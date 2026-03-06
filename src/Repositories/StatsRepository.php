<?php

namespace FlowSystems\WebhookActions\Repositories;

defined('ABSPATH') || exit;

class StatsRepository {
  private string $table;

  private const ALLOWED_STATUSES = ['success', 'permanently_failed'];

  public function __construct() {
    global $wpdb;
    $this->table = $wpdb->prefix . 'fswa_stats';
  }

  /**
   * Record a terminal delivery outcome into the persistent stats table.
   * Uses INSERT ... ON DUPLICATE KEY UPDATE for atomic increments.
   *
   * @param string   $date        Y-m-d (log creation date)
   * @param int      $webhookId
   * @param string   $triggerName
   * @param string   $status      'success' or 'permanently_failed'
   * @param int|null $durationMs  Request duration (recorded only for success)
   * @param int|null $httpCode    HTTP response code
   */
  public function record(
    string $date,
    int $webhookId,
    string $triggerName,
    string $status,
    ?int $durationMs = null,
    ?int $httpCode = null
  ): void {
    if (!in_array($status, self::ALLOWED_STATUSES, true)) {
      return;
    }

    global $wpdb;

    $isSuccess        = (int) ($status === 'success');
    $isPermFailed     = (int) ($status === 'permanently_failed');
    $durValue         = ($isSuccess && $durationMs !== null) ? $durationMs : 0;
    $durCount         = ($isSuccess && $durationMs !== null) ? 1 : 0;

    [$http2xx, $http4xx, $http5xx] = $this->bucketHttpCode($httpCode);

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $wpdb->query(
      $wpdb->prepare(
        "INSERT INTO {$this->table}
          (`date`, `webhook_id`, `trigger_name`, `success`, `permanently_failed`,
           `sum_duration_ms`, `count_with_duration`, `http_2xx`, `http_4xx`, `http_5xx`)
         VALUES (%s, %d, %s, %d, %d, %d, %d, %d, %d, %d)
         ON DUPLICATE KEY UPDATE
           `success`              = `success`              + VALUES(`success`),
           `permanently_failed`   = `permanently_failed`   + VALUES(`permanently_failed`),
           `sum_duration_ms`      = `sum_duration_ms`      + VALUES(`sum_duration_ms`),
           `count_with_duration`  = `count_with_duration`  + VALUES(`count_with_duration`),
           `http_2xx`             = `http_2xx`             + VALUES(`http_2xx`),
           `http_4xx`             = `http_4xx`             + VALUES(`http_4xx`),
           `http_5xx`             = `http_5xx`             + VALUES(`http_5xx`)",
        $date, $webhookId, $triggerName,
        $isSuccess, $isPermFailed,
        $durValue, $durCount,
        $http2xx, $http4xx, $http5xx
      )
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
  }

  /**
   * Get aggregated stats for a time window, optionally filtered by webhook and trigger.
   *
   * @param int|null    $webhookId
   * @param int         $days
   * @param string|null $triggerName
   * @return array{success: int, permanently_failed: int, avg_duration_ms: int, http_2xx: int, http_4xx: int, http_5xx: int}
   */
  public function getPeriodStats(?int $webhookId, int $days, ?string $triggerName = null): array {
    global $wpdb;

    $dateFrom = gmdate('Y-m-d', strtotime("-{$days} days"));
    $where    = ['`date` >= %s'];
    $values   = [$dateFrom];

    if ($webhookId !== null) {
      $where[]  = '`webhook_id` = %d';
      $values[] = $webhookId;
    }

    if ($triggerName !== null) {
      $where[]  = '`trigger_name` = %s';
      $values[] = $triggerName;
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $row = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT
           COALESCE(SUM(`success`), 0)            AS success,
           COALESCE(SUM(`permanently_failed`), 0) AS permanently_failed,
           COALESCE(SUM(`sum_duration_ms`), 0)    AS sum_duration_ms,
           COALESCE(SUM(`count_with_duration`), 0) AS count_with_duration,
           COALESCE(SUM(`http_2xx`), 0)            AS http_2xx,
           COALESCE(SUM(`http_4xx`), 0)            AS http_4xx,
           COALESCE(SUM(`http_5xx`), 0)            AS http_5xx
         FROM {$this->table} {$whereSql}",
        ...$values
      ),
      ARRAY_A
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

    $row          = $row ?: [];
    $sumDur       = (int) ($row['sum_duration_ms'] ?? 0);
    $cntDur       = (int) ($row['count_with_duration'] ?? 0);

    return [
      'success'            => (int) ($row['success'] ?? 0),
      'permanently_failed' => (int) ($row['permanently_failed'] ?? 0),
      'avg_duration_ms'    => $cntDur > 0 ? (int) round($sumDur / $cntDur) : 0,
      'http_2xx'           => (int) ($row['http_2xx'] ?? 0),
      'http_4xx'           => (int) ($row['http_4xx'] ?? 0),
      'http_5xx'           => (int) ($row['http_5xx'] ?? 0),
    ];
  }

  /**
   * Get all-time aggregated stats, optionally filtered by webhook.
   *
   * @param int|null $webhookId
   * @return array{total_sent: int, total_success: int, total_error: int}
   */
  public function getAllTimeStats(?int $webhookId = null): array {
    global $wpdb;

    $where  = [];
    $values = [];

    if ($webhookId !== null) {
      $where[]  = '`webhook_id` = %d';
      $values[] = $webhookId;
    }

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $query = "SELECT
                COALESCE(SUM(`success`), 0)            AS success,
                COALESCE(SUM(`permanently_failed`), 0) AS permanently_failed
              FROM {$this->table} {$whereSql}";

    $row = !empty($values)
      ? $wpdb->get_row($wpdb->prepare($query, ...$values), ARRAY_A)
      : $wpdb->get_row($query, ARRAY_A);
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

    $success  = (int) ($row['success'] ?? 0);
    $permFail = (int) ($row['permanently_failed'] ?? 0);

    return [
      'total_sent'    => $success + $permFail,
      'total_success' => $success,
      'total_error'   => $permFail,
    ];
  }

  /**
   * Bucket an HTTP code into 2xx / 4xx / 5xx columns.
   *
   * @param int|null $code
   * @return array{int, int, int} [http_2xx, http_4xx, http_5xx]
   */
  private function bucketHttpCode(?int $code): array {
    if ($code === null) {
      return [0, 0, 0];
    }

    if ($code >= 200 && $code < 300) {
      return [1, 0, 0];
    }

    if ($code >= 400 && $code < 500) {
      return [0, 1, 0];
    }

    if ($code >= 500 && $code < 600) {
      return [0, 0, 1];
    }

    return [0, 0, 0];
  }
}
