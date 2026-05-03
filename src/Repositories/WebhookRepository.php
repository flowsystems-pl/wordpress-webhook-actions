<?php

namespace FlowSystems\WebhookActions\Repositories;

defined('ABSPATH') || exit;

class WebhookRepository {
  private string $webhooksTable;
  private string $triggersTable;

  public function __construct() {
    global $wpdb;
    $this->webhooksTable = $wpdb->prefix . 'fswa_webhooks';
    $this->triggersTable = $wpdb->prefix . 'fswa_webhook_triggers';
  }

  /**
   * Get all webhooks with their triggers
   *
   * @param bool $only_enabled Only return enabled webhooks
   * @return array
   */
  public function getAll(bool $onlyEnabled = false): array {
    global $wpdb;

    $where = $onlyEnabled ? "WHERE is_enabled = 1" : "";

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $webhooks = $wpdb->get_results(
      "SELECT * FROM {$this->webhooksTable} {$where} ORDER BY created_at DESC",
      ARRAY_A
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

    if (empty($webhooks)) {
      return [];
    }

    // Fetch triggers for all webhooks
    $webhookIds = array_column($webhooks, 'id');
    $placeholders = implode(',', array_fill(0, count($webhookIds), '%d'));

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $triggers = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT webhook_id, trigger_name FROM {$this->triggersTable} WHERE webhook_id IN ({$placeholders})",
        ...$webhookIds
      ),
      ARRAY_A
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

    // Group triggers by webhook_id
    $triggersByWebhook = [];
    foreach ($triggers as $trigger) {
      $triggersByWebhook[$trigger['webhook_id']][] = $trigger['trigger_name'];
    }

    // Attach triggers to webhooks
    foreach ($webhooks as &$webhook) {
      $webhook['triggers'] = $triggersByWebhook[$webhook['id']] ?? [];
      $webhook['is_enabled'] = (bool) $webhook['is_enabled'];
      $webhook['is_synchronous'] = (bool) $webhook['is_synchronous'];
      $webhook['custom_headers'] = !empty($webhook['custom_headers']) ? json_decode($webhook['custom_headers'], true) : [];
      $webhook['url_params']     = !empty($webhook['url_params'])     ? json_decode($webhook['url_params'], true)     : [];
    }

    return $webhooks;
  }

  /**
   * Get a single webhook by ID
   *
   * @param int $id
   * @return array|null
   */
  public function find(int $id): ?array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $webhook = $wpdb->get_row(
      $wpdb->prepare(
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        "SELECT * FROM {$this->webhooksTable} WHERE id = %d",
        $id
      ),
      ARRAY_A
    );

    if (!$webhook) {
      return null;
    }

    // Fetch triggers
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $triggers = $wpdb->get_col(
      $wpdb->prepare(
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        "SELECT trigger_name FROM {$this->triggersTable} WHERE webhook_id = %d",
        $id
      )
    );

    $webhook['triggers'] = $triggers ?: [];
    $webhook['is_enabled'] = (bool) $webhook['is_enabled'];
    $webhook['is_synchronous'] = (bool) ($webhook['is_synchronous'] ?? false);
    $webhook['conditions'] = !empty($webhook['conditions'])
      ? json_decode($webhook['conditions'], true)
      : null;
    $webhook['custom_headers'] = !empty($webhook['custom_headers']) ? json_decode($webhook['custom_headers'], true) : [];
    $webhook['url_params']     = !empty($webhook['url_params'])     ? json_decode($webhook['url_params'], true)     : [];

    return $webhook;
  }

  /**
   * Get a map of webhook_id => webhook_uuid for a set of IDs
   *
   * @param int[] $ids
   * @return array<int, string>
   */
  public function getUuidMap(array $ids): array {
    if (empty($ids)) {
      return [];
    }

    global $wpdb;

    $placeholders = implode(',', array_fill(0, count($ids), '%d'));

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT id, webhook_uuid FROM {$this->webhooksTable} WHERE id IN ({$placeholders})",
        ...$ids
      ),
      ARRAY_A
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

    $map = [];
    foreach ($rows as $row) {
      $map[(int) $row['id']] = $row['webhook_uuid'];
    }

    return $map;
  }

  /**
   * Find a webhook by its UUID (exact match)
   *
   * @param string $uuid
   * @return array|null
   */
  public function findByUuid(string $uuid): ?array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $webhook = $wpdb->get_row(
      $wpdb->prepare(
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        "SELECT * FROM {$this->webhooksTable} WHERE webhook_uuid = %s",
        $uuid
      ),
      ARRAY_A
    );

    return $webhook ?: null;
  }

  /**
   * Find webhook IDs whose UUID contains the given partial string
   *
   * @param string $partial
   * @return int[]
   */
  public function findIdsByUuidPartial(string $partial): array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $ids = $wpdb->get_col(
      $wpdb->prepare(
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        "SELECT id FROM {$this->webhooksTable} WHERE webhook_uuid LIKE %s",
        '%' . $wpdb->esc_like($partial) . '%'
      )
    );

    return array_map('intval', $ids ?: []);
  }

  /**
   * Get webhooks by trigger name
   *
   * @param string $trigger_name
   * @return array
   */
  public function getByTrigger(string $triggerName): array {
    global $wpdb;

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $webhooks = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT w.* FROM {$this->webhooksTable} w
                INNER JOIN {$this->triggersTable} t ON w.id = t.webhook_id
                WHERE t.trigger_name = %s AND w.is_enabled = 1",
        $triggerName
      ),
      ARRAY_A
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

    if (empty($webhooks)) {
      return [];
    }

    // Attach all triggers
    foreach ($webhooks as &$webhook) {
      // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
      $triggers = $wpdb->get_col(
        $wpdb->prepare(
          "SELECT trigger_name FROM {$this->triggersTable} WHERE webhook_id = %d",
          $webhook['id']
        )
      );
      // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
      $webhook['triggers']       = $triggers ?: [];
      $webhook['is_enabled']     = (bool) $webhook['is_enabled'];
      $webhook['is_synchronous'] = (bool) ($webhook['is_synchronous'] ?? false);
      $webhook['custom_headers'] = !empty($webhook['custom_headers']) ? json_decode($webhook['custom_headers'], true) : [];
      $webhook['url_params']     = !empty($webhook['url_params'])     ? json_decode($webhook['url_params'], true)     : [];
    }

    return $webhooks;
  }

  /**
   * Create a new webhook
   *
   * @param array $data
   * @return int|false The webhook ID or false on failure
   */
  public function create(array $data) {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $result = $wpdb->insert(
      $this->webhooksTable,
      [
        'webhook_uuid'   => wp_generate_uuid4(),
        'name'           => $data['name'],
        'endpoint_url'   => $data['endpoint_url'],
        'http_method'    => strtoupper($data['http_method'] ?? 'POST'),
        'custom_headers' => !empty($data['custom_headers']) ? wp_json_encode($data['custom_headers']) : null,
        'url_params'     => !empty($data['url_params']) ? wp_json_encode($data['url_params']) : null,
        'auth_header'    => $data['auth_header'] ?? null,
        'is_enabled'     => isset($data['is_enabled']) ? (int) $data['is_enabled'] : 1,
        'is_synchronous' => isset($data['is_synchronous']) ? (int)(bool)$data['is_synchronous'] : 0,
      ],
      ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d']
    );

    if (!$result) {
      return false;
    }

    $webhookId = $wpdb->insert_id;

    // Insert triggers
    if (!empty($data['triggers']) && is_array($data['triggers'])) {
      $this->syncTriggers($webhookId, $data['triggers']);
    }

    return $webhookId;
  }

  /**
   * Update a webhook
   *
   * @param int $id
   * @param array $data
   * @return bool
   */
  public function update(int $id, array $data): bool {
    global $wpdb;

    $updateData = [];
    $format = [];

    if (isset($data['name'])) {
      $updateData['name'] = $data['name'];
      $format[] = '%s';
    }

    if (isset($data['endpoint_url'])) {
      $updateData['endpoint_url'] = $data['endpoint_url'];
      $format[] = '%s';
    }

    if (array_key_exists('auth_header', $data)) {
      $updateData['auth_header'] = $data['auth_header'] ?: null;
      $format[] = '%s';
    }

    if (isset($data['is_enabled'])) {
      $updateData['is_enabled'] = (int) $data['is_enabled'];
      $format[] = '%d';
    }

    if (isset($data['http_method'])) {
      $updateData['http_method'] = strtoupper($data['http_method']);
      $format[] = '%s';
    }

    if (array_key_exists('custom_headers', $data)) {
      $updateData['custom_headers'] = !empty($data['custom_headers']) ? wp_json_encode($data['custom_headers']) : null;
      $format[] = '%s';
    }

    if (array_key_exists('url_params', $data)) {
      $updateData['url_params'] = !empty($data['url_params']) ? wp_json_encode($data['url_params']) : null;
      $format[] = '%s';
    }

    if (isset($data['is_synchronous'])) {
      $updateData['is_synchronous'] = (int)(bool)$data['is_synchronous'];
      $format[] = '%d';
    }

    if (!empty($updateData)) {
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
      $result = $wpdb->update(
        $this->webhooksTable,
        $updateData,
        ['id' => $id],
        $format,
        ['%d']
      );

      if ($result === false) {
        return false;
      }
    }

    // Sync triggers if provided
    if (isset($data['triggers']) && is_array($data['triggers'])) {
      $this->syncTriggers($id, $data['triggers']);
    }

    return true;
  }

  /**
   * Delete a webhook
   *
   * @param int $id
   * @return bool
   */
  public function delete(int $id): bool {
    global $wpdb;

    // Triggers will be deleted via ON DELETE CASCADE, but let's be explicit
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->delete($this->triggersTable, ['webhook_id' => $id], ['%d']);

    // Delete related trigger schemas
    $schemasTable = $wpdb->prefix . 'fswa_trigger_schemas';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->delete($schemasTable, ['webhook_id' => $id], ['%d']);

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $result = $wpdb->delete($this->webhooksTable, ['id' => $id], ['%d']);

    return $result !== false;
  }

  /**
   * Sync triggers for a webhook
   *
   * @param int $webhook_id
   * @param array $triggers
   */
  private function syncTriggers(int $webhookId, array $triggers): void {
    global $wpdb;

    // Delete existing triggers
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->delete($this->triggersTable, ['webhook_id' => $webhookId], ['%d']);

    // Insert new triggers
    foreach ($triggers as $triggerName) {
      if (!empty($triggerName)) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->insert(
          $this->triggersTable,
          [
            'webhook_id' => $webhookId,
            'trigger_name' => $triggerName,
          ],
          ['%d', '%s']
        );
      }
    }
  }

  /**
   * Get all unique trigger names currently in use
   *
   * @return array
   */
  public function getAllTriggers(): array {
    global $wpdb;

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    return $wpdb->get_col(
      "SELECT DISTINCT trigger_name FROM {$this->triggersTable} ORDER BY trigger_name"
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
  }

  /**
   * Count webhooks
   *
   * @param bool $only_enabled
   * @return int
   */
  public function count(bool $onlyEnabled = false): int {
    global $wpdb;

    $where = $onlyEnabled ? "WHERE is_enabled = 1" : "";

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    return (int) $wpdb->get_var(
      "SELECT COUNT(*) FROM {$this->webhooksTable} {$where}"
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
  }

  /**
   * Toggle webhook enabled status
   *
   * @param int $id
   * @param bool $enabled
   * @return bool
   */
  public function setEnabled(int $id, bool $enabled): bool {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $result = $wpdb->update(
      $this->webhooksTable,
      ['is_enabled' => (int) $enabled],
      ['id' => $id],
      ['%d'],
      ['%d']
    );

    return $result !== false;
  }

  /**
   * Get disabled webhooks that have triggers matching the given trigger name.
   * Used to capture example payloads even when the webhook is not active.
   *
   * @param string $triggerName
   * @return array
   */
  public function getDisabledByTrigger(string $triggerName): array {
    global $wpdb;

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $webhooks = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT w.* FROM {$this->webhooksTable} w
                INNER JOIN {$this->triggersTable} t ON w.id = t.webhook_id
                WHERE t.trigger_name = %s AND w.is_enabled = 0",
        $triggerName
      ),
      ARRAY_A
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

    foreach ($webhooks as &$webhook) {
      $webhook['is_enabled'] = false;
    }

    return $webhooks;
  }

  /**
   * Get all configured & enabled webhooks
   *
   * @return array<int, array<string, mixed>>
   */
  public function getWebhooks(): array {
    return $this->getAll(true);
  }
}
