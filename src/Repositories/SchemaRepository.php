<?php

namespace FlowSystems\WebhookActions\Repositories;

defined('ABSPATH') || exit;

class SchemaRepository {
  private string $schemasTable;

  public function __construct() {
    global $wpdb;
    $this->schemasTable = $wpdb->prefix . 'fswa_trigger_schemas';
  }

  /**
   * Find schema by webhook ID and trigger name
   *
   * @param int $webhookId
   * @param string $trigger
   * @return array|null
   */
  public function findByWebhookAndTrigger(int $webhookId, string $trigger): ?array {
    global $wpdb;

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $schema = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT * FROM {$this->schemasTable} WHERE webhook_id = %d AND trigger_name = %s",
        $webhookId,
        $trigger
      ),
      ARRAY_A
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

    if (!$schema) {
      return null;
    }

    return $this->decodeSchema($schema);
  }

  /**
   * Get all schemas for a webhook
   *
   * @param int $webhookId
   * @return array
   */
  public function getByWebhook(int $webhookId): array {
    global $wpdb;

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $schemas = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT * FROM {$this->schemasTable} WHERE webhook_id = %d ORDER BY trigger_name",
        $webhookId
      ),
      ARRAY_A
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

    if (empty($schemas)) {
      return [];
    }

    return array_map([$this, 'decodeSchema'], $schemas);
  }

  /**
   * Insert or update a schema
   *
   * @param int $webhookId
   * @param string $trigger
   * @param array $data
   * @return int|false Schema ID or false on failure
   */
  public function upsert(int $webhookId, string $trigger, array $data) {
    global $wpdb;

    $existing = $this->findByWebhookAndTrigger($webhookId, $trigger);

    $insertData = [
      'webhook_id' => $webhookId,
      'trigger_name' => $trigger,
    ];

    if (array_key_exists('example_payload', $data)) {
      $insertData['example_payload'] = is_array($data['example_payload'])
        ? wp_json_encode($data['example_payload'])
        : $data['example_payload'];
    }

    if (array_key_exists('field_mapping', $data)) {
      $insertData['field_mapping'] = is_array($data['field_mapping'])
        ? wp_json_encode($data['field_mapping'])
        : $data['field_mapping'];
    }

    if (array_key_exists('include_user_data', $data)) {
      $insertData['include_user_data'] = (int) $data['include_user_data'];
    }

    if (array_key_exists('captured_at', $data)) {
      $insertData['captured_at'] = $data['captured_at'];
    }

    if ($existing) {
      // Update existing record
      unset($insertData['webhook_id'], $insertData['trigger_name']);

      if (empty($insertData)) {
        return (int) $existing['id'];
      }

      // Build format array based on data types
      $formats = [];
      foreach ($insertData as $key => $value) {
        if ($key === 'include_user_data') {
          $formats[] = '%d';
        } else {
          $formats[] = '%s';
        }
      }

      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
      $result = $wpdb->update(
        $this->schemasTable,
        $insertData,
        ['id' => $existing['id']],
        $formats,
        ['%d']
      );

      return $result !== false ? (int) $existing['id'] : false;
    }

    // Insert new record - build format array based on data types
    $formats = [];
    foreach ($insertData as $key => $value) {
      if (in_array($key, ['webhook_id', 'include_user_data'], true)) {
        $formats[] = '%d';
      } else {
        $formats[] = '%s';
      }
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $result = $wpdb->insert($this->schemasTable, $insertData, $formats);

    return $result ? $wpdb->insert_id : false;
  }

  /**
   * Capture example payload for a webhook/trigger combination
   *
   * @param int $webhookId
   * @param string $trigger
   * @param array $payload
   * @return bool
   */
  public function captureExamplePayload(int $webhookId, string $trigger, array $payload): bool {
    $existing = $this->findByWebhookAndTrigger($webhookId, $trigger);

    // Only capture if we don't have an example payload yet
    if ($existing && !empty($existing['example_payload'])) {
      return true;
    }

    $result = $this->upsert($webhookId, $trigger, [
      'example_payload' => $payload,
      'captured_at' => current_time('mysql'),
    ]);

    return $result !== false;
  }

  /**
   * Delete a schema by ID
   *
   * @param int $id
   * @return bool
   */
  public function delete(int $id): bool {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $result = $wpdb->delete($this->schemasTable, ['id' => $id], ['%d']);

    return $result !== false;
  }

  /**
   * Delete all schemas for a webhook
   *
   * @param int $webhookId
   * @return bool
   */
  public function deleteByWebhook(int $webhookId): bool {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $result = $wpdb->delete($this->schemasTable, ['webhook_id' => $webhookId], ['%d']);

    return $result !== false;
  }

  /**
   * Delete schema by webhook and trigger
   *
   * @param int $webhookId
   * @param string $trigger
   * @return bool
   */
  public function deleteByWebhookAndTrigger(int $webhookId, string $trigger): bool {
    global $wpdb;

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $result = $wpdb->query(
      $wpdb->prepare(
        "DELETE FROM {$this->schemasTable} WHERE webhook_id = %d AND trigger_name = %s",
        $webhookId,
        $trigger
      )
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

    return $result !== false;
  }

  /**
   * Clear the captured example payload (for re-capture)
   *
   * @param int $webhookId
   * @param string $trigger
   * @return bool
   */
  public function clearExamplePayload(int $webhookId, string $trigger): bool {
    $existing = $this->findByWebhookAndTrigger($webhookId, $trigger);

    if (!$existing) {
      return true;
    }

    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $result = $wpdb->update(
      $this->schemasTable,
      [
        'example_payload' => null,
        'captured_at' => null,
      ],
      ['id' => $existing['id']],
      ['%s', '%s'],
      ['%d']
    );

    return $result !== false;
  }

  /**
   * Decode JSON fields in schema
   *
   * @param array $schema
   * @return array
   */
  private function decodeSchema(array $schema): array {
    if (!empty($schema['example_payload'])) {
      $decoded = json_decode($schema['example_payload'], true);
      if ($decoded !== null || json_last_error() === JSON_ERROR_NONE) {
        $schema['example_payload'] = $decoded;
      }
    }

    if (!empty($schema['field_mapping'])) {
      $decoded = json_decode($schema['field_mapping'], true);
      if ($decoded !== null || json_last_error() === JSON_ERROR_NONE) {
        $schema['field_mapping'] = $decoded;
      }
    }

    $schema['include_user_data'] = (bool) ($schema['include_user_data'] ?? false);

    return $schema;
  }
}
