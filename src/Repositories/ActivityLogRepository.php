<?php

namespace FlowSystems\WebhookActions\Repositories;

defined('ABSPATH') || exit;

class ActivityLogRepository {
  private string $table;

  public function __construct() {
    global $wpdb;
    $this->table = $wpdb->prefix . 'fswa_activity_logs';
  }

  /**
   * Get paginated activity logs with filters.
   *
   * @param array $filters
   * @param int $page
   * @param int $perPage
   * @return array ['items' => array, 'total' => int, 'pages' => int]
   */
  public function getPaginated(array $filters = [], int $page = 1, int $perPage = 20): array {
    global $wpdb;

    $whereClauses = [];
    $whereValues  = [];

    if (!empty($filters['action'])) {
      $whereClauses[] = 'action = %s';
      $whereValues[]  = $filters['action'];
    }

    if (!empty($filters['action_prefix'])) {
      $whereClauses[] = 'action LIKE %s';
      $whereValues[]  = $wpdb->esc_like($filters['action_prefix']) . '%';
    }

    if (!empty($filters['user_id'])) {
      $whereClauses[] = 'user_id = %d';
      $whereValues[]  = (int) $filters['user_id'];
    }

    if (!empty($filters['object_type'])) {
      $whereClauses[] = 'object_type = %s';
      $whereValues[]  = $filters['object_type'];
    }

    if (!empty($filters['object_id'])) {
      $whereClauses[] = 'object_id = %d';
      $whereValues[]  = (int) $filters['object_id'];
    }

    if (!empty($filters['date_from'])) {
      $whereClauses[] = 'created_at >= %s';
      $whereValues[]  = $filters['date_from'];
    }

    if (!empty($filters['date_to'])) {
      $whereClauses[] = 'created_at <= %s';
      $whereValues[]  = $filters['date_to'];
    }

    $whereSql = !empty($whereClauses)
      ? 'WHERE ' . implode(' AND ', $whereClauses)
      : '';

    $offset = ($page - 1) * $perPage;

    $countQuery = "SELECT COUNT(*) FROM {$this->table} {$whereSql}";
    if (!empty($whereValues)) {
      // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
      $countQuery = $wpdb->prepare($countQuery, ...$whereValues);
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
    $total = (int) $wpdb->get_var($countQuery);

    $itemsQuery = "SELECT * FROM {$this->table} {$whereSql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
    $itemValues = array_merge($whereValues, [$perPage, $offset]);
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    $itemsQuery = $wpdb->prepare($itemsQuery, ...$itemValues);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
    $rows = $wpdb->get_results($itemsQuery, ARRAY_A);

    $items = array_map([$this, 'decodeRow'], $rows ?: []);

    return [
      'items' => $items,
      'total' => $total,
      'pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 1,
    ];
  }

  /**
   * Find a single activity log entry.
   */
  public function find(int $id): ?array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $row = $wpdb->get_row(
      $wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id),
      ARRAY_A
    );

    return $row ? $this->decodeRow($row) : null;
  }

  /**
   * Insert a new activity log entry. Returns the new row ID.
   */
  public function create(array $data): int {
    global $wpdb;

    $insert = [
      'user_id'     => isset($data['user_id']) ? (int) $data['user_id'] : null,
      'token_id'    => isset($data['token_id']) ? (int) $data['token_id'] : null,
      'token_hint'  => $data['token_hint'] ?? null,
      'action'      => $data['action'],
      'object_type' => $data['object_type'] ?? null,
      'object_id'   => isset($data['object_id']) ? (int) $data['object_id'] : null,
      'object_name' => $data['object_name'] ?? null,
      'context'     => isset($data['context']) ? wp_json_encode($data['context']) : null,
      'ip_address'  => $data['ip_address'] ?? null,
      'user_agent'  => $data['user_agent'] ?? null,
    ];

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $wpdb->insert($this->table, $insert);

    return (int) $wpdb->insert_id;
  }

  /**
   * Delete entries older than the given date string (Y-m-d H:i:s).
   */
  public function deleteOlderThan(string $date): int {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query(
      $wpdb->prepare("DELETE FROM {$this->table} WHERE created_at < %s", $date)
    );

    return (int) $wpdb->rows_affected;
  }

  /**
   * Count all activity log entries.
   */
  public function count(): int {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table}");
  }

  private function decodeRow(array $row): array {
    if (!empty($row['context'])) {
      $decoded = json_decode($row['context'], true);
      $row['context'] = is_array($decoded) ? $decoded : null;
    }

    foreach (['user_id', 'token_id', 'object_id'] as $intField) {
      if (isset($row[$intField])) {
        $row[$intField] = $row[$intField] !== null ? (int) $row[$intField] : null;
      }
    }

    return $row;
  }
}
