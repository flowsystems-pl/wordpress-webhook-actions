<?php

namespace FlowSystems\WebhookActions\Database;

class Migrator {
  private const OPTION_KEY = 'fswa_db_version';
  private const CURRENT_VERSION = '1.9.0';

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
      $wpdb->prefix . 'fswa_stats',
      $wpdb->prefix . 'fswa_api_tokens',
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
      '1.2.0' => [self::class, 'migration_1_2_0'],
      '1.3.0' => [self::class, 'migration_1_3_0'],
      '1.4.0' => [self::class, 'migration_1_4_0'],
      '1.4.1' => [self::class, 'migration_1_4_1'],
      '1.5.0' => [self::class, 'migration_1_5_0'],
      '1.6.0' => [self::class, 'migration_1_6_0'],
      '1.7.0' => [self::class, 'migration_1_7_0'],
      '1.8.0' => [self::class, 'migration_1_8_0'],
      '1.9.0' => [self::class, 'migration_1_9_0'],
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

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
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
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
  }

  /**
   * Migration 1.2.0 - Add persistent stats table and stats_recorded flag on logs
   */
  public static function migration_1_2_0(): void {
    global $wpdb;

    $charsetCollate = $wpdb->get_charset_collate();
    $statsTable     = $wpdb->prefix . 'fswa_stats';
    $logsTable      = $wpdb->prefix . 'fswa_logs';

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sqlStats = "CREATE TABLE {$statsTable} (
            `date`                DATE         NOT NULL,
            `webhook_id`          BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `trigger_name`        VARCHAR(255) NOT NULL DEFAULT '',
            `success`             INT UNSIGNED NOT NULL DEFAULT 0,
            `permanently_failed`  INT UNSIGNED NOT NULL DEFAULT 0,
            `sum_duration_ms`     BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `count_with_duration` INT UNSIGNED NOT NULL DEFAULT 0,
            `http_2xx`            INT UNSIGNED NOT NULL DEFAULT 0,
            `http_4xx`            INT UNSIGNED NOT NULL DEFAULT 0,
            `http_5xx`            INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`date`, `webhook_id`, `trigger_name`)
        ) {$charsetCollate};";

    dbDelta($sqlStats);

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $exists = $wpdb->get_var($wpdb->prepare(
      "SHOW COLUMNS FROM {$logsTable} LIKE %s",
      'stats_recorded'
    ));
    if (!$exists) {
      $wpdb->query("ALTER TABLE {$logsTable} ADD COLUMN stats_recorded TINYINT(1) NOT NULL DEFAULT 0");
      $wpdb->query("ALTER TABLE {$logsTable} ADD KEY idx_stats_recorded (stats_recorded)");
    }
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
  }

  /**
   * Migration 1.3.0 - Add API tokens table
   */
  public static function migration_1_3_0(): void {
    global $wpdb;

    $charsetCollate = $wpdb->get_charset_collate();
    $tokensTable    = $wpdb->prefix . 'fswa_api_tokens';

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE {$tokensTable} (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name         VARCHAR(255) NOT NULL,
            token_hash   VARCHAR(64) NOT NULL,
            token_hint   VARCHAR(13) NOT NULL,
            scope        VARCHAR(20) NOT NULL DEFAULT 'read',
            expires_at   DATETIME DEFAULT NULL,
            last_used_at DATETIME DEFAULT NULL,
            rotated_at   DATETIME DEFAULT NULL,
            created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_token_hash (token_hash),
            KEY idx_expires (expires_at)
        ) {$charsetCollate};";

    dbDelta($sql);
  }

  /**
   * Migration 1.4.0 - Add conditions column to webhooks table
   */
  public static function migration_1_4_0(): void {
    global $wpdb;

    $webhooksTable = $wpdb->prefix . 'fswa_webhooks';

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $exists = $wpdb->get_var($wpdb->prepare(
      "SHOW COLUMNS FROM {$webhooksTable} LIKE %s",
      'conditions'
    ));
    if (!$exists) {
      $wpdb->query("ALTER TABLE {$webhooksTable} ADD COLUMN conditions LONGTEXT DEFAULT NULL");
    }
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
  }

  /**
   * Migration 1.4.1 - Move conditions from fswa_webhooks to fswa_trigger_schemas
   */
  public static function migration_1_4_1(): void {
    global $wpdb;

    $webhooksTable = $wpdb->prefix . 'fswa_webhooks';
    $schemasTable  = $wpdb->prefix . 'fswa_trigger_schemas';

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    // Drop conditions from webhooks if it was added by 1.4.0
    $webhookConditionsExists = $wpdb->get_var($wpdb->prepare(
      "SHOW COLUMNS FROM {$webhooksTable} LIKE %s",
      'conditions'
    ));
    if ($webhookConditionsExists) {
      $wpdb->query("ALTER TABLE {$webhooksTable} DROP COLUMN conditions");
    }

    // Add conditions to trigger_schemas
    $schemaConditionsExists = $wpdb->get_var($wpdb->prepare(
      "SHOW COLUMNS FROM {$schemasTable} LIKE %s",
      'conditions'
    ));
    if (!$schemaConditionsExists) {
      $wpdb->query("ALTER TABLE {$schemasTable} ADD COLUMN conditions LONGTEXT DEFAULT NULL");
    }
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
  }

  /**
   * Migration 1.5.0 - Add retry_limit column to webhooks table
   */
  public static function migration_1_5_0(): void {
    global $wpdb;

    $webhooksTable = $wpdb->prefix . 'fswa_webhooks';

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $exists = $wpdb->get_var($wpdb->prepare(
      "SHOW COLUMNS FROM {$webhooksTable} LIKE %s",
      'retry_limit'
    ));
    if (!$exists) {
      $wpdb->query("ALTER TABLE {$webhooksTable} ADD COLUMN retry_limit INT UNSIGNED NULL DEFAULT NULL");
    }
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
  }

  /**
   * Migration 1.6.0 - Add backoff config columns to webhooks table
   */
  public static function migration_1_6_0(): void {
    global $wpdb;

    $webhooksTable = $wpdb->prefix . 'fswa_webhooks';

    $columns = [
      'backoff_strategy'   => "ALTER TABLE {$webhooksTable} ADD COLUMN backoff_strategy VARCHAR(20) NULL DEFAULT NULL",
      'backoff_base_delay' => "ALTER TABLE {$webhooksTable} ADD COLUMN backoff_base_delay INT UNSIGNED NULL DEFAULT NULL",
      'backoff_max_delay'  => "ALTER TABLE {$webhooksTable} ADD COLUMN backoff_max_delay INT UNSIGNED NULL DEFAULT NULL",
    ];

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    foreach ($columns as $column => $sql) {
      $exists = $wpdb->get_var($wpdb->prepare(
        "SHOW COLUMNS FROM {$webhooksTable} LIKE %s",
        $column
      ));
      if (!$exists) {
        $wpdb->query($sql);
      }
    }
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
  }

  /**
   * Migration 1.7.0 - Add is_test flag to queue table
   */
  public static function migration_1_7_0(): void {
    global $wpdb;

    $queueTable = $wpdb->prefix . 'fswa_queue';

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $exists = $wpdb->get_var($wpdb->prepare(
      "SHOW COLUMNS FROM {$queueTable} LIKE %s",
      'is_test'
    ));
    if (!$exists) {
      $wpdb->query("ALTER TABLE {$queueTable} ADD COLUMN is_test TINYINT(1) NOT NULL DEFAULT 0");
    }
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
  }

  /**
   * Migration 1.8.0 - Add webhook_uuid to webhooks table
   */
  public static function migration_1_8_0(): void {
    global $wpdb;

    $webhooksTable = $wpdb->prefix . 'fswa_webhooks';

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $exists = $wpdb->get_var($wpdb->prepare(
      "SHOW COLUMNS FROM {$webhooksTable} LIKE %s",
      'webhook_uuid'
    ));
    if (!$exists) {
      $wpdb->query("ALTER TABLE {$webhooksTable} ADD COLUMN webhook_uuid VARCHAR(36) NOT NULL DEFAULT '' AFTER id");
    }

    // Populate UUIDs for existing webhooks (before adding the unique index)
    $rows = $wpdb->get_results(
      "SELECT id FROM {$webhooksTable} WHERE webhook_uuid = '' OR webhook_uuid IS NULL",
      ARRAY_A
    );
    foreach ($rows as $row) {
      $wpdb->update(
        $webhooksTable,
        ['webhook_uuid' => wp_generate_uuid4()],
        ['id' => (int) $row['id']],
        ['%s'],
        ['%d']
      );
    }

    // Add unique index if not present
    $indexExists = $wpdb->get_var(
      "SHOW INDEX FROM {$webhooksTable} WHERE Key_name = 'idx_webhook_uuid'"
    );
    if (!$indexExists) {
      $wpdb->query("ALTER TABLE {$webhooksTable} ADD UNIQUE KEY idx_webhook_uuid (webhook_uuid)");
    }
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
  }

  /**
   * Migration 1.9.0 - Add http_method, custom_headers, url_params to webhooks table
   */
  public static function migration_1_9_0(): void {
    global $wpdb;

    $table = $wpdb->prefix . 'fswa_webhooks';

    $columns = [
      'http_method'    => "ALTER TABLE {$table} ADD COLUMN http_method VARCHAR(10) NOT NULL DEFAULT 'POST'",
      'custom_headers' => "ALTER TABLE {$table} ADD COLUMN custom_headers TEXT NULL",
      'url_params'     => "ALTER TABLE {$table} ADD COLUMN url_params TEXT NULL",
    ];

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    foreach ($columns as $column => $sql) {
      $exists = $wpdb->get_var($wpdb->prepare(
        "SHOW COLUMNS FROM {$table} LIKE %s",
        $column
      ));
      if (!$exists) {
        $wpdb->query($sql);
      }
    }
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
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
