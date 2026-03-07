<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Repositories\LogRepository;
use FlowSystems\WebhookActions\Repositories\StatsRepository;

class LogService {
  private LogRepository   $repository;
  private StatsRepository $statsRepository;

  public function __construct() {
    $this->repository      = new LogRepository();
    $this->statsRepository = new StatsRepository();
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
    $logId = $this->repository->create([
      'webhook_id'      => $webhookId,
      'trigger_name'    => $triggerName,
      'status'          => 'success',
      'http_code'       => $httpCode,
      'request_payload' => $payload,
      'response_body'   => $responseBody,
      'duration_ms'     => $durationMs,
      'stats_recorded'  => 1,
    ]);

    if ($logId) {
      $this->statsRepository->record(
        gmdate('Y-m-d'),
        $webhookId,
        $triggerName,
        'success',
        $durationMs,
        $httpCode
      );
    }

    return $logId;
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
    $result = $this->repository->update($logId, $data);

    // Record terminal outcome in persistent stats the first time only.
    // stats_recorded=1 gates replays and duplicate updates.
    if ($result && isset($data['status']) && in_array($data['status'], ['success', 'permanently_failed'], true)) {
      $log = $this->repository->find($logId);
      if ($log && !(bool) ($log['stats_recorded'] ?? false)) {
        $this->statsRepository->record(
          gmdate('Y-m-d', strtotime($log['created_at'])),
          (int) $log['webhook_id'],
          (string) $log['trigger_name'],
          $data['status'],
          isset($data['duration_ms']) ? (int) $data['duration_ms'] : null,
          isset($data['http_code'])   ? (int) $data['http_code']   : null
        );
        $this->repository->update($logId, ['stats_recorded' => 1]);
      }
    }

    return $result;
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
    $attemptData['attempt'] = empty($history) ? 0 : max(array_column($history, 'attempt')) + 1;
    $history[] = $attemptData;

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
