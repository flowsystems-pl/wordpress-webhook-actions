<?php

namespace FlowSystems\WebhookActions;

defined('ABSPATH') || exit;

class Activation {
  /**
   * Run activation tasks
   */
  public static function activate(): void {
    self::createTables();
    self::scheduleCleanupCron();
    self::scheduleQueueProcessor();
  }

  /**
   * Create database tables
   */
  public static function createTables(): void {
    global $wpdb;

    $charsetCollate = $wpdb->get_charset_collate();
    $webhooksTable = $wpdb->prefix . 'fswa_webhooks';
    $triggersTable = $wpdb->prefix . 'fswa_webhook_triggers';
    $logsTable = $wpdb->prefix . 'fswa_logs';
    $queueTable = $wpdb->prefix . 'fswa_queue';

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // Webhooks table
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
            event_uuid VARCHAR(36) DEFAULT NULL,
            event_timestamp DATETIME DEFAULT NULL,
            attempt_history LONGTEXT DEFAULT NULL,
            next_attempt_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_webhook (webhook_id),
            KEY idx_status (status),
            KEY idx_created (created_at),
            KEY idx_webhook_created (webhook_id, created_at),
            KEY idx_event_uuid (event_uuid)
        ) {$charsetCollate};";

    dbDelta($sqlLogs);

    // Queue table for webhook jobs
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
            log_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status_scheduled (status, scheduled_at),
            KEY idx_locked (locked_at, locked_by),
            KEY idx_webhook (webhook_id),
            KEY idx_log_id (log_id)
        ) {$charsetCollate};";

    dbDelta($sqlQueue);

    update_option('fswa_db_version', '1.1.0');
  }

  /**
   * Schedule the cleanup cron job
   */
  public static function scheduleCleanupCron(): void {
    if (!wp_next_scheduled('fswa_cleanup_logs')) {

      $timestamp = strtotime('tomorrow 3:00am');
      wp_schedule_event($timestamp, 'daily', 'fswa_cleanup_logs');
    }
  }

  /**
   * Schedule the queue processor cron job (every minute)
   */
  public static function scheduleQueueProcessor(): void {
    if (!wp_next_scheduled('fswa_process_queue')) {
      wp_schedule_event(time(), 'every_minute', 'fswa_process_queue');
    }

    add_filter('cron_schedules', function ($schedules) {
      if (!isset($schedules['every_minute'])) {
        $schedules['every_minute'] = [
          'interval' => 60,
          'display' => __('Every Minute', 'flowsystems-webhook-actions'),
        ];
      }
      return $schedules;
    });
  }

  /**
   * Clean up on deactivation
   */
  public static function deactivate(): void {
    wp_unschedule_hook('fswa_process_queue');
    wp_unschedule_hook('fswa_cleanup_logs');
  }

  /**
   * Remove all data on uninstall
   */
  public static function uninstall(): void {
    global $wpdb;

    // Drop tables (order matters for foreign key constraints)
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}fswa_queue");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}fswa_trigger_schemas");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}fswa_webhook_triggers");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}fswa_logs");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}fswa_webhooks");
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

    // Remove options
    delete_option('fswa_db_version');
    delete_option('fswa_log_retention_days');
    delete_option('fswa_archive_logs');
    delete_option('fswa_archived_stats');
  }
}
