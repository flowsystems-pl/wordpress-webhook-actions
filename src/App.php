<?php

namespace FlowSystems\WebhookActions;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Controllers\DispatcherController;
use FlowSystems\WebhookActions\Controllers\AdminController;
use FlowSystems\WebhookActions\Database\Migrator;
use FlowSystems\WebhookActions\Services\LogArchiver;
use FlowSystems\WebhookActions\Services\QueueService;
use FlowSystems\WebhookActions\Services\HookDiscoveryService;
use FlowSystems\WebhookActions\Services\Scheduler;
use FlowSystems\WebhookActions\Integrations\CF7Integration;

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
    // Register custom cron schedules (only needed when Action Scheduler is not active)
    if (!Scheduler::hasActionScheduler()) {
      add_filter('cron_schedules', [$this, 'registerCronSchedules']);
    }

    // Run migrations if needed
    if (Migrator::needsMigration()) {
      Migrator::migrate();
    }

    // Initialize controllers
    new DispatcherController();
    new AdminController();

    // Third-party integrations (loaded only when the plugin is active)
    if (class_exists('WPCF7_ContactForm')) {
      (new CF7Integration())->register();
    }

    // Register cleanup cron
    add_action('fswa_cleanup_logs', [$this, 'runLogCleanup']);

    // Bust hook discovery cache when plugins or theme change.
    add_action('activated_plugin', [HookDiscoveryService::class, 'clearCache']);
    add_action('deactivated_plugin', [HookDiscoveryService::class, 'clearCache']);
    add_action('switch_theme', [HookDiscoveryService::class, 'clearCache']);

    // Schedule queue processor and cleanup if not already scheduled.
    // Deferred to `init` so Action Scheduler is fully initialized before we call as_* functions.
    add_action('init', [$this, 'ensureScheduled'], 1);
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
   * Ensure recurring actions are scheduled (self-healing on every request)
   */
  public function ensureScheduled(): void {
    Scheduler::scheduleRecurring('fswa_process_queue', MINUTE_IN_SECONDS, 'every_minute');
    Scheduler::scheduleRecurring('fswa_cleanup_logs', DAY_IN_SECONDS, 'daily', strtotime('tomorrow 3:00am'));
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
