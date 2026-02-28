<?php

namespace FlowSystems\WebhookActions\Database;

class Migrator {
  private const OPTION_KEY = 'fswa_db_version';
  private const CURRENT_VERSION = '1.1.0';

  /**
   * Run pending migrations
   */
  public static function migrate(): void {
    $currentVersion = get_option(self::OPTION_KEY, '0.0.0');

    // Check if critical tables are missing (handles flattened migrations)
    if (self::hasMissingTables()) {
      $currentVersion = '0.0.0';
    }

    if (version_compare($currentVersion, self::CURRENT_VERSION, '>=')) {
      return;
    }

    $migrations = self::getMigrations();

    foreach ($migrations as $version => $migration) {
      if (version_compare($currentVersion, $version, '<')) {
        $migration();
        update_option(self::OPTION_KEY, $version);
      }
    }
  }

  /**
   * Check if any required tables are missing
   */
  private static function hasMissingTables(): bool {
    global $wpdb;

    $requiredTables = [
      $wpdb->prefix . 'fswa_webhooks',
      $wpdb->prefix . 'fswa_webhook_triggers',
      $wpdb->prefix . 'fswa_logs',
      $wpdb->prefix . 'fswa_queue',
      $wpdb->prefix . 'fswa_trigger_schemas',
    ];

    foreach ($requiredTables as $table) {
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
      $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
      if (!$exists) {
        return true;
      }
    }

    return false;
  }

  /**
   * Get all migrations
   *
   * @return array<string, callable>
   */
  private static function getMigrations(): array {
    return [
      '1.0.0' => [self::class, 'migration_1_0_0'],
      '1.1.0' => [self::class, 'migration_1_1_0'],
    ];
  }

  /**
   * Migration 1.0.0 - Create all tables
   */
  public static function migration_1_0_0(): void {
    global $wpdb;

    $charsetCollate = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // Webhooks table
    $webhooksTable = $wpdb->prefix . 'fswa_webhooks';
    $sqlWebhooks = "CREATE TABLE {$webhooksTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            endpoint_url VARCHAR(2048) NOT NULL,
            auth_header VARCHAR(1024) DEFAULT NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_enabled (is_enabled)
        ) {$charsetCollate};";

    dbDelta($sqlWebhooks);

    // Webhook triggers table
    $triggersTable = $wpdb->prefix . 'fswa_webhook_triggers';
    $sqlTriggers = "CREATE TABLE {$triggersTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            webhook_id BIGINT UNSIGNED NOT NULL,
            trigger_name VARCHAR(255) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_webhook (webhook_id),
            KEY idx_trigger (trigger_name)
        ) {$charsetCollate};";

    dbDelta($sqlTriggers);

    // Logs table
    $logsTable = $wpdb->prefix . 'fswa_logs';
    $sqlLogs = "CREATE TABLE {$logsTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            webhook_id BIGINT UNSIGNED DEFAULT NULL,
            trigger_name VARCHAR(255) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            http_code SMALLINT UNSIGNED DEFAULT NULL,
            request_payload LONGTEXT,
            original_payload LONGTEXT DEFAULT NULL,
            mapping_applied TINYINT(1) NOT NULL DEFAULT 0,
            response_body LONGTEXT,
            error_message TEXT,
            duration_ms INT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_webhook (webhook_id),
            KEY idx_status (status),
            KEY idx_created (created_at),
            KEY idx_webhook_created (webhook_id, created_at)
        ) {$charsetCollate};";

    dbDelta($sqlLogs);

    // Queue table for webhook jobs
    $queueTable = $wpdb->prefix . 'fswa_queue';
    $sqlQueue = "CREATE TABLE {$queueTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            webhook_id BIGINT UNSIGNED NOT NULL,
            trigger_name VARCHAR(255) NOT NULL,
            payload LONGTEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
            locked_at DATETIME DEFAULT NULL,
            locked_by VARCHAR(64) DEFAULT NULL,
            scheduled_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status_scheduled (status, scheduled_at),
            KEY idx_locked (locked_at, locked_by),
            KEY idx_webhook (webhook_id)
        ) {$charsetCollate};";

    dbDelta($sqlQueue);

    // Trigger schemas table for payload mapping configuration
    $schemasTable = $wpdb->prefix . 'fswa_trigger_schemas';
    $sqlSchemas = "CREATE TABLE {$schemasTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            webhook_id BIGINT UNSIGNED NOT NULL,
            trigger_name VARCHAR(255) NOT NULL,
            example_payload LONGTEXT DEFAULT NULL,
            field_mapping LONGTEXT DEFAULT NULL,
            include_user_data TINYINT(1) NOT NULL DEFAULT 0,
            captured_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_webhook_trigger (webhook_id, trigger_name),
            KEY idx_webhook (webhook_id)
        ) {$charsetCollate};";

    dbDelta($sqlSchemas);
  }

  /**
   * Migration 1.1.0 - Add event identity and attempt history columns
   */
  public static function migration_1_1_0(): void {
    global $wpdb;

    $logsTable = $wpdb->prefix . 'fswa_logs';
    $queueTable = $wpdb->prefix . 'fswa_queue';

    // Columns to add to fswa_logs
    $logsColumns = [
      'event_uuid'      => "ALTER TABLE {$logsTable} ADD COLUMN event_uuid VARCHAR(36) DEFAULT NULL",
      'event_timestamp' => "ALTER TABLE {$logsTable} ADD COLUMN event_timestamp DATETIME DEFAULT NULL",
      'attempt_history' => "ALTER TABLE {$logsTable} ADD COLUMN attempt_history LONGTEXT DEFAULT NULL",
      'next_attempt_at' => "ALTER TABLE {$logsTable} ADD COLUMN next_attempt_at DATETIME DEFAULT NULL",
    ];

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    foreach ($logsColumns as $column => $sql) {
      $exists = $wpdb->get_var($wpdb->prepare(
        "SHOW COLUMNS FROM {$logsTable} LIKE %s",
        $column
      ));
      if (!$exists) {
        $wpdb->query($sql);
      }
    }

    // Add index on event_uuid if not exists
    $indexExists = $wpdb->get_var(
      "SHOW INDEX FROM {$logsTable} WHERE Key_name = 'idx_event_uuid'"
    );
    if (!$indexExists) {
      $wpdb->query("ALTER TABLE {$logsTable} ADD KEY idx_event_uuid (event_uuid)");
    }

    // Add log_id column to fswa_queue
    $logIdExists = $wpdb->get_var($wpdb->prepare(
      "SHOW COLUMNS FROM {$queueTable} LIKE %s",
      'log_id'
    ));
    if (!$logIdExists) {
      $wpdb->query("ALTER TABLE {$queueTable} ADD COLUMN log_id BIGINT UNSIGNED DEFAULT NULL");
      $wpdb->query("ALTER TABLE {$queueTable} ADD KEY idx_log_id (log_id)");
    }
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
  }

  /**
   * Get current database version
   */
  public static function getCurrentVersion(): string {
    return get_option(self::OPTION_KEY, '0.0.0');
  }

  /**
   * Get target database version
   */
  public static function getTargetVersion(): string {
    return self::CURRENT_VERSION;
  }

  /**
   * Check if migration is needed
   */
  public static function needsMigration(): bool {
    if (version_compare(self::getCurrentVersion(), self::CURRENT_VERSION, '<')) {
      return true;
    }

    // Also check if any tables are missing
    return self::hasMissingTables();
  }
}
