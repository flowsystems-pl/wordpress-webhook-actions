<?php

namespace FlowSystems\WebhookActions\Repositories;

defined('ABSPATH') || exit;

/**
 * Notification rules. A rule with webhook_id NULL is site-wide and applies to
 * every webhook that inherits; a rule with a webhook_id belongs to that
 * webhook alone.
 */
class NotificationRuleRepository {
  private string $table;

  public function __construct() {
    global $wpdb;
    $this->table = $wpdb->prefix . 'fswa_notification_rules';
  }

  /**
   * Site-wide rules, in display order.
   *
   * @return array<int, array<string, mixed>>
   */
  public function getGlobal(): array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own table, named from $wpdb->prefix.
    $rows = $wpdb->get_results("SELECT * FROM {$this->table} WHERE webhook_id IS NULL ORDER BY sort_order ASC, id ASC", ARRAY_A) ?: [];

    return array_map([$this, 'cast'], $rows);
  }

  /**
   * Rules owned by one webhook, in display order.
   *
   * @return array<int, array<string, mixed>>
   */
  public function getByWebhook(int $webhookId): array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own table, named from $wpdb->prefix; every value goes through $wpdb->prepare().
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table} WHERE webhook_id = %d ORDER BY sort_order ASC, id ASC", $webhookId), ARRAY_A) ?: [];

    return array_map([$this, 'cast'], $rows);
  }

  /**
   * Every enabled rule for one event, global and per-webhook alike. The
   * dispatcher narrows them to the webhook at hand.
   *
   * @return array<int, array<string, mixed>>
   */
  public function getEnabledForEvent(string $event): array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own table, named from $wpdb->prefix; every value goes through $wpdb->prepare().
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table} WHERE event = %s AND is_enabled = 1 ORDER BY sort_order ASC, id ASC", $event), ARRAY_A) ?: [];

    return array_map([$this, 'cast'], $rows);
  }

  public function find(int $id): ?array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own table, named from $wpdb->prefix; every value goes through $wpdb->prepare().
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id), ARRAY_A);

    return $row ? $this->cast($row) : null;
  }

  /**
   * @param array<string, mixed> $data name, event, webhook_id, is_enabled, filters, channel_ids, template, throttle_seconds, digest
   */
  public function create(array $data): int|false {
    global $wpdb;

    $webhookId = isset($data['webhook_id']) && (int) $data['webhook_id'] > 0 ? (int) $data['webhook_id'] : null;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $result = $wpdb->insert(
      $this->table,
      [
        'webhook_id'       => $webhookId,
        'name'             => (string) ($data['name'] ?? ''),
        'event'            => (string) $data['event'],
        'is_enabled'       => !array_key_exists('is_enabled', $data) || $data['is_enabled'] ? 1 : 0,
        'filters_json'     => wp_json_encode(is_array($data['filters'] ?? null) ? $data['filters'] : []),
        'channel_ids'      => wp_json_encode(array_values(array_map('intval', $data['channel_ids'] ?? []))),
        'template_json'    => isset($data['template']) && is_array($data['template']) ? wp_json_encode($data['template']) : null,
        'throttle_seconds' => isset($data['throttle_seconds']) && $data['throttle_seconds'] !== null ? (int) $data['throttle_seconds'] : null,
        'digest'           => !empty($data['digest']) ? (string) $data['digest'] : null,
        'sort_order'       => (int) ($data['sort_order'] ?? $this->nextSortOrder($webhookId)),
      ],
      ['%d', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%d']
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

    if (array_key_exists('name', $data)) {
      $updateData['name'] = (string) $data['name'];
      $format[]           = '%s';
    }
    if (array_key_exists('event', $data)) {
      $updateData['event'] = (string) $data['event'];
      $format[]            = '%s';
    }
    if (array_key_exists('is_enabled', $data)) {
      $updateData['is_enabled'] = $data['is_enabled'] ? 1 : 0;
      $format[]                 = '%d';
    }
    if (array_key_exists('filters', $data)) {
      $updateData['filters_json'] = wp_json_encode(is_array($data['filters']) ? $data['filters'] : []);
      $format[]                   = '%s';
    }
    if (array_key_exists('channel_ids', $data)) {
      $updateData['channel_ids'] = wp_json_encode(array_values(array_map('intval', is_array($data['channel_ids']) ? $data['channel_ids'] : [])));
      $format[]                  = '%s';
    }
    if (array_key_exists('template', $data)) {
      $updateData['template_json'] = is_array($data['template']) ? wp_json_encode($data['template']) : null;
      $format[]                    = '%s';
    }
    if (array_key_exists('throttle_seconds', $data)) {
      $updateData['throttle_seconds'] = $data['throttle_seconds'] !== null ? (int) $data['throttle_seconds'] : null;
      $format[]                       = '%d';
    }
    if (array_key_exists('digest', $data)) {
      $updateData['digest'] = !empty($data['digest']) ? (string) $data['digest'] : null;
      $format[]             = '%s';
    }
    if (array_key_exists('sort_order', $data)) {
      $updateData['sort_order'] = (int) $data['sort_order'];
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

  /**
   * Drop every rule a webhook owns (webhook deleted).
   */
  public function deleteByWebhook(int $webhookId): int {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $result = $wpdb->delete($this->table, ['webhook_id' => $webhookId], ['%d']);

    return $result !== false ? (int) $result : 0;
  }

  /**
   * Persist a new display order. $ids is the full ordered list for one scope.
   *
   * @param array<int> $ids
   */
  public function reorder(array $ids): void {
    global $wpdb;

    foreach (array_values($ids) as $position => $id) {
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
      $wpdb->update($this->table, ['sort_order' => $position], ['id' => (int) $id], ['%d'], ['%d']);
    }
  }

  private function nextSortOrder(?int $webhookId): int {
    global $wpdb;

    if ($webhookId === null) {
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own table, named from $wpdb->prefix.
      $max = $wpdb->get_var("SELECT MAX(sort_order) FROM {$this->table} WHERE webhook_id IS NULL");
    } else {
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own table, named from $wpdb->prefix; every value goes through $wpdb->prepare().
      $max = $wpdb->get_var($wpdb->prepare("SELECT MAX(sort_order) FROM {$this->table} WHERE webhook_id = %d", $webhookId));
    }

    return $max === null ? 0 : (int) $max + 1;
  }

  /**
   * @param array<string, mixed> $row
   * @return array<string, mixed>
   */
  private function cast(array $row): array {
    $filters  = json_decode((string) ($row['filters_json'] ?? ''), true);
    $channels = json_decode((string) ($row['channel_ids'] ?? ''), true);
    $template = $row['template_json'] !== null ? json_decode((string) $row['template_json'], true) : null;

    return [
      'id'               => (int) $row['id'],
      'webhook_id'       => $row['webhook_id'] !== null ? (int) $row['webhook_id'] : null,
      'name'             => (string) $row['name'],
      'event'            => (string) $row['event'],
      'is_enabled'       => (bool) $row['is_enabled'],
      'filters'          => is_array($filters) ? $filters : [],
      'channel_ids'      => is_array($channels) ? array_values(array_map('intval', $channels)) : [],
      'template'         => is_array($template) ? $template : null,
      'throttle_seconds' => $row['throttle_seconds'] !== null ? (int) $row['throttle_seconds'] : null,
      'digest'           => $row['digest'] ?: null,
      'sort_order'       => (int) $row['sort_order'],
      'created_at'       => $row['created_at'],
      'updated_at'       => $row['updated_at'],
    ];
  }
}
