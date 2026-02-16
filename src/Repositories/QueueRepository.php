<?php

namespace FlowSystems\WebhookActions\Repositories;

defined('ABSPATH') || exit;

class QueueRepository {
  private string $table;

  public function __construct() {
    global $wpdb;
    $this->table = $wpdb->prefix . 'fswa_queue';
  }

  /**
   * Insert a new job into the queue
   *
   * @param array $data Job data
   * @return int Insert ID
   */
  public function insert(array $data): int {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $wpdb->insert(
      $this->table,
      $data,
      $this->getFormats($data)
    );

    return (int) $wpdb->insert_id;
  }

  /**
   * Find a job by ID
   *
   * @param int $id
   * @return array|null
   */
  public function find(int $id): ?array {
    global $wpdb;

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $job = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT * FROM {$this->table} WHERE id = %d",
        $id
      ),
      ARRAY_A
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

    return $job ?: null;
  }

  /**
   * Update a job by ID
   *
   * @param int $id
   * @param array $data
   * @return bool
   */
  public function update(int $id, array $data): bool {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $affected = $wpdb->update(
      $this->table,
      $data,
      ['id' => $id],
      $this->getFormats($data),
      ['%d']
    );

    return $affected !== false;
  }

  /**
   * Delete a job by ID
   *
   * @param int $id
   * @return bool
   */
  public function delete(int $id): bool {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $affected = $wpdb->delete(
      $this->table,
      ['id' => $id],
      ['%d']
    );

    return $affected > 0;
  }

  /**
   * Get next batch of jobs ready for processing
   *
   * @param string $now Current timestamp
   * @param int $batchSize
   * @return array
   */
  public function findPendingBatch(string $now, int $batchSize): array {
    global $wpdb;

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $jobs = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT * FROM {$this->table}
         WHERE status = 'pending'
         AND scheduled_at <= %s
         AND locked_at IS NULL
         ORDER BY scheduled_at ASC
         LIMIT %d",
        $now,
        $batchSize
      ),
      ARRAY_A
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

    return $jobs ?: [];
  }

  /**
   * Attempt to acquire lock on a job (atomic operation)
   *
   * @param int $jobId
   * @param string $lockId
   * @param string $now
   * @return bool True if lock acquired
   */
  public function acquireLock(int $jobId, string $lockId, string $now): bool {
    global $wpdb;

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $affected = $wpdb->query(
      $wpdb->prepare(
        "UPDATE {$this->table}
         SET locked_at = %s, locked_by = %s, status = 'processing'
         WHERE id = %d AND locked_at IS NULL",
        $now,
        $lockId,
        $jobId
      )
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

    return $affected === 1;
  }

  /**
   * Reset stale locked jobs
   *
   * @param string $threshold Timestamp threshold
   * @return int Number of jobs reset
   */
  public function resetStaleJobs(string $threshold): int {
    global $wpdb;

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $affected = $wpdb->query(
      $wpdb->prepare(
        "UPDATE {$this->table}
         SET locked_at = NULL, locked_by = NULL, status = 'pending'
         WHERE status = 'processing'
         AND locked_at < %s",
        $threshold
      )
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

    return (int) $affected;
  }

  /**
   * Get job counts grouped by status
   *
   * @return array
   */
  public function getCountsByStatus(): array {
    global $wpdb;

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $stats = $wpdb->get_results(
      "SELECT status, COUNT(*) as count FROM {$this->table} GROUP BY status",
      ARRAY_A
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

    return $stats ?: [];
  }

  /**
   * Get oldest pending job timestamp
   *
   * @return string|null
   */
  public function getOldestPendingTimestamp(): ?string {
    global $wpdb;

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $oldest = $wpdb->get_var(
      "SELECT MIN(scheduled_at) FROM {$this->table} WHERE status = 'pending'"
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

    return $oldest;
  }

  /**
   * Count jobs due now
   *
   * @param string $now
   * @return int
   */
  public function countDueNow(string $now): int {
    global $wpdb;

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $count = $wpdb->get_var(
      $wpdb->prepare(
        "SELECT COUNT(*) FROM {$this->table}
         WHERE status = 'pending' AND scheduled_at <= %s",
        $now
      )
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

    return (int) $count;
  }

  /**
   * Find jobs with filters
   *
   * @param array $filters
   * @param int $limit
   * @param int $offset
   * @return array
   */
  public function findBy(array $filters = [], int $limit = 50, int $offset = 0): array {
    global $wpdb;

    $where = ['1=1'];
    $params = [];

    if (!empty($filters['status'])) {
      $where[] = 'status = %s';
      $params[] = $filters['status'];
    }

    if (!empty($filters['webhook_id'])) {
      $where[] = 'webhook_id = %d';
      $params[] = (int) $filters['webhook_id'];
    }

    if (!empty($filters['trigger_name'])) {
      $where[] = 'trigger_name = %s';
      $params[] = $filters['trigger_name'];
    }

    $whereClause = implode(' AND ', $where);

    $params[] = $limit;
    $params[] = $offset;

    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
    return $wpdb->get_results(
      $wpdb->prepare(
        "SELECT * FROM {$this->table}
         WHERE {$whereClause}
         ORDER BY scheduled_at DESC
         LIMIT %d OFFSET %d",
        ...$params
      ),
      ARRAY_A
    ) ?: [];
    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
  }

  /**
   * Count jobs with filters
   *
   * @param array $filters
   * @return int
   */
  public function countBy(array $filters = []): int {
    global $wpdb;

    $where = ['1=1'];
    $params = [];

    if (!empty($filters['status'])) {
      $where[] = 'status = %s';
      $params[] = $filters['status'];
    }

    if (!empty($filters['webhook_id'])) {
      $where[] = 'webhook_id = %d';
      $params[] = (int) $filters['webhook_id'];
    }

    $whereClause = implode(' AND ', $where);

    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
    if (!empty($params)) {
      $count = $wpdb->get_var(
        $wpdb->prepare(
          "SELECT COUNT(*) FROM {$this->table} WHERE {$whereClause}",
          ...$params
        )
      );
    } else {
      $count = $wpdb->get_var(
        "SELECT COUNT(*) FROM {$this->table} WHERE {$whereClause}"
      );
    }
    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

    return (int) $count;
  }

  /**
   * Delete old completed jobs
   *
   * @param string $threshold
   * @return int Number deleted
   */
  public function deleteCompletedBefore(string $threshold): int {
    global $wpdb;

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $affected = $wpdb->query(
      $wpdb->prepare(
        "DELETE FROM {$this->table}
         WHERE status = 'completed'
         AND created_at < %s",
        $threshold
      )
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

    return (int) $affected;
  }

  /**
   * Get format specifiers for data array
   *
   * @param array $data
   * @return array
   */
  private function getFormats(array $data): array {
    $formats = [];
    foreach ($data as $value) {
      if (is_int($value)) {
        $formats[] = '%d';
      } elseif (is_float($value)) {
        $formats[] = '%f';
      } else {
        $formats[] = '%s';
      }
    }
    return $formats;
  }
}
