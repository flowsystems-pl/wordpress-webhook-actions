<?php

namespace FlowSystems\WebhookActions\Repositories;

defined('ABSPATH') || exit;

class LogRepository {
  private string $logsTable;
  private string $webhooksTable;

  public function __construct() {
    global $wpdb;
    $this->logsTable = $wpdb->prefix . 'fswa_logs';
    $this->webhooksTable = $wpdb->prefix . 'fswa_webhooks';
  }

  /**
   * Get paginated logs with filters
   *
   * @param array $filters
   * @param int $page
   * @param int $perPage
   * @return array ['items' => array, 'total' => int, 'pages' => int]
   */
  public function getPaginated(array $filters = [], int $page = 1, int $perPage = 20): array {
    global $wpdb;

    $whereClauses = [];
    $whereValues = [];

    if (!empty($filters['webhook_id'])) {
      $whereClauses[] = "l.webhook_id = %d";
      $whereValues[] = (int) $filters['webhook_id'];
    }

    if (!empty($filters['status'])) {
      $whereClauses[] = "l.status = %s";
      $whereValues[] = $filters['status'];
    }

    if (!empty($filters['trigger_name'])) {
      $whereClauses[] = "l.trigger_name = %s";
      $whereValues[] = $filters['trigger_name'];
    }

    if (!empty($filters['date_from'])) {
      $whereClauses[] = "l.created_at >= %s";
      $whereValues[] = $filters['date_from'];
    }

    if (!empty($filters['date_to'])) {
      $whereClauses[] = "l.created_at <= %s";
      $whereValues[] = $filters['date_to'];
    }

    if (!empty($filters['event_uuid'])) {
      $whereClauses[] = "l.event_uuid LIKE %s";
      $whereValues[] = '%' . $wpdb->esc_like($filters['event_uuid']) . '%';
    }

    if (!empty($filters['target_url'])) {
      $whereClauses[] = "w.endpoint_url LIKE %s";
      $whereValues[] = '%' . $wpdb->esc_like($filters['target_url']) . '%';
    }

    $whereSql = !empty($whereClauses)
      ? "WHERE " . implode(' AND ', $whereClauses)
      : "";

    // Always join so target_url filter works in both count and items queries
    $joinSql = "LEFT JOIN {$this->webhooksTable} w ON l.webhook_id = w.id";

    // Count total
    $countQuery = "SELECT COUNT(*) FROM {$this->logsTable} l {$joinSql} {$whereSql}";
    if (!empty($whereValues)) {
      // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
      $countQuery = $wpdb->prepare($countQuery, ...$whereValues);
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
    $total = (int) $wpdb->get_var($countQuery);

    $offset = ($page - 1) * $perPage;

    $queryValues = array_merge($whereValues, [$perPage, $offset]);
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $items = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT l.*, w.name as webhook_name, w.endpoint_url as target_url
                  FROM {$this->logsTable} l
                  {$joinSql}
                  {$whereSql}
                  ORDER BY l.created_at DESC
                  LIMIT %d OFFSET %d",
        ...$queryValues
      ),
      ARRAY_A
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

    foreach ($items as &$item) {
      if (!empty($item['request_payload'])) {
        $item['request_payload'] = json_decode($item['request_payload'], true);
      }
      if (!empty($item['original_payload'])) {
        $item['original_payload'] = json_decode($item['original_payload'], true);
      }
      if (!empty($item['response_body'])) {
        $decoded = json_decode($item['response_body'], true);
        $item['response_body'] = $decoded !== null ? $decoded : $item['response_body'];
      }
      if (!empty($item['attempt_history'])) {
        $decoded = json_decode($item['attempt_history'], true);
        $item['attempt_history'] = $decoded !== null ? $decoded : [];
      }
      $item['mapping_applied'] = (bool) ($item['mapping_applied'] ?? false);
    }

    return [
      'items' => $items ?: [],
      'total' => $total,
      'pages' => (int) ceil($total / $perPage),
      'page' => $page,
      'per_page' => $perPage,
    ];
  }

  /**
   * Get a single log by ID
   *
   * @param int $id
   * @return array|null
   */
  public function find(int $id): ?array {
    global $wpdb;

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $log = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT l.*, w.name as webhook_name, w.endpoint_url as target_url
                 FROM {$this->logsTable} l
                 LEFT JOIN {$this->webhooksTable} w ON l.webhook_id = w.id
                 WHERE l.id = %d",
        $id
      ),
      ARRAY_A
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

    if (!$log) {
      return null;
    }

    if (!empty($log['request_payload'])) {
      $log['request_payload'] = json_decode($log['request_payload'], true);
    }
    if (!empty($log['original_payload'])) {
      $log['original_payload'] = json_decode($log['original_payload'], true);
    }
    if (!empty($log['response_body'])) {
      $decoded = json_decode($log['response_body'], true);
      $log['response_body'] = $decoded !== null ? $decoded : $log['response_body'];
    }
    if (!empty($log['attempt_history'])) {
      $decoded = json_decode($log['attempt_history'], true);
      $log['attempt_history'] = $decoded !== null ? $decoded : [];
    }
    $log['mapping_applied'] = (bool) ($log['mapping_applied'] ?? false);

    return $log;
  }

  /**
   * Create a new log entry
   *
   * @param array $data
   * @return int|false The log ID or false on failure
   */
  public function create(array $data) {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $result = $wpdb->insert(
      $this->logsTable,
      [
        'webhook_id' => $data['webhook_id'] ?? null,
        'trigger_name' => $data['trigger_name'],
        'status' => $data['status'] ?? 'pending',
        'http_code' => $data['http_code'] ?? null,
        'request_payload' => isset($data['request_payload'])
          ? (is_array($data['request_payload']) ? wp_json_encode($data['request_payload']) : $data['request_payload'])
          : null,
        'original_payload' => isset($data['original_payload'])
          ? (is_array($data['original_payload']) ? wp_json_encode($data['original_payload']) : $data['original_payload'])
          : null,
        'mapping_applied' => isset($data['mapping_applied']) ? (int) $data['mapping_applied'] : 0,
        'response_body' => $data['response_body'] ?? null,
        'error_message' => $data['error_message'] ?? null,
        'duration_ms' => $data['duration_ms'] ?? null,
        'event_uuid' => $data['event_uuid'] ?? null,
        'event_timestamp' => $data['event_timestamp'] ?? null,
        'attempt_history' => isset($data['attempt_history'])
          ? (is_array($data['attempt_history']) ? wp_json_encode($data['attempt_history']) : $data['attempt_history'])
          : null,
        'next_attempt_at' => $data['next_attempt_at'] ?? null,
      ],
      ['%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s']
    );

    return $result ? $wpdb->insert_id : false;
  }

  /**
   * Update a log entry
   *
   * @param int $id
   * @param array $data
   * @return bool
   */
  public function update(int $id, array $data): bool {
    global $wpdb;

    $updateData = [];
    $format = [];

    if (isset($data['status'])) {
      $updateData['status'] = $data['status'];
      $format[] = '%s';
    }

    if (isset($data['http_code'])) {
      $updateData['http_code'] = $data['http_code'];
      $format[] = '%d';
    }

    if (isset($data['response_body'])) {
      $updateData['response_body'] = $data['response_body'];
      $format[] = '%s';
    }

    if (isset($data['error_message'])) {
      $updateData['error_message'] = $data['error_message'];
      $format[] = '%s';
    }

    if (isset($data['duration_ms'])) {
      $updateData['duration_ms'] = $data['duration_ms'];
      $format[] = '%d';
    }

    if (isset($data['attempt_history'])) {
      $updateData['attempt_history'] = is_array($data['attempt_history'])
        ? wp_json_encode($data['attempt_history'])
        : $data['attempt_history'];
      $format[] = '%s';
    }

    if (array_key_exists('next_attempt_at', $data)) {
      $updateData['next_attempt_at'] = $data['next_attempt_at'];
      $format[] = '%s';
    }

    if (empty($updateData)) {
      return true;
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $result = $wpdb->update(
      $this->logsTable,
      $updateData,
      ['id' => $id],
      $format,
      ['%d']
    );

    return $result !== false;
  }

  /**
   * Delete a log entry
   *
   * @param int $id
   * @return bool
   */
  public function delete(int $id): bool {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $result = $wpdb->delete($this->logsTable, ['id' => $id], ['%d']);

    return $result !== false;
  }

  /**
   * Delete logs older than specified date
   *
   * @param string $date MySQL datetime format
   * @return int Number of deleted rows
   */
  public function deleteOlderThan(string $date): int {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
    return (int) $wpdb->query(
      $wpdb->prepare(
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        "DELETE FROM {$this->logsTable} WHERE created_at < %s",
        $date
      )
    );
  }

  /**
   * Get logs older than specified date for archiving
   *
   * @param string $date MySQL datetime format
   * @param int $limit
   * @return array
   */
  public function getOlderThan(string $date, int $limit = 1000): array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
    return $wpdb->get_results(
      $wpdb->prepare(
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        "SELECT * FROM {$this->logsTable} WHERE created_at < %s ORDER BY created_at ASC LIMIT %d",
        $date,
        $limit
      ),
      ARRAY_A
    ) ?: [];
  }

  /**
   * Get logs for a specific webhook
   *
   * @param int $webhook_id
   * @param int $page
   * @param int $perPage
   * @return array
   */
  public function getByWebhook(int $webhookId, int $page = 1, int $perPage = 20): array {
    return $this->getPaginated(['webhook_id' => $webhookId], $page, $perPage);
  }

  /**
   * Get log statistics
   *
   * @param int|null $webhookId
   * @param int $days Number of days to look back
   * @return array
   */
  public function getStats(?int $webhookId = null, int $days = 7): array {
    global $wpdb;

    $dateFrom = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

    $whereWebhook = $webhookId
      ? $wpdb->prepare("AND webhook_id = %d", $webhookId)
      : "";

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $stats = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT status, COUNT(*) as count
                FROM {$this->logsTable}
                WHERE created_at >= %s {$whereWebhook}
                GROUP BY status",
        $dateFrom
      ),
      ARRAY_A
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

    $result = [
      'total' => 0,
      'success' => 0,
      'error' => 0,
      'pending' => 0,
      'retry' => 0,
      'permanently_failed' => 0,
    ];

    foreach ($stats as $stat) {
      if (array_key_exists($stat['status'], $result)) {
        $result[$stat['status']] = (int) $stat['count'];
      }
    }

    // Total only counts completed deliveries (success + error)
    $result['total'] = $result['success'] + $result['error'];

    return $result;
  }

  /**
   * Count total logs
   *
   * @return int
   */
  public function count(): int {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->logsTable}");
  }

  /**
   * Get all-time stats from current logs in database
   *
   * @return array{total_sent: int, total_success: int, total_error: int}
   */
  public function getAllTimeStats(): array {
    global $wpdb;

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $stats = $wpdb->get_results(
      "SELECT status, COUNT(*) as count
       FROM {$this->logsTable}
       WHERE status IN ('success', 'error')
       GROUP BY status",
      ARRAY_A
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

    $result = [
      'total_sent' => 0,
      'total_success' => 0,
      'total_error' => 0,
    ];

    foreach ($stats as $stat) {
      $count = (int) $stat['count'];
      if ($stat['status'] === 'success') {
        $result['total_success'] = $count;
      } elseif ($stat['status'] === 'error') {
        $result['total_error'] = $count;
      }
      $result['total_sent'] += $count;
    }

    return $result;
  }

  /**
   * Get oldest log date
   *
   * @return string|null
   */
  public function getOldestDate(): ?string {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    return $wpdb->get_var("SELECT MIN(created_at) FROM {$this->logsTable}");
  }

  /**
   * Get velocity statistics for webhook deliveries
   *
   * @return array ['last_hour' => int, 'last_day' => int, 'avg_duration_ms' => int]
   */
  public function getVelocityStats(): array {
    global $wpdb;

    $oneHourAgo = gmdate('Y-m-d H:i:s', strtotime('-1 hour'));
    $oneDayAgo = gmdate('Y-m-d H:i:s', strtotime('-24 hours'));

    // Count webhooks sent in the last hour
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $lastHour = (int) $wpdb->get_var(
      $wpdb->prepare(
        "SELECT COUNT(*) FROM {$this->logsTable} WHERE created_at >= %s",
        $oneHourAgo
      )
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

    // Count webhooks sent in the last 24 hours
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $lastDay = (int) $wpdb->get_var(
      $wpdb->prepare(
        "SELECT COUNT(*) FROM {$this->logsTable} WHERE created_at >= %s",
        $oneDayAgo
      )
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

    // Average duration for successful requests in the last 7 days
    $sevenDaysAgo = gmdate('Y-m-d H:i:s', strtotime('-7 days'));
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $avgDuration = $wpdb->get_var(
      $wpdb->prepare(
        "SELECT AVG(duration_ms) FROM {$this->logsTable}
         WHERE status = 'success' AND duration_ms IS NOT NULL AND created_at >= %s",
        $sevenDaysAgo
      )
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

    return [
      'last_hour' => $lastHour,
      'last_day' => $lastDay,
      'avg_duration_ms' => $avgDuration !== null ? (int) round((float) $avgDuration) : 0,
    ];
  }

  /**
   * Get average number of attempts per event (last 7 days)
   *
   * @return float
   */
  public function getAvgAttemptsPerEvent(): float {
    global $wpdb;

    $queueTable = $wpdb->prefix . 'fswa_queue';
    $sevenDaysAgo = gmdate('Y-m-d H:i:s', strtotime('-7 days'));

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $avg = $wpdb->get_var(
      $wpdb->prepare(
        "SELECT AVG(q.attempts + 1)
         FROM {$this->logsTable} l
         INNER JOIN {$queueTable} q ON q.log_id = l.id
         WHERE l.status IN ('success', 'error', 'permanently_failed')
         AND l.created_at >= %s",
        $sevenDaysAgo
      )
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

    return $avg !== null ? round((float) $avg, 2) : 0.0;
  }

  /**
   * Get age in seconds of the oldest pending log
   *
   * @return int|null Null if no pending logs
   */
  public function getOldestPendingAgeSeconds(): ?int {
    global $wpdb;

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $oldest = $wpdb->get_var(
      "SELECT MIN(created_at) FROM {$this->logsTable} WHERE status = 'pending'"
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

    if ($oldest === null) {
      return null;
    }

    return (int) (time() - strtotime($oldest));
  }

  /**
   * Find log IDs by status (for bulk operations)
   *
   * @param array $statuses
   * @param int $limit
   * @return int[]
   */
  public function findIdsByStatus(array $statuses, int $limit = 100): array {
    global $wpdb;

    if (empty($statuses)) {
      return [];
    }

    $placeholders = implode(', ', array_fill(0, count($statuses), '%s'));

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $ids = $wpdb->get_col(
      $wpdb->prepare(
        "SELECT id FROM {$this->logsTable} WHERE status IN ({$placeholders}) ORDER BY id ASC LIMIT %d",
        array_merge($statuses, [$limit])
      )
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

    return array_map('intval', $ids ?: []);
  }
}
