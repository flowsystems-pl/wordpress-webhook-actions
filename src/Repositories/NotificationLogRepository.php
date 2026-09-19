<?php

namespace FlowSystems\WebhookActions\Repositories;

defined('ABSPATH') || exit;

/**
 * What the notifier did: one row per (rule, channel, delivery event), whether
 * it was sent, failed, throttled or folded into a digest. Feeds the "Sent"
 * tab, the bell on delivery-log rows, and the throttle check.
 */
class NotificationLogRepository {
  public const STATUS_PENDING   = 'pending';
  public const STATUS_SENDING   = 'sending';
  public const STATUS_SENT      = 'sent';
  public const STATUS_FAILED    = 'failed';
  public const STATUS_THROTTLED = 'throttled';
  public const STATUS_DIGESTED  = 'digested';   // waiting in a digest bucket
  public const STATUS_IN_DIGEST = 'in_digest';  // folded into a digest that went out

  /** Statuses that count as "this rule fired" for throttling. */
  private const COUNTS_AS_SENT = ['pending', 'sending', 'sent'];

  private string $table;
  private string $channelsTable;
  private string $rulesTable;
  private string $webhooksTable;

  public function __construct() {
    global $wpdb;
    $this->table         = $wpdb->prefix . 'fswa_notification_log';
    $this->channelsTable = $wpdb->prefix . 'fswa_notification_channels';
    $this->rulesTable    = $wpdb->prefix . 'fswa_notification_rules';
    $this->webhooksTable = $wpdb->prefix . 'fswa_webhooks';
  }

  /**
   * @param array{rule_id:?int, channel_id:?int, webhook_id:?int, log_id:?int, event:string, status:string, error?:?string, subject?:string} $data
   */
  public function create(array $data): int|false {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $result = $wpdb->insert(
      $this->table,
      [
        'rule_id'    => $data['rule_id'] ?? null,
        'channel_id' => $data['channel_id'] ?? null,
        'webhook_id' => $data['webhook_id'] ?? null,
        'log_id'     => $data['log_id'] ?? null,
        'event'      => (string) ($data['event'] ?? ''),
        'status'     => (string) ($data['status'] ?? self::STATUS_SENT),
        'error'      => isset($data['error']) ? mb_substr((string) $data['error'], 0, 2000) : null,
        'subject'    => mb_substr((string) ($data['subject'] ?? ''), 0, 255),
        'message_json' => isset($data['message']) && is_array($data['message']) ? wp_json_encode($data['message'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
        'created_at' => gmdate('Y-m-d H:i:s'),
      ],
      ['%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
    );

    return $result !== false ? (int) $wpdb->insert_id : false;
  }

  /**
   * When did this rule last actually send for this webhook? Throttle input.
   */
  public function lastSentAt(int $ruleId, ?int $webhookId): ?string {
    global $wpdb;

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The plugin's own table from $wpdb->prefix; the status list and the IN() placeholder list are built from literals / %d only, and every value goes through $wpdb->prepare().
    $statuses = "'" . implode("','", self::COUNTS_AS_SENT) . "'";
    if ($webhookId === null) {
      $value = $wpdb->get_var($wpdb->prepare("SELECT MAX(created_at) FROM {$this->table} WHERE rule_id = %d AND status IN ({$statuses})", $ruleId));
    } else {
      $value = $wpdb->get_var($wpdb->prepare("SELECT MAX(created_at) FROM {$this->table} WHERE rule_id = %d AND webhook_id = %d AND status IN ({$statuses})", $ruleId, $webhookId));
    }

    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

    return $value ?: null;
  }

  /**
   * Paginated listing joined with rule, channel and webhook names.
   *
   * @param array{webhook_id?:int, rule_id?:int, channel_id?:int, status?:string, log_id?:int} $filters
   * @return array{items: array<int, array<string, mixed>>, total: int}
   */
  public function getPaginated(array $filters = [], int $page = 1, int $perPage = 20): array {
    global $wpdb;

    $where  = ['1=1'];
    $params = [];

    foreach (['webhook_id', 'rule_id', 'channel_id', 'log_id'] as $col) {
      if (!empty($filters[$col])) {
        $where[]  = "n.{$col} = %d";
        $params[] = (int) $filters[$col];
      }
    }
    if (!empty($filters['status'])) {
      $where[]  = 'n.status = %s';
      $params[] = (string) $filters['status'];
    }

    $whereSql = implode(' AND ', $where);
    $offset   = max(0, ($page - 1) * $perPage);

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names come from $wpdb->prefix, the WHERE fragments are literals with %d/%s placeholders, and every value goes through $wpdb->prepare().
    $countSql = "SELECT COUNT(*) FROM {$this->table} n WHERE {$whereSql}";
    $total    = (int) $wpdb->get_var(empty($params) ? $countSql : $wpdb->prepare($countSql, ...$params));

    $sql = "SELECT n.*, r.name AS rule_name, c.name AS channel_name, c.type AS channel_type, w.name AS webhook_name
              FROM {$this->table} n
              LEFT JOIN {$this->rulesTable} r ON r.id = n.rule_id
              LEFT JOIN {$this->channelsTable} c ON c.id = n.channel_id
              LEFT JOIN {$this->webhooksTable} w ON w.id = n.webhook_id
             WHERE {$whereSql}
             ORDER BY n.id DESC
             LIMIT %d OFFSET %d";
    $rows = $wpdb->get_results($wpdb->prepare($sql, ...array_merge($params, [$perPage, $offset])), ARRAY_A) ?: [];
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

    foreach ($rows as &$row) {
      foreach (['id', 'rule_id', 'channel_id', 'webhook_id', 'log_id', 'attempts'] as $col) {
        $row[$col] = isset($row[$col]) && $row[$col] !== null ? (int) $row[$col] : null;
      }
      unset($row['message_json']);
    }
    unset($row);

    return ['items' => $rows, 'total' => $total];
  }

  /**
   * Per delivery-log id: how many notifications went out and how many failed.
   * Feeds the bell icon on the logs table without a query per row.
   *
   * @param array<int> $logIds
   * @return array<int, array{sent:int, failed:int}>
   */
  public function summaryForLogs(array $logIds): array {
    global $wpdb;

    $logIds = array_values(array_unique(array_filter(array_map('intval', $logIds))));
    if (empty($logIds)) {
      return [];
    }

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The plugin's own table from $wpdb->prefix; the status list and the IN() placeholder list are built from literals / %d only, and every value goes through $wpdb->prepare().
    $placeholders = implode(',', array_fill(0, count($logIds), '%d'));
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT log_id, status, COUNT(*) AS n FROM {$this->table} WHERE log_id IN ({$placeholders}) GROUP BY log_id, status",
      ...$logIds
    ), ARRAY_A) ?: [];

    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $out = [];
    foreach ($rows as $row) {
      $logId = (int) $row['log_id'];
      $out[$logId] ??= ['sent' => 0, 'failed' => 0];
      if ($row['status'] === self::STATUS_SENT) {
        $out[$logId]['sent'] += (int) $row['n'];
      } elseif ($row['status'] === self::STATUS_FAILED) {
        $out[$logId]['failed'] += (int) $row['n'];
      }
    }

    return $out;
  }

  /**
   * Claim up to $limit pending rows for sending. Each row flips to `sending`
   * with a compare-and-set, so two concurrent ticks never send the same one.
   *
   * @return array<int, array<string, mixed>> rows with `message` decoded
   */
  public function claimPending(int $limit = 50): array {
    global $wpdb;

    // Rows stuck in `sending` (a crashed request) go back to pending after 10 minutes.
    $stale = gmdate('Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own table, named from $wpdb->prefix; every value goes through $wpdb->prepare().
    $wpdb->query($wpdb->prepare("UPDATE {$this->table} SET status = %s WHERE status = %s AND attempts < 3 AND created_at < %s", self::STATUS_PENDING, self::STATUS_SENDING, $stale));

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own table, named from $wpdb->prefix; every value goes through $wpdb->prepare().
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table} WHERE status = %s ORDER BY id ASC LIMIT %d", self::STATUS_PENDING, $limit), ARRAY_A) ?: [];

    $claimed = [];
    foreach ($rows as $row) {
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own table, named from $wpdb->prefix; every value goes through $wpdb->prepare().
      $won = $wpdb->query($wpdb->prepare("UPDATE {$this->table} SET status = %s, attempts = attempts + 1 WHERE id = %d AND status = %s", self::STATUS_SENDING, (int) $row['id'], self::STATUS_PENDING));
      if ($won) {
        $claimed[] = $this->cast($row);
      }
    }

    return $claimed;
  }

  public function markSent(int $id): void {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->update($this->table, ['status' => self::STATUS_SENT, 'sent_at' => gmdate('Y-m-d H:i:s'), 'error' => null], ['id' => $id], ['%s', '%s', '%s'], ['%d']);
  }

  /**
   * A failed send: retried on a later tick while attempts < 3, else final.
   */
  public function markFailed(int $id, string $error, bool $retry): void {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->update(
      $this->table,
      ['status' => $retry ? self::STATUS_PENDING : self::STATUS_FAILED, 'error' => mb_substr($error, 0, 2000)],
      ['id' => $id],
      ['%s', '%s'],
      ['%d']
    );
  }

  public function countPending(): int {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own table, named from $wpdb->prefix; every value goes through $wpdb->prepare().
    return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table} WHERE status = %s", self::STATUS_PENDING));
  }

  /**
   * Rows waiting in digest buckets for the given rules, oldest first.
   *
   * @param array<int> $ruleIds
   * @return array<int, array<string, mixed>>
   */
  public function digestedForRules(array $ruleIds): array {
    global $wpdb;

    $ruleIds = array_values(array_unique(array_map('intval', $ruleIds)));
    if (empty($ruleIds)) {
      return [];
    }
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The plugin's own table from $wpdb->prefix; the status list and the IN() placeholder list are built from literals / %d only, and every value goes through $wpdb->prepare().
    $placeholders = implode(',', array_fill(0, count($ruleIds), '%d'));
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table} WHERE status = %s AND rule_id IN ({$placeholders}) ORDER BY id ASC LIMIT 2000", self::STATUS_DIGESTED, ...$ruleIds), ARRAY_A) ?: [];

    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
    return array_map([$this, 'cast'], $rows);
  }

  /**
   * @param array<int> $ids
   */
  public function markInDigest(array $ids): void {
    global $wpdb;

    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (empty($ids)) {
      return;
    }
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The plugin's own table from $wpdb->prefix; the status list and the IN() placeholder list are built from literals / %d only, and every value goes through $wpdb->prepare().
    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    $wpdb->query($wpdb->prepare("UPDATE {$this->table} SET status = %s, sent_at = %s WHERE id IN ({$placeholders})", self::STATUS_IN_DIGEST, gmdate('Y-m-d H:i:s'), ...$ids));
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
  }

  public function find(int $id): ?array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own table, named from $wpdb->prefix; every value goes through $wpdb->prepare().
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id), ARRAY_A);

    return $row ? $this->cast($row) : null;
  }

  /**
   * @param array<string, mixed> $row
   * @return array<string, mixed>
   */
  private function cast(array $row): array {
    foreach (['id', 'rule_id', 'channel_id', 'webhook_id', 'log_id', 'attempts'] as $col) {
      $row[$col] = isset($row[$col]) && $row[$col] !== null ? (int) $row[$col] : null;
    }
    $message        = json_decode((string) ($row['message_json'] ?? ''), true);
    $row['message'] = is_array($message) ? $message : null;
    unset($row['message_json']);

    return $row;
  }

  public function pruneOlderThan(int $days): int {
    global $wpdb;

    $cutoff = gmdate('Y-m-d H:i:s', time() - max(1, $days) * DAY_IN_SECONDS);

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own table, named from $wpdb->prefix; every value goes through $wpdb->prepare().
    $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$this->table} WHERE created_at < %s", $cutoff));

    return $deleted !== false ? (int) $deleted : 0;
  }
}
