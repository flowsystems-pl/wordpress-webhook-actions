<?php

namespace FlowSystems\WebhookActions\Repositories;

defined('ABSPATH') || exit;

class ApiTokenRepository {
  private string $table;

  public function __construct() {
    global $wpdb;
    $this->table = $wpdb->prefix . 'fswa_api_tokens';
  }

  /**
   * Get all tokens — never includes token_hash
   */
  public function getAll(): array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    return $wpdb->get_results(
      "SELECT id, name, scope, token_hint, expires_at, last_used_at, rotated_at, created_at FROM {$this->table} ORDER BY created_at DESC",
      ARRAY_A
    ) ?: [];
  }

  /**
   * Find a token by ID — never includes token_hash
   */
  public function find(int $id): ?array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $row = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT id, name, scope, token_hint, expires_at, last_used_at, rotated_at, created_at FROM {$this->table} WHERE id = %d",
        $id
      ),
      ARRAY_A
    );

    return $row ?: null;
  }

  /**
   * Find a token by its hash — used only for validation
   */
  public function findByHash(string $hash): ?array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $row = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT id, name, scope, token_hint, expires_at, last_used_at, rotated_at, created_at FROM {$this->table} WHERE token_hash = %s",
        $hash
      ),
      ARRAY_A
    );

    return $row ?: null;
  }

  /**
   * Create a new token row
   *
   * @param array{name: string, scope: string, token_hash: string, token_hint: string, expires_at: string|null} $data
   */
  public function create(array $data): int|false {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $result = $wpdb->insert(
      $this->table,
      [
        'name'       => $data['name'],
        'scope'      => $data['scope'],
        'token_hash' => $data['token_hash'],
        'token_hint' => $data['token_hint'],
        'expires_at' => $data['expires_at'] ?? null,
      ],
      ['%s', '%s', '%s', '%s', '%s']
    );

    return $result !== false ? (int) $wpdb->insert_id : false;
  }

  /**
   * Rotate token: update hash, hint, and rotated_at; preserve all other fields
   */
  public function rotate(int $id, string $hash, string $hint): bool {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $result = $wpdb->update(
      $this->table,
      [
        'token_hash' => $hash,
        'token_hint' => $hint,
        'rotated_at' => gmdate('Y-m-d H:i:s'),
      ],
      ['id' => $id],
      ['%s', '%s', '%s'],
      ['%d']
    );

    return $result !== false;
  }

  /**
   * Delete a token by ID
   */
  public function delete(int $id): bool {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $result = $wpdb->delete($this->table, ['id' => $id], ['%d']);

    return $result !== false && $result > 0;
  }

  /**
   * Update last_used_at to now
   */
  public function touchLastUsed(int $id): void {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->update(
      $this->table,
      ['last_used_at' => gmdate('Y-m-d H:i:s')],
      ['id' => $id],
      ['%s'],
      ['%d']
    );
  }
}
