<?php

namespace FlowSystems\WebhookActions;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Controllers\DispatcherController;
use FlowSystems\WebhookActions\Controllers\AdminController;
use FlowSystems\WebhookActions\Database\Migrator;
use FlowSystems\WebhookActions\Services\LogArchiver;
use FlowSystems\WebhookActions\Services\QueueService;

class App {
  const VERSION = '1.1.0';
  const SLUG = 'flowsystems-webhook-actions';

  public static string $path = '';
  public static string $url = '';

  private static $_instance;

  public static function instance(): void {
    if (null === self::$_instance) {
      self::$_instance = new self();
    }
  }

  private function __construct() {
    self::$_instance = $this;

    if (!self::$path) {
      self::$path = str_replace('/src', '', rtrim(plugin_dir_path(__FILE__), '/'));
    }

    if (!self::$url) {
      self::$url = str_replace('/src', '', rtrim(plugin_dir_url(__FILE__), '/'));
    }

    $this->init();
  }

  /**
   * Initialize the plugin
   *
   * @return void
   */
  public function init(): void {
    // Register custom cron schedules
    add_filter('cron_schedules', [$this, 'registerCronSchedules']);

    // Run migrations if needed
    if (Migrator::needsMigration()) {
      Migrator::migrate();
    }

    // Initialize controllers
    new DispatcherController();
    new AdminController();

    // Register cleanup cron
    add_action('fswa_cleanup_logs', [$this, 'runLogCleanup']);

    // Schedule queue processor if not already scheduled
    $this->ensureQueueProcessorScheduled();
  }

  /**
   * Register custom cron schedules
   */
  public function registerCronSchedules(array $schedules): array {
    if (!isset($schedules['every_minute'])) {
      $schedules['every_minute'] = [
        'interval' => 60,
        'display' => __('Every Minute', 'flowsystems-webhook-actions'),
      ];
    }
    return $schedules;
  }

  /**
   * Ensure the queue processor cron is scheduled
   */
  private function ensureQueueProcessorScheduled(): void {
    if (!wp_next_scheduled('fswa_process_queue')) {
      wp_schedule_event(time(), 'every_minute', 'fswa_process_queue');
    }
  }

  /**
   * Run log cleanup and archiving
   */
  public function runLogCleanup(): void {
    $retentionDays = (int) get_option('fswa_log_retention_days', 30);
    $archiveEnabled = (bool) get_option('fswa_archive_logs', true);

    $archiver = new LogArchiver();

    if ($archiveEnabled) {
      // Archive logs before deleting
      $archiver->archiveLogs($retentionDays);
    } else {
      // Delete old logs (aggregates stats before deletion)
      $archiver->deleteLogsWithoutArchive($retentionDays);
    }

    // Also cleanup old completed queue jobs (keep for 7 days)
    $queueService = new QueueService();
    $queueService->cleanupCompletedJobs(7);
  }

  /**
   * Deactivate plugin
   */
  public static function deactivate(): void {
    Activation::deactivate();
  }
}
