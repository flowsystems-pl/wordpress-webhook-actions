<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Repositories\WebhookRepository;
use WP_Error;

class Dispatcher {
  private WPHttpTransport $transport;
  private LogService $logService;
  private QueueService $queueService;
  private WebhookRepository $webhookRepository;
  private PayloadTransformer $payloadTransformer;

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

    if (empty($webhooks)) {
      return;
    }

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
        'hook' => $trigger,
        'args' => $this->normalizeArgs($args),
        'timestamp' => time(),
        'site' => [
          'url' => home_url(),
        ],
      ],
      $trigger,
      $args
    );

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

      // Log pending status first to get log_id
      $logId = $this->logService->logPending(
        $webhookId,
        $trigger,
        $transformedPayload,
        $originalPayload,
        $mappingApplied
      );

      // Enqueue to database queue with log_id for later update
      $this->queueService->enqueue($webhookId, $trigger, [
        'webhook' => $webhook,
        'payload' => $transformedPayload,
        'log_id' => $logId,
      ]);
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
      'processed' => 0,
      'succeeded' => 0,
      'failed' => 0,
      'rescheduled' => 0,
      'stale_cleaned' => 0,
    ];

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

      // Process the job
      $success = $this->processJob($job);

      if ($success) {
        $this->queueService->markCompleted($jobId);
        $result['succeeded']++;
      } else {
        // Try to reschedule with backoff
        if ($this->queueService->rescheduleWithBackoff($jobId)) {
          $result['rescheduled']++;
        } else {
          // Max attempts reached, already marked as failed
          $result['failed']++;
        }
      }
    }

    return $result;
  }

  /**
   * Process a single job from the queue
   *
   * @param array $job Job data from queue
   * @return bool True if successful
   */
  private function processJob(array $job): bool {
    $jobData = json_decode($job['payload'], true);

    if (!$jobData || !isset($jobData['webhook']) || !isset($jobData['payload'])) {
      return false;
    }

    $webhook = $jobData['webhook'];
    $payload = $jobData['payload'];
    $trigger = $job['trigger_name'];
    $logId = !empty($jobData['log_id']) ? (int) $jobData['log_id'] : null;

    return $this->sendToWebhook($webhook, $payload, $trigger, $logId);
  }

  /**
   * Send a single webhook to an endpoint
   *
   * @param array<string, mixed> $webhook Webhook configuration array
   * @param array<string, mixed> $payload Payload data to send
   * @param string $trigger The trigger event name
   * @param int|null $logId Existing log ID to update (null to create new)
   * @return bool True if successful
   */
  public function sendToWebhook(
    array $webhook,
    array $payload,
    string $trigger,
    ?int $logId = null
  ): bool {
    if (empty($webhook['endpoint_url']) || !is_string($webhook['endpoint_url'])) {
      return false;
    }

    $webhookId = isset($webhook['id']) ? (int) $webhook['id'] : 0;
    $url = (string) $webhook['endpoint_url'];
    $authHeader = isset($webhook['auth_header']) && is_string($webhook['auth_header'])
      ? (string) $webhook['auth_header']
      : '';

    if (!$this->isValidUrl($url)) {
      $this->logError($trigger, $url, 'Invalid URL format', $webhookId, $payload, null, null, null, $logId);
      return false;
    }

    $headers = [
      'Content-Type' => 'application/json',
    ];

    if (!empty($authHeader)) {
      $headers['Authorization'] = $authHeader;
    }

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
      $this->handleError($result, $trigger, $url, $webhookId, $payload, $durationMs, $logId);
      return $this->isTransientError($result) ? false : false; // All errors mean job failed
    }

    if (!$this->isSuccessResponse($result)) {
      $responseCode = wp_remote_retrieve_response_code($result);
      $responseBody = wp_remote_retrieve_body($result);
      $errorMessage = sprintf("HTTP %d: %s", (int) $responseCode, (string) $responseBody);

      $this->logError($trigger, $url, $errorMessage, $webhookId, $payload, (int) $responseCode, $responseBody, $durationMs, $logId);
      return false;
    }

    $this->logSuccess($trigger, $url, $payload, $result, $webhookId, $durationMs, $logId);
    return true;
  }

  /**
   * Normalize arguments for payload serialization
   *
   * @param array<string, mixed> $args Arguments to normalize
   * @return array<string, mixed> Normalized arguments
   */
  private function normalizeArgs(array $args): array {
    return array_map(function ($arg) {
      if (is_scalar($arg) || $arg === null) {
        return $arg;
      }

      if (is_array($arg)) {
        return $arg;
      }

      if (is_object($arg)) {
        return [
          '__type' => get_class($arg),
          'id' => $arg->ID ?? null,
        ];
      }

      return null;
    }, $args);
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
   * Check if HTTP response indicates success (2xx status code)
   *
   * @param array<string, mixed>|WP_Error $response WordPress HTTP response array
   * @return bool True if response is successful
   */
  private function isSuccessResponse($response): bool {
    $code = wp_remote_retrieve_response_code($response);
    return (int) $code >= 200 && (int) $code < 300;
  }

  /**
   * Handle HTTP errors
   *
   * @param WP_Error $error The WP_Error object
   * @param string $trigger The trigger event name
   * @param string $url The webhook URL
   * @param int $webhookId Webhook ID
   * @param array<string, mixed> $payload Payload data
   * @param int $durationMs Request duration in milliseconds
   * @param int|null $logId Existing log ID to update
   * @return void
   */
  private function handleError(
    WP_Error $error,
    string $trigger,
    string $url,
    int $webhookId,
    array $payload,
    int $durationMs = 0,
    ?int $logId = null
  ): void {
    $errorMessage = $error->get_error_message();
    $this->logError($trigger, $url, (string) $errorMessage, $webhookId, $payload, null, null, $durationMs, $logId);
  }

  /**
   * Determine if an error is transient and should be retried
   *
   * @param WP_Error $error The WP_Error object
   * @return bool True if error is transient
   */
  private function isTransientError(WP_Error $error): bool {
    $transientCodes = [
      'http_request_failed',
      'http_request_timeout'
    ];

    return count(array_intersect(
      $error->get_error_codes(),
      $transientCodes
    )) > 0;
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
    ?int $logId = null
  ): void {
    if ($webhookId > 0) {
      if ($logId !== null) {
        $this->logService->updateLog($logId, [
          'status' => 'error',
          'http_code' => $httpCode,
          'response_body' => $responseBody,
          'error_message' => $error,
          'duration_ms' => $durationMs,
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

    /**
     * Fires after a webhook delivery fails.
     *
     * @param string $trigger The trigger event name
     * @param string $url     The webhook endpoint URL
     * @param string $error   The error message
     */
    do_action('fswa_error', $trigger, $url, $error);
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
    ?int $logId = null
  ): void {
    $responseCode = wp_remote_retrieve_response_code($response);
    $responseBody = wp_remote_retrieve_body($response);

    if ($webhookId > 0) {
      if ($logId !== null) {
        $this->logService->updateLog($logId, [
          'status' => 'success',
          'http_code' => (int) $responseCode,
          'response_body' => (string) $responseBody,
          'duration_ms' => $durationMs,
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

    /**
     * Fires after a successful webhook delivery.
     *
     * @param string $trigger  The trigger event name
     * @param string $url      The webhook endpoint URL
     * @param array  $payload  The payload data that was sent
     * @param array  $response The HTTP response from wp_remote_post
     */
    do_action('fswa_success', $trigger, $url, $payload, $response);
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
