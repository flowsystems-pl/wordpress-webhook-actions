<?php

namespace FlowSystems\WebhookActions\Repositories;

defined('ABSPATH') || exit;

/**
 * Notification channels: named destinations (a Slack webhook, a Telegram
 * bot + chat, an SMS number) a rule sends to.
 *
 * Follows the Credentials Vault contract: `secret_ciphertext` never leaves the
 * public read methods. Drivers get the decrypted secret map through
 * findWithSecret() at send time only.
 */
class NotificationChannelRepository {
  private string $table;
  private string $rulesTable;

  /** Columns safe to expose — excludes secret_ciphertext. */
  private const PUBLIC_COLUMNS = 'id, name, type, config_json, hint, is_enabled, last_error, last_error_at, last_sent_at, created_at, updated_at';

  public function __construct() {
    global $wpdb;
    $this->table      = $wpdb->prefix . 'fswa_notification_channels';
    $this->rulesTable = $wpdb->prefix . 'fswa_notification_rules';
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  public function getAll(): array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table name is $wpdb->prefix plus a literal and the column list is a class constant.
    $rows = $wpdb->get_results("SELECT " . self::PUBLIC_COLUMNS . " FROM {$this->table} ORDER BY name ASC", ARRAY_A) ?: [];

    return array_map([$this, 'cast'], $rows);
  }

  public function find(int $id): ?array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table name is $wpdb->prefix plus a literal and the column list is a class constant; every value is a placeholder.
    $row = $wpdb->get_row($wpdb->prepare("SELECT " . self::PUBLIC_COLUMNS . " FROM {$this->table} WHERE id = %d", $id), ARRAY_A);

    return $row ? $this->cast($row) : null;
  }

  /**
   * Channel row including the ciphertext. Send-time use only — never over REST.
   */
  public function findWithSecret(int $id): ?array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own table, named from $wpdb->prefix; every value goes through $wpdb->prepare().
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id), ARRAY_A);

    return $row ? $this->cast($row) : null;
  }

  /**
   * @param array<int> $ids
   * @return array<int, array<string, mixed>> keyed by id, secrets included
   */
  public function findManyWithSecrets(array $ids): array {
    global $wpdb;

    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (empty($ids)) {
      return [];
    }

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The plugin's own tables from $wpdb->prefix; the IN() placeholder list is %d only and every value goes through $wpdb->prepare(); the two-column scans carry no user input.
    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table} WHERE id IN ({$placeholders})", ...$ids), ARRAY_A) ?: [];

    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $out = [];
    foreach ($rows as $row) {
      $out[(int) $row['id']] = $this->cast($row);
    }

    return $out;
  }

  /**
   * @param array{name:string, type:string, config:array, secret_ciphertext:?string, hint:string, is_enabled?:bool} $data
   */
  public function create(array $data): int|false {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $result = $wpdb->insert(
      $this->table,
      [
        'name'              => $data['name'],
        'type'              => $data['type'],
        'config_json'       => wp_json_encode($data['config'] ?? []),
        'secret_ciphertext' => $data['secret_ciphertext'] ?? null,
        'hint'              => $data['hint'] ?? '',
        'is_enabled'        => !empty($data['is_enabled']) || !array_key_exists('is_enabled', $data) ? 1 : 0,
      ],
      ['%s', '%s', '%s', '%s', '%s', '%d']
    );

    return $result !== false ? (int) $wpdb->insert_id : false;
  }

  /**
   * Only the keys present in $data are changed.
   */
  public function update(int $id, array $data): bool {
    global $wpdb;

    $updateData = [];
    $format     = [];

    foreach (['name', 'type', 'secret_ciphertext', 'hint'] as $field) {
      if (array_key_exists($field, $data)) {
        $updateData[$field] = $data[$field];
        $format[]           = '%s';
      }
    }
    if (array_key_exists('config', $data)) {
      $updateData['config_json'] = wp_json_encode(is_array($data['config']) ? $data['config'] : []);
      $format[]                  = '%s';
    }
    if (array_key_exists('is_enabled', $data)) {
      $updateData['is_enabled'] = $data['is_enabled'] ? 1 : 0;
      $format[]                 = '%d';
    }

    if (empty($updateData)) {
      return true;
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $result = $wpdb->update($this->table, $updateData, ['id' => $id], $format, ['%d']);

    return $result !== false;
  }

  public function delete(int $id): bool {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $result = $wpdb->delete($this->table, ['id' => $id], ['%d']);

    return $result !== false && $result > 0;
  }

  public function nameExists(string $name, int $excludeId = 0): bool {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own table, named from $wpdb->prefix; every value goes through $wpdb->prepare().
    $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table} WHERE name = %s AND id != %d", $name, $excludeId));

    return $id !== null;
  }

  /**
   * Remember a successful send (clears the standing error).
   */
  public function recordSent(int $id): void {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->update(
      $this->table,
      ['last_sent_at' => gmdate('Y-m-d H:i:s'), 'last_error' => null, 'last_error_at' => null],
      ['id' => $id],
      ['%s', '%s', '%s'],
      ['%d']
    );
  }

  public function recordError(int $id, string $error): void {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->update(
      $this->table,
      ['last_error' => mb_substr($error, 0, 1000), 'last_error_at' => gmdate('Y-m-d H:i:s')],
      ['id' => $id],
      ['%s', '%s'],
      ['%d']
    );
  }

  /**
   * Channels whose most recent send failed after their last success.
   *
   * @return array<int, array<string, mixed>>
   */
  public function failing(): array {
    return array_values(array_filter($this->getAll(), static function (array $c): bool {
      return $c['is_enabled'] && $c['last_error'] !== null;
    }));
  }

  /**
   * Rules that reference a channel (delete guard). channel_ids is a JSON int
   * list, so the match is done in PHP rather than with a LIKE.
   */
  public function countRulesUsing(int $id): int {
    global $wpdb;

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The plugin's own tables from $wpdb->prefix; the IN() placeholder list is %d only and every value goes through $wpdb->prepare(); the two-column scans carry no user input.
    $rows = $wpdb->get_results("SELECT id, channel_ids FROM {$this->rulesTable}", ARRAY_A) ?: [];
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

    $count = 0;
    foreach ($rows as $row) {
      $ids = json_decode((string) $row['channel_ids'], true);
      if (is_array($ids) && in_array($id, array_map('intval', $ids), true)) {
        $count++;
      }
    }

    return $count;
  }

  /**
   * Remove a channel from every rule that references it (force-delete path).
   */
  public function detachFromRules(int $id): void {
    global $wpdb;

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The plugin's own tables from $wpdb->prefix; the IN() placeholder list is %d only and every value goes through $wpdb->prepare(); the two-column scans carry no user input.
    $rows = $wpdb->get_results("SELECT id, channel_ids FROM {$this->rulesTable}", ARRAY_A) ?: [];
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

    foreach ($rows as $row) {
      $ids = json_decode((string) $row['channel_ids'], true);
      if (!is_array($ids)) {
        continue;
      }
      $ids = array_map('intval', $ids);
      if (!in_array($id, $ids, true)) {
        continue;
      }
      $remaining = array_values(array_filter($ids, static fn(int $c): bool => $c !== $id));
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
      $wpdb->update($this->rulesTable, ['channel_ids' => wp_json_encode($remaining)], ['id' => (int) $row['id']], ['%s'], ['%d']);
    }
  }

  /**
   * Every channel's id + ciphertext. Internal use only (key re-wrap).
   */
  public function allWithSecrets(): array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own table, named from $wpdb->prefix.
    return $wpdb->get_results("SELECT id, secret_ciphertext FROM {$this->table} WHERE secret_ciphertext IS NOT NULL", ARRAY_A) ?: [];
  }

  public function updateCiphertext(int $id, string $ciphertext): bool {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $result = $wpdb->update($this->table, ['secret_ciphertext' => $ciphertext], ['id' => $id], ['%s'], ['%d']);

    return $result !== false;
  }

  /**
   * @param array<string, mixed> $row
   * @return array<string, mixed>
   */
  private function cast(array $row): array {
    $row['id']         = (int) $row['id'];
    $row['is_enabled'] = (bool) $row['is_enabled'];
    $config            = json_decode((string) ($row['config_json'] ?? ''), true);
    $row['config']     = is_array($config) ? $config : [];
    unset($row['config_json']);

    return $row;
  }
}
