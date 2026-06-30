<?php

namespace FlowSystems\WebhookActions\Repositories;

defined('ABSPATH') || exit;

/**
 * Store for AI Builder conversations.
 *
 * A conversation holds the chat transcript, the current (editable) plan the
 * agent proposed, and the last applied recipe so a build can be undone in one
 * click. It contains no secrets — provider keys live in the credentials vault.
 *
 * JSON columns are stored as text and decoded on read so callers always get
 * arrays back.
 */
class AgentConversationRepository {
  private string $table;

  /** JSON-encoded columns, decoded transparently on read. */
  private const JSON_COLUMNS = ['transcript_json', 'plan_json', 'last_recipe_json', 'execution_json'];

  public function __construct() {
    global $wpdb;
    $this->table = $wpdb->prefix . 'fswa_agent_conversations';
  }

  /**
   * List conversations (metadata-light: omits the heavy JSON blobs).
   *
   * @return array<int, array<string, mixed>>
   */
  public function getAll(string $status = 'active'): array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT id, uuid, title, status, transport, model, created_at, updated_at
         FROM {$this->table} WHERE status = %s ORDER BY updated_at DESC",
        $status
      ),
      ARRAY_A
    ) ?: [];

    return $rows;
  }

  /**
   * Find a full conversation by ID, with JSON columns decoded.
   */
  public function find(int $id): ?array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $row = $wpdb->get_row(
      $wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id),
      ARRAY_A
    );

    return $row ? $this->hydrate($row) : null;
  }

  /**
   * Create a conversation. Returns the new ID or false.
   *
   * @param array{title?:string, transport?:string, model?:string, transcript?:array, plan?:array} $data
   */
  public function create(array $data): int|false {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $result = $wpdb->insert(
      $this->table,
      [
        'uuid'             => wp_generate_uuid4(),
        'title'            => (string) ($data['title'] ?? ''),
        'status'           => 'active',
        'transport'        => (string) ($data['transport'] ?? ''),
        'model'            => (string) ($data['model'] ?? ''),
        'transcript_json'  => isset($data['transcript']) ? wp_json_encode($data['transcript']) : null,
        'plan_json'        => isset($data['plan']) ? wp_json_encode($data['plan']) : null,
        'last_recipe_json' => isset($data['last_recipe']) ? wp_json_encode($data['last_recipe']) : null,
      ],
      ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
    );

    return $result !== false ? (int) $wpdb->insert_id : false;
  }

  /**
   * Update a conversation. Array/object values for JSON columns are encoded
   * automatically; scalar columns (title, status, transport, model) pass through.
   */
  public function update(int $id, array $data): bool {
    global $wpdb;

    $updateData = [];
    $format     = [];

    foreach (['title', 'status', 'transport', 'model'] as $field) {
      if (array_key_exists($field, $data)) {
        $updateData[$field] = (string) $data[$field];
        $format[]           = '%s';
      }
    }

    // Allow callers to pass either the raw column name or the friendly key.
    $jsonMap = [
      'transcript'  => 'transcript_json',
      'plan'        => 'plan_json',
      'last_recipe' => 'last_recipe_json',
      'execution'   => 'execution_json',
    ];
    foreach ($jsonMap as $friendly => $column) {
      if (array_key_exists($friendly, $data)) {
        $value              = $data[$friendly];
        $updateData[$column] = $value === null ? null : wp_json_encode($value);
        $format[]            = '%s';
      } elseif (array_key_exists($column, $data)) {
        $updateData[$column] = $data[$column] === null ? null : (string) $data[$column];
        $format[]            = '%s';
      }
    }

    if (empty($updateData)) {
      return true;
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $result = $wpdb->update($this->table, $updateData, ['id' => $id], $format, ['%d']);

    return $result !== false;
  }

  /**
   * Delete a conversation by ID.
   */
  public function delete(int $id): bool {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $result = $wpdb->delete($this->table, ['id' => $id], ['%d']);

    return $result !== false && $result > 0;
  }

  /**
   * Decode JSON columns into arrays and normalize the boolean-ish status.
   */
  private function hydrate(array $row): array {
    foreach (self::JSON_COLUMNS as $column) {
      $row[$column] = !empty($row[$column]) ? json_decode((string) $row[$column], true) : null;
    }

    return $row;
  }
}
