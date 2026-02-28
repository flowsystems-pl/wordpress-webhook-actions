<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Repositories\LogRepository;

class LogService {
  private LogRepository $repository;

  public function __construct() {
    $this->repository = new LogRepository();
  }

  /**
   * Log a pending webhook request
   *
   * @param int $webhookId
   * @param string $triggerName
   * @param array $payload The transformed payload (sent to webhook)
   * @param array|null $originalPayload The original payload before transformation (null if no mapping)
   * @param bool $mappingApplied Whether field mapping was applied
   * @param string|null $eventUuid UUID shared across all webhooks for this trigger event
   * @param string|null $eventTimestamp ISO 8601 UTC timestamp of the event
   * @return int|false Log ID or false on failure
   */
  public function logPending(
    int $webhookId,
    string $triggerName,
    array $payload,
    ?array $originalPayload = null,
    bool $mappingApplied = false,
    ?string $eventUuid = null,
    ?string $eventTimestamp = null
  ) {
    return $this->repository->create([
      'webhook_id' => $webhookId,
      'trigger_name' => $triggerName,
      'status' => 'pending',
      'request_payload' => $payload,
      'original_payload' => $originalPayload,
      'mapping_applied' => $mappingApplied,
      'event_uuid' => $eventUuid,
      'event_timestamp' => $eventTimestamp,
    ]);
  }

  /**
   * Log a successful webhook request
   *
   * @param int $webhookId
   * @param string $triggerName
   * @param array $payload
   * @param int $httpCode
   * @param string $responseBody
   * @param int $durationMs
   * @return int|false Log ID or false on failure
   */
  public function logSuccess(
    int $webhookId,
    string $triggerName,
    array $payload,
    int $httpCode,
    string $responseBody,
    int $durationMs
  ) {
    return $this->repository->create([
      'webhook_id' => $webhookId,
      'trigger_name' => $triggerName,
      'status' => 'success',
      'http_code' => $httpCode,
      'request_payload' => $payload,
      'response_body' => $responseBody,
      'duration_ms' => $durationMs,
    ]);
  }

  /**
   * Log an error webhook request
   *
   * @param int $webhookId
   * @param string $triggerName
   * @param array $payload
   * @param int|null $httpCode
   * @param string $errorMessage
   * @param string|null $responseBody
   * @param int|null $durationMs
   * @return int|false Log ID or false on failure
   */
  public function logError(
    int $webhookId,
    string $triggerName,
    array $payload,
    ?int $httpCode,
    string $errorMessage,
    ?string $responseBody = null,
    ?int $durationMs = null
  ) {
    return $this->repository->create([
      'webhook_id' => $webhookId,
      'trigger_name' => $triggerName,
      'status' => 'error',
      'http_code' => $httpCode,
      'request_payload' => $payload,
      'response_body' => $responseBody,
      'error_message' => $errorMessage,
      'duration_ms' => $durationMs,
    ]);
  }

  /**
   * Log a retry webhook request
   *
   * @param int $webhookId
   * @param string $triggerName
   * @param array $payload
   * @param string $errorMessage
   * @return int|false Log ID or false on failure
   */
  public function logRetry(
    int $webhookId,
    string $triggerName,
    array $payload,
    string $errorMessage
  ) {
    return $this->repository->create([
      'webhook_id' => $webhookId,
      'trigger_name' => $triggerName,
      'status' => 'retry',
      'request_payload' => $payload,
      'error_message' => $errorMessage,
    ]);
  }

  /**
   * Update an existing log entry
   *
   * @param int $logId
   * @param array $data
   * @return bool
   */
  public function updateLog(int $logId, array $data): bool {
    return $this->repository->update($logId, $data);
  }

  /**
   * Append an attempt entry to the log's attempt history
   *
   * Caps history at fswa_max_attempts entries to prevent unbounded growth.
   *
   * @param int $logId
   * @param array $attemptData
   * @return bool
   */
  public function appendAttemptHistory(int $logId, array $attemptData): bool {
    $log = $this->repository->find($logId);
    if (!$log) {
      return false;
    }

    $history = is_array($log['attempt_history']) ? $log['attempt_history'] : [];
    $history[] = $attemptData;

    $maxAttempts = (int) apply_filters('fswa_max_attempts', 5);
    if (count($history) > $maxAttempts) {
      $history = array_slice($history, -$maxAttempts);
    }

    return $this->repository->update($logId, ['attempt_history' => $history]);
  }

  /**
   * Get the repository instance
   *
   * @return LogRepository
   */
  public function getRepository(): LogRepository {
    return $this->repository;
  }
}
