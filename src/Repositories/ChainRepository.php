<?php

namespace FlowSystems\WebhookActions\Repositories;

defined('ABSPATH') || exit;

class ChainRepository {
  private string $table;
  private string $linksTable;

  public function __construct() {
    global $wpdb;
    $this->table      = $wpdb->prefix . 'fswa_chains';
    $this->linksTable = $wpdb->prefix . 'fswa_chain_links';
  }

  public function getAll(): array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    return $wpdb->get_results(
      "SELECT id, name, description, created_at, updated_at FROM {$this->table} ORDER BY name ASC",
      ARRAY_A
    ) ?: [];
  }

  public function find(int $id): ?array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $row = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT id, name, description, created_at, updated_at FROM {$this->table} WHERE id = %d",
        $id
      ),
      ARRAY_A
    );

    return $row ?: null;
  }

  public function findByName(string $name): ?array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $row = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT id, name, description, created_at, updated_at FROM {$this->table} WHERE name = %s",
        $name
      ),
      ARRAY_A
    );

    return $row ?: null;
  }

  /**
   * @param array{name: string, description?: string|null} $data
   */
  public function create(array $data): int|false {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $result = $wpdb->insert(
      $this->table,
      [
        'name'        => $data['name'],
        'description' => $data['description'] ?? null,
      ],
      ['%s', '%s']
    );

    return $result !== false ? (int) $wpdb->insert_id : false;
  }

  /**
   * @param array{name?: string, description?: string|null} $data
   */
  public function update(int $id, array $data): bool {
    global $wpdb;

    $fields = [];
    $formats = [];
    if (array_key_exists('name', $data)) {
      $fields['name'] = $data['name'];
      $formats[]      = '%s';
    }
    if (array_key_exists('description', $data)) {
      $fields['description'] = $data['description'];
      $formats[]             = '%s';
    }
    if (empty($fields)) {
      return true;
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $result = $wpdb->update($this->table, $fields, ['id' => $id], $formats, ['%d']);
    return $result !== false;
  }

  public function delete(int $id): bool {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $result = $wpdb->delete($this->table, ['id' => $id], ['%d']);
    return $result !== false && $result > 0;
  }

  /**
   * For each chain, return the IDs of webhooks involved (sources + targets), deduped.
   *
   * @return array<int, int[]> Map of chain_id => webhook_id[]
   */
  public function getMembersByChain(): array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $rows = $wpdb->get_results(
      "SELECT chain_id, source_webhook_id, target_webhook_id FROM {$this->linksTable}",
      ARRAY_A
    ) ?: [];

    $map = [];
    foreach ($rows as $r) {
      $cid = (int) $r['chain_id'];
      $map[$cid] = $map[$cid] ?? [];
      $map[$cid][(int) $r['source_webhook_id']] = true;
      $map[$cid][(int) $r['target_webhook_id']] = true;
    }
    return array_map(fn(array $set): array => array_keys($set), $map);
  }
}
