<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Repositories\WebhookRepository;
use FlowSystems\WebhookActions\Services\ConditionEvaluator;
use WP_Error;

class Dispatcher {
  private WPHttpTransport $transport;
  private LogService $logService;
  private QueueService $queueService;
  private WebhookRepository $webhookRepository;
  private PayloadTransformer $payloadTransformer;
  private ConditionEvaluator $conditionEvaluator;

  /**
   * Constructor
   *
   * @param WPHttpTransport $transport HTTP transport instance
   * @param QueueService $queueService Queue service instance
   */
  public function __construct(WPHttpTransport $transport, QueueService $queueService) {
    $this->transport = $transport;
    $this->queueService = $queueService;
    $this->logService = new LogService();
    $this->webhookRepository = new WebhookRepository();
    $this->payloadTransformer = new PayloadTransformer();
    $this->conditionEvaluator = new ConditionEvaluator();
  }

  /**
   * Dispatch webhooks for a specific trigger - enqueues jobs to database queue
   *
   * @param string $trigger The trigger event name
   * @param array<string, mixed> $args Additional arguments for payload
   * @return void
   */
  public function dispatch(string $trigger, array $args = []): void {
    /**
     * Filter to decide if a trigger should be dispatched.
     * Useful for resolving conflicts with themes or plugins.
     *
     * @param bool   $should_dispatch Whether to dispatch the webhook (default true)
     * @param string $trigger         The trigger event name
     * @param array  $args            Arguments passed to the trigger
     */
    $shouldDispatch = apply_filters('fswa_should_dispatch', true, $trigger, $args);

    if (!$shouldDispatch) {
      return;
    }

    $webhooks = $this->webhookRepository->getByTrigger($trigger);
    $disabledWebhooks = $this->webhookRepository->getDisabledByTrigger($trigger);

    if (empty($webhooks) && empty($disabledWebhooks)) {
      return;
    }

    // Generate event identity once per trigger dispatch, shared across all webhooks
    $eventUuid = wp_generate_uuid4();
    $eventTimestamp = gmdate('Y-m-d\TH:i:s\Z');

    /**
     * Filter the webhook payload before dispatching.
     *
     * @param array  $payload The default payload data
     * @param string $trigger The trigger event name
     * @param array  $args    Original arguments passed to the trigger
     */
    $payload = apply_filters(
      'fswa_payload',
      [
        'event' => [
          'id'        => $eventUuid,
          'timestamp' => $eventTimestamp,
          'version'   => '1.0',
        ],
        'hook'      => $trigger,
        'args'      => $this->normalizeArgs($args),
        'timestamp' => time(),
        'site'      => [
          'url' => home_url(),
        ],
      ],
      $trigger,
      $args
    );

    if (empty($webhooks)) {
      // Capture example payloads for disabled webhooks so field mapping
      // can be configured without having to temporarily re-enable the webhook.
      foreach ($disabledWebhooks as $webhook) {
        $webhookId = (int) ($webhook['id'] ?? 0);
        if ($webhookId === 0) {
          continue;
        }
        $this->payloadTransformer->transform($webhookId, $trigger, $payload, $args);
      }
      return;
    }

    // Track enqueued webhooks to prevent duplicates within same request
    static $enqueuedWebhooks = [];

    foreach ($webhooks as $webhook) {
      $webhookId = (int) ($webhook['id'] ?? 0);
      if ($webhookId === 0) {
        continue;
      }

      // Deduplication key
      $enqueueKey = md5($trigger . serialize($webhook) . serialize($payload));
      if (isset($enqueuedWebhooks[$enqueueKey])) {
        continue;
      }
      $enqueuedWebhooks[$enqueueKey] = true;

      // Transform payload based on schema configuration
      $transformResult = $this->payloadTransformer->transform($webhookId, $trigger, $payload, $args);
      $transformedPayload = $transformResult['transformed'];
      $originalPayload = $transformResult['original'];
      $mappingApplied = $transformResult['mapping_applied'];

      // Evaluate conditions (per-trigger schema) against the transformed payload
      $conditions = is_array($transformResult['conditions'] ?? null) ? $transformResult['conditions'] : [];
      if (!empty($conditions)) {
        $evalResult = $this->conditionEvaluator->evaluate($conditions, $payload);
        if (!$evalResult['passed']) {
          $this->logService->logSkipped(
            $webhookId,
            $trigger,
            $transformedPayload,
            $originalPayload,
            $mappingApplied,
            $this->buildSkipMessage($evalResult['failed_rule'], $payload),
            $eventUuid,
            $eventTimestamp
          );
          do_action('fswa_skipped', $trigger, $webhookId, $evalResult['failed_rule']);
          continue;
        }
      }

      // Log pending status first to get log_id
      $logId = $this->logService->logPending(
        $webhookId,
        $trigger,
        $transformedPayload,
        $originalPayload,
        $mappingApplied,
        $eventUuid,
        $eventTimestamp
      );

      $this->queueService->enqueue(
        $webhookId,
        $trigger,
        [
          'webhook'          => $webhook,
          'payload'          => $transformedPayload,
          'log_id'           => $logId,
          'mapping_applied'  => $mappingApplied,
          'original_payload' => $originalPayload,
        ],
        null,
        $logId ?: null
      );
    }

    // Capture example payloads for disabled webhooks so mapping and conditions
    // can be configured without needing to re-enable the webhook first.
    foreach ($disabledWebhooks as $webhook) {
      $webhookId = (int) ($webhook['id'] ?? 0);
      if ($webhookId === 0) {
        continue;
      }
      $this->payloadTransformer->transform($webhookId, $trigger, $payload, $args);
    }
  }

  /**
   * Process a batch of queued jobs
   *
   * @param int $batchSize Number of jobs to process
   * @return array{processed: int, succeeded: int, failed: int, rescheduled: int, stale_cleaned: int}
   */
  public function process(int $batchSize = 10): array {
    $result = [
      'processed'     => 0,
      'succeeded'     => 0,
      'failed'        => 0,
      'rescheduled'   => 0,
      'stale_cleaned' => 0,
    ];

    // Record that the queue processor has run (used by health observability)
    update_option('fswa_last_cron_run', time(), false);

    // Step 1: Cleanup stale locks
    $result['stale_cleaned'] = $this->queueService->cleanupStaleJobs(5);

    // Step 2: Get batch of pending jobs
    $jobs = $this->queueService->getNextBatch($batchSize);

    if (empty($jobs)) {
      return $result;
    }

    // Step 3: Process each job
    $lockId = wp_generate_uuid4();

    foreach ($jobs as $job) {
      $jobId = (int) $job['id'];

      // Try to lock the job
      if (!$this->queueService->lockJob($jobId, $lockId)) {
        // Job was taken by another process
        continue;
      }

      $result['processed']++;

      $resultData = $this->processJob($job);

      if ($resultData['success']) {
        $this->queueService->markCompleted($jobId);
        $result['succeeded']++;
      } elseif ($resultData['shouldRetry']) {
        $rescheduleResult = $this->queueService->rescheduleWithBackoff($jobId);
        if ($rescheduleResult['rescheduled']) {
          $logId = $this->extractLogIdFromJob($job);
          if ($logId) {
            $this->logService->updateLog($logId, [
              'status'         => 'retry',
              'next_attempt_at' => $rescheduleResult['scheduled_at'],
            ]);
          }
          $result['rescheduled']++;
        } else {
          // Max attempts reached
          $this->queueService->markPermanentlyFailed($jobId);
          $logId = $this->extractLogIdFromJob($job);
          if ($logId) {
            $this->logService->updateLog($logId, [
              'status'         => 'permanently_failed',
              'next_attempt_at' => null,
            ]);
          }
          $result['failed']++;
        }
      } else {
        // Non-retryable failure (4xx, 3xx, config error)
        $this->queueService->markPermanentlyFailed($jobId);
        $logId = $this->extractLogIdFromJob($job);
        if ($logId) {
          $this->logService->updateLog($logId, [
            'status'         => 'permanently_failed',
            'next_attempt_at' => null,
          ]);
        }
        $result['failed']++;
      }
    }

    return $result;
  }

  /**
   * Process a single job from the queue
   *
   * @param array $job Job data from queue
   * @return array{success: bool, shouldRetry: bool}
   */
  private function processJob(array $job): array {
    $jobData = json_decode($job['payload'], true);

    if (!$jobData || !isset($jobData['webhook']) || !isset($jobData['payload'])) {
      return ['success' => false, 'shouldRetry' => false];
    }

    $webhook = $jobData['webhook'];
    $payload = $jobData['payload'];
    $trigger = $job['trigger_name'];
    $logId = $this->extractLogIdFromJob($job);
    $mappingApplied = (bool) ($jobData['mapping_applied'] ?? false);
    $originalPayload = $jobData['original_payload'] ?? null;

    if ($logId === null) {
      $webhookId = isset($webhook['id']) ? (int) $webhook['id'] : 0;
      if ($webhookId > 0) {
        $recoveredId = $this->logService->logPending(
          $webhookId,
          $trigger,
          $payload,
          $originalPayload ?: null,
          $mappingApplied
        );
        if ($recoveredId) {
          $logId = $recoveredId;
        }
      }
    }

    $attemptNumber = (int) ($job['attempts'] ?? 0);
    $isTest        = (bool) ($job['is_test'] ?? false);

    return $this->sendToWebhook($webhook, $payload, $trigger, $logId, $attemptNumber, $isTest);
  }

  /**
   * Extract log ID from a queue job (column-first, payload fallback)
   *
   * @param array $job
   * @return int|null
   */
  private function extractLogIdFromJob(array $job): ?int {
    if (!empty($job['log_id'])) {
      return (int) $job['log_id'];
    }

    $jobData = json_decode($job['payload'], true);
    $logId = $jobData['log_id'] ?? null;

    return $logId ? (int) $logId : null;
  }

  /**
   * Send a single webhook to an endpoint
   *
   * @param array<string, mixed> $webhook Webhook configuration array
   * @param array<string, mixed> $payload Payload data to send
   * @param string $trigger The trigger event name
   * @param int|null $logId Existing log ID to update (null to create new)
   * @param int $attemptNumber Current attempt number (0-indexed)
   * @return array{success: bool, shouldRetry: bool}
   */
  public function sendToWebhook(
    array $webhook,
    array $payload,
    string $trigger,
    ?int $logId = null,
    int $attemptNumber = 0,
    bool $isTest = false
  ): array {
    if (empty($webhook['endpoint_url']) || !is_string($webhook['endpoint_url'])) {
      return ['success' => false, 'shouldRetry' => false];
    }

    $webhookId = isset($webhook['id']) ? (int) $webhook['id'] : 0;
    $url = (string) $webhook['endpoint_url'];
    $authHeader = isset($webhook['auth_header']) && is_string($webhook['auth_header'])
      ? (string) $webhook['auth_header']
      : '';

    if (!$this->isValidUrl($url)) {
      $this->logError($trigger, $url, 'Invalid URL format', $webhookId, $payload, null, null, null, $logId);
      return ['success' => false, 'shouldRetry' => false];
    }

    $headers = [
      'Content-Type' => 'application/json',
    ];

    if (!empty($authHeader)) {
      $headers['Authorization'] = $authHeader;
    }

    // Add event identity headers before fswa_headers filter
    $headers['X-Event-Id']        = $payload['event']['id'] ?? '';
    $headers['X-Event-Timestamp'] = $payload['event']['timestamp'] ?? '';
    $headers['X-Webhook-Id']      = $webhook['webhook_uuid'] ?? '';

    /**
     * Filter the HTTP headers sent with the webhook request.
     *
     * @param array<string, string> $headers HTTP headers to send
     * @param array                 $webhook The webhook configuration
     * @param string                $trigger The trigger event name
     */
    $headers = apply_filters('fswa_headers', $headers, $webhook, $trigger);

    $startTime = microtime(true);
    $result = $this->transport->send($url, $payload, $headers);
    $durationMs = (int) ((microtime(true) - $startTime) * 1000);

    if (is_wp_error($result)) {
      $errorMessage = $result->get_error_message();
      $this->logError($trigger, $url, (string) $errorMessage, $webhookId, $payload, null, null, $durationMs, $logId, $isTest);

      if ($logId !== null) {
        $this->logService->appendAttemptHistory($logId, [
          'attempt'       => $attemptNumber,
          'attempted_at'  => gmdate('Y-m-d\TH:i:s\Z'),
          'http_code'     => null,
          'status'        => 'error',
          'error_message' => (string) $errorMessage,
          'response_body' => null,
          'duration_ms'   => $durationMs,
          'should_retry'  => true,
        ]);
      }

      /**
       * Fires after a webhook delivery fails.
       *
       * @param string $trigger The trigger event name
       * @param string $url     The webhook endpoint URL
       * @param string $error   The error message
       */
      do_action('fswa_error', $trigger, $url, (string) $errorMessage);

      return ['success' => false, 'shouldRetry' => !$isTest];
    }

    $responseCode = (int) wp_remote_retrieve_response_code($result);
    $responseBody = wp_remote_retrieve_body($result);

    $success = $responseCode >= 200 && $responseCode < 300;
    $shouldRetry = !$isTest && !$success && ($responseCode >= 500 || $responseCode === 429);

    if ($success) {
      $this->logSuccess($trigger, $url, $payload, $result, $webhookId, $durationMs, $logId, $isTest);
    } else {
      $errorMessage = sprintf("HTTP %d: %s", $responseCode, (string) $responseBody);
      $this->logError($trigger, $url, $errorMessage, $webhookId, $payload, $responseCode, $responseBody, $durationMs, $logId, $isTest);
    }

    if ($logId !== null) {
      $parsedBody = json_decode($responseBody, true);
      $this->logService->appendAttemptHistory($logId, [
        'attempt'       => $attemptNumber,
        'attempted_at'  => gmdate('Y-m-d\TH:i:s\Z'),
        'http_code'     => $responseCode,
        'status'        => $success ? 'success' : 'error',
        'error_message' => $success ? null : sprintf("HTTP %d", $responseCode),
        'response_body' => $parsedBody !== null ? $parsedBody : ($responseBody !== '' ? $responseBody : null),
        'duration_ms'   => $durationMs,
        'should_retry'  => $shouldRetry,
      ]);
    }

    if ($success) {
      /**
       * Fires after a successful webhook delivery.
       *
       * @param string $trigger  The trigger event name
       * @param string $url      The webhook endpoint URL
       * @param array  $payload  The payload data that was sent
       * @param array  $response The HTTP response from wp_remote_post
       */
      do_action('fswa_success', $trigger, $url, $payload, $result);
    } else {
      /**
       * Fires after a webhook delivery fails.
       *
       * @param string $trigger The trigger event name
       * @param string $url     The webhook endpoint URL
       * @param string $error   The error message
       */
      do_action('fswa_error', $trigger, $url, sprintf("HTTP %d", $responseCode));
    }

    return ['success' => $success, 'shouldRetry' => $shouldRetry];
  }

  /**
   * Build a human-readable skip message from the failed condition rule.
   *
   * @param array|null $rule
   * @return string
   */
  private function buildSkipMessage(?array $rule, array $payload = []): string {
    if ($rule === null) {
      return 'Conditions not met.';
    }

    if (isset($rule['type']) && $rule['type'] === 'group') {
      $match = isset($rule['match']) && $rule['match'] === 'or' ? 'ANY' : 'ALL';
      $parts = [];
      foreach ($rule['rules'] ?? [] as $subRule) {
        $actual    = $this->resolveFieldForMessage($subRule['field'] ?? '', $payload);
        $ruleMsg   = $this->formatRuleMessage($subRule, $actual);
        $passed    = $this->evaluateSubRule($subRule, $payload);
        $parts[]   = $ruleMsg . ($passed ? ' ✓' : ' ✗');
      }
      if (!empty($parts)) {
        return sprintf('Condition not met: group (%s) — %s', $match, implode('; ', $parts));
      }
      $count = count($rule['rules'] ?? []);
      return sprintf('Condition not met: group (%s of %d rule%s)', $match, $count, $count !== 1 ? 's' : '');
    }

    $actual = $this->resolveFieldForMessage($rule['field'] ?? '', $payload);
    return 'Condition not met: ' . $this->formatRuleMessage($rule, $actual);
  }

  private function formatRuleMessage(array $rule, mixed $actual): string {
    $op    = $rule['operator'] ?? 'unknown';
    $field = $rule['field'] ?? 'unknown';
    $val   = $rule['value'] ?? '';

    $valueHidden = in_array($op, ['is_empty', 'is_not_empty', 'is_true', 'is_false'], true);

    $base = $valueHidden
      ? sprintf('%s %s', $field, $op)
      : sprintf('%s %s "%s"', $field, $op, $val);

    if ($actual !== null) {
      $actualStr = is_array($actual) ? json_encode($actual) : (string) $actual;
      return sprintf('%s (actual: "%s")', $base, $actualStr);
    }

    return $base;
  }

  private function evaluateSubRule(array $rule, array $payload): bool {
    $result = $this->conditionEvaluator->evaluate([
      'enabled' => true,
      'type'    => 'and',
      'rules'   => [$rule],
    ], $payload);
    return $result['passed'];
  }

  private function resolveFieldForMessage(string $field, array $payload): mixed {
    if (empty($field) || empty($payload)) {
      return null;
    }
    $segments = explode('.', $field);
    $current  = $payload;
    foreach ($segments as $segment) {
      if (!is_array($current) || !array_key_exists($segment, $current)) {
        return null;
      }
      $current = $current[$segment];
    }
    return $current;
  }

  /**
   * Normalize arguments for payload serialization
   *
   * @param array<string, mixed> $args Arguments to normalize
   * @return array<string, mixed> Normalized arguments
   */
  private function normalizeArgs(array $args): array {
    return array_map([$this, 'normalizeValue'], $args);
  }

  /**
   * Recursively normalize a single value for payload serialization
   *
   * @param mixed $value Value to normalize
   * @return mixed Normalized value
   */
  private function normalizeValue(mixed $value): mixed {
    if (is_scalar($value) || $value === null) {
      return $value;
    }

    if (is_array($value)) {
      return array_map([$this, 'normalizeValue'], $value);
    }

    if (is_object($value)) {
      if ($value instanceof \Closure) {
        return null;
      }

      if ($value instanceof \DateTimeInterface) {
        return $value->format(\DateTime::ATOM);
      }

      if ($value instanceof \Traversable) {
        return array_map([$this, 'normalizeValue'], iterator_to_array($value, false));
      }

      // Allow third-party code to provide custom extraction for any object type.
      $custom = apply_filters('fswa_normalize_object', null, $value);
      if (is_array($custom)) {
        return array_merge(['__type' => get_class($value)], array_map([$this, 'normalizeValue'], $custom));
      }

      if (method_exists($value, 'get_data')) {
        $data = $value->get_data();
      } elseif ($value instanceof \JsonSerializable) {
        $data = $value->jsonSerialize();
      } elseif (method_exists($value, 'get_properties')) {
        $data = $value->get_properties();
      } else {
        $data = get_object_vars($value);
      }

      $data = is_array($data) ? array_map([$this, 'normalizeValue'], $data) : ['value' => (string) $value];

      return array_merge(['__type' => get_class($value)], $data);
    }

    return null;
  }

  /**
   * Validate if a URL is properly formatted and secure
   *
   * @param string $url The URL to validate
   * @return bool True if valid, false otherwise
   */
  private function isValidUrl(string $url): bool {
    if (!wp_http_validate_url($url)) {
      return false;
    }

    /**
     * Filter whether HTTPS is required for webhook URLs.
     *
     * @param bool $require_https Whether to require HTTPS (default true)
     */
    $requireHttps = apply_filters('fswa_require_https', true);
    if ($requireHttps && !str_starts_with($url, 'https://')) {
      return false;
    }

    return true;
  }

  /**
   * Log webhook errors
   *
   * @param string $trigger The trigger event name
   * @param string $url The webhook URL
   * @param string $error The error message
   * @param int $webhookId The webhook ID
   * @param array $payload The request payload
   * @param int|null $httpCode HTTP response code
   * @param string|null $responseBody Response body
   * @param int|null $durationMs Request duration
   * @param int|null $logId Existing log ID to update
   * @return void
   */
  private function logError(
    string $trigger,
    string $url,
    string $error,
    int $webhookId = 0,
    array $payload = [],
    ?int $httpCode = null,
    ?string $responseBody = null,
    ?int $durationMs = null,
    ?int $logId = null,
    bool $isTest = false
  ): void {
    $status = $isTest ? 'test' : 'error';
    if ($webhookId > 0) {
      if ($logId !== null) {
        $this->logService->updateLog($logId, [
          'status'        => $status,
          'http_code'     => $httpCode,
          'response_body' => $responseBody,
          'error_message' => $error,
          'duration_ms'   => $durationMs,
        ]);
      } else {
        $this->logService->logError(
          $webhookId,
          $trigger,
          $payload,
          $httpCode,
          $error,
          $responseBody,
          $durationMs
        );
      }
    }
  }

  /**
   * Log successful webhook dispatches
   *
   * @param string $trigger The trigger event name
   * @param string $url The webhook URL
   * @param array<string, mixed> $payload The payload sent
   * @param array<string, mixed>|WP_Error $response The HTTP response
   * @param int $webhookId The webhook ID
   * @param int $durationMs Request duration
   * @param int|null $logId Existing log ID to update
   * @return void
   */
  private function logSuccess(
    string $trigger,
    string $url,
    array $payload,
    $response,
    int $webhookId = 0,
    int $durationMs = 0,
    ?int $logId = null,
    bool $isTest = false
  ): void {
    $responseCode = wp_remote_retrieve_response_code($response);
    $responseBody = wp_remote_retrieve_body($response);
    $status       = $isTest ? 'test' : 'success';

    if ($webhookId > 0) {
      if ($logId !== null) {
        $this->logService->updateLog($logId, [
          'status'        => $status,
          'http_code'     => (int) $responseCode,
          'response_body' => (string) $responseBody,
          'duration_ms'   => $durationMs,
        ]);
      } else {
        $this->logService->logSuccess(
          $webhookId,
          $trigger,
          $payload,
          (int) $responseCode,
          (string) $responseBody,
          $durationMs
        );
      }
    }
  }

  /**
   * Get the queue service instance
   *
   * @return QueueService
   */
  public function getQueueService(): QueueService {
    return $this->queueService;
  }

  /**
   * Get the webhook repository instance
   *
   * @return WebhookRepository
   */
  public function getWebhooksRepository(): WebhookRepository {
    return $this->webhookRepository;
  }
}
