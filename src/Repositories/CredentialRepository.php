<?php

namespace FlowSystems\WebhookActions\Repositories;

defined('ABSPATH') || exit;

/**
 * Vault credentials store.
 *
 * The encrypted secret (`secret_ciphertext`) is never returned by the public
 * read methods — only metadata + a masked hint. The Dispatcher uses
 * findWithSecret() internally to resolve the outgoing auth header.
 */
class CredentialRepository {
  private string $table;
  private string $webhooksTable;

  /** Columns safe to expose — excludes secret_ciphertext. */
  private const PUBLIC_COLUMNS = 'id, name, type, header_name, hint, created_at, updated_at';

  public function __construct() {
    global $wpdb;
    $this->table         = $wpdb->prefix . 'fswa_credentials';
    $this->webhooksTable = $wpdb->prefix . 'fswa_webhooks';
  }

  /**
   * Get all credentials — never includes the secret.
   */
  public function getAll(): array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    return $wpdb->get_results(
      "SELECT " . self::PUBLIC_COLUMNS . " FROM {$this->table} ORDER BY created_at DESC",
      ARRAY_A
    ) ?: [];
  }

  /**
   * Find a credential by ID — never includes the secret.
   */
  public function find(int $id): ?array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $row = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT " . self::PUBLIC_COLUMNS . " FROM {$this->table} WHERE id = %d",
        $id
      ),
      ARRAY_A
    );

    return $row ?: null;
  }

  /**
   * Find a credential including its encrypted secret. Internal use only
   * (dispatch-time header resolution). Never expose this via REST.
   */
  public function findWithSecret(int $id): ?array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $row = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT id, name, type, header_name, secret_ciphertext FROM {$this->table} WHERE id = %d",
        $id
      ),
      ARRAY_A
    );

    return $row ?: null;
  }

  /**
   * Create a credential.
   *
   * @param array{name:string, type:string, header_name:string, secret_ciphertext:string, hint:string} $data
   */
  public function create(array $data): int|false {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $result = $wpdb->insert(
      $this->table,
      [
        'name'              => $data['name'],
        'type'              => $data['type'],
        'header_name'       => $data['header_name'],
        'secret_ciphertext' => $data['secret_ciphertext'],
        'hint'              => $data['hint'],
      ],
      ['%s', '%s', '%s', '%s', '%s']
    );

    return $result !== false ? (int) $wpdb->insert_id : false;
  }

  /**
   * Update a credential. Only the keys present in $data are changed.
   */
  public function update(int $id, array $data): bool {
    global $wpdb;

    $updateData = [];
    $format     = [];

    foreach (['name', 'type', 'header_name', 'secret_ciphertext', 'hint'] as $field) {
      if (array_key_exists($field, $data)) {
        $updateData[$field] = $data[$field];
        $format[]           = '%s';
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
   * Delete a credential by ID.
   */
  public function delete(int $id): bool {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $result = $wpdb->delete($this->table, ['id' => $id], ['%d']);

    return $result !== false && $result > 0;
  }

  /**
   * Every credential's id + ciphertext. Internal use only (key migration).
   */
  public function allWithSecrets(): array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    return $wpdb->get_results(
      "SELECT id, secret_ciphertext FROM {$this->table}",
      ARRAY_A
    ) ?: [];
  }

  /**
   * Replace just the ciphertext of a credential (key re-wrap).
   */
  public function updateCiphertext(int $id, string $ciphertext): bool {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $result = $wpdb->update($this->table, ['secret_ciphertext' => $ciphertext], ['id' => $id], ['%s'], ['%d']);

    return $result !== false;
  }

  /**
   * Count webhooks referencing a credential (delete guard).
   */
  public function countWebhooksUsing(int $id): int {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    return (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$this->webhooksTable} WHERE auth_credential_id = %d",
      $id
    ));
  }

  /**
   * Detach a credential from all webhooks that reference it (force-delete path).
   */
  public function detachFromWebhooks(int $id): void {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->update(
      $this->webhooksTable,
      ['auth_credential_id' => null],
      ['auth_credential_id' => $id],
      ['%d'],
      ['%d']
    );
  }

  /**
   * Whether a credential name is already taken (optionally excluding one ID).
   */
  public function nameExists(string $name, int $excludeId = 0): bool {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $id = $wpdb->get_var($wpdb->prepare(
      "SELECT id FROM {$this->table} WHERE name = %s AND id != %d",
      $name,
      $excludeId
    ));

    return $id !== null;
  }
}
