<?php

namespace FlowSystems\WebhookActions\Api;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use FlowSystems\WebhookActions\Services\LogArchiver;
use FlowSystems\WebhookActions\Repositories\LogRepository;

class SettingsController extends WP_REST_Controller {
  protected $namespace = 'fswa/v1';
  protected $rest_base = 'settings';

  private const DEFAULT_RETENTION_DAYS = 30;
  private const DEFAULT_ARCHIVE_LOGS = true;

  /**
   * Register routes
   */
  public function registerRoutes(): void {
    // Settings
    register_rest_route($this->namespace, '/' . $this->rest_base, [
      [
        'methods' => WP_REST_Server::READABLE,
        'callback' => [$this, 'getSettings'],
        'permission_callback' => [$this, 'permissionsCheck'],
      ],
      [
        'methods' => WP_REST_Server::EDITABLE,
        'callback' => [$this, 'updateSettings'],
        'permission_callback' => [$this, 'permissionsCheck'],
        'args' => [
          'log_retention_days' => [
            'type' => 'integer',
            'minimum' => 1,
            'maximum' => 365,
          ],
          'archive_logs' => [
            'type' => 'boolean',
          ],
        ],
      ],
    ]);

    // Archive info
    register_rest_route($this->namespace, '/' . $this->rest_base . '/archive', [
      'methods' => WP_REST_Server::READABLE,
      'callback' => [$this, 'getArchiveInfo'],
      'permission_callback' => [$this, 'permissionsCheck'],
    ]);

    // Download archive
    register_rest_route($this->namespace, '/' . $this->rest_base . '/archive/download', [
      'methods' => WP_REST_Server::READABLE,
      'callback' => [$this, 'downloadArchive'],
      'permission_callback' => [$this, 'permissionsCheck'],
    ]);

    // Clear all logs
    register_rest_route($this->namespace, '/' . $this->rest_base . '/clear-logs', [
      'methods' => WP_REST_Server::CREATABLE,
      'callback' => [$this, 'clearLogs'],
      'permission_callback' => [$this, 'permissionsCheck'],
    ]);

    // Plugin info
    register_rest_route($this->namespace, '/' . $this->rest_base . '/info', [
      'methods' => WP_REST_Server::READABLE,
      'callback' => [$this, 'getInfo'],
      'permission_callback' => [$this, 'permissionsCheck'],
    ]);
  }

  /**
   * Check permissions
   */
  public function permissionsCheck($request): bool {
    return current_user_can('manage_options');
  }

  /**
   * Get settings
   */
  public function getSettings($request): WP_REST_Response {
    $settings = [
      'log_retention_days' => (int) get_option('fswa_log_retention_days', self::DEFAULT_RETENTION_DAYS),
      'archive_logs' => (bool) get_option('fswa_archive_logs', self::DEFAULT_ARCHIVE_LOGS),
    ];

    return rest_ensure_response($settings);
  }

  /**
   * Update settings
   */
  public function updateSettings($request): WP_REST_Response {
    if ($request->has_param('log_retention_days')) {
      $days = (int) $request->get_param('log_retention_days');
      $days = max(1, min(365, $days));
      update_option('fswa_log_retention_days', $days);
    }

    if ($request->has_param('archive_logs')) {
      update_option('fswa_archive_logs', (bool) $request->get_param('archive_logs'));
    }

    return $this->getSettings($request);
  }

  /**
   * Get archive information
   */
  public function getArchiveInfo($request): WP_REST_Response {
    $archiver = new LogArchiver();
    $info = $archiver->getArchiveInfo();

    return rest_ensure_response($info);
  }

  /**
   * Download archive as ZIP
   */
  public function downloadArchive($request) {
    $archiver = new LogArchiver();
    $zipPath = $archiver->createArchiveZip();

    if (!$zipPath || !file_exists($zipPath)) {
      return new WP_Error(
        'rest_archive_not_found',
        __('No archive files found or failed to create ZIP.', 'flowsystems-webhook-actions'),
        ['status' => 404]
      );
    }

    // Return download URL
    $uploadDir = wp_upload_dir();
    $relativePath = str_replace($uploadDir['basedir'], '', $zipPath);
    $downloadUrl = $uploadDir['baseurl'] . $relativePath;

    return rest_ensure_response([
      'download_url' => $downloadUrl,
      'filename' => basename($zipPath),
      'size' => filesize($zipPath),
    ]);
  }

  /**
   * Clear all logs
   */
  public function clearLogs($request): WP_REST_Response {
    global $wpdb;

    $logsTable = $wpdb->prefix . 'fswa_logs';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $wpdb->query("TRUNCATE TABLE {$logsTable}");

    return rest_ensure_response([
      'success' => true,
      'message' => __('All logs have been cleared.', 'flowsystems-webhook-actions'),
    ]);
  }

  /**
   * Get plugin information
   */
  public function getInfo($request): WP_REST_Response {
    $logRepository = new LogRepository();

    $info = [
      'version' => defined('FSWA_VERSION') ? FSWA_VERSION : '1.0.0',
      'db_version' => get_option('fswa_db_version', '0.0.0'),
      'logs_count' => $logRepository->count(),
      'oldest_log' => $logRepository->getOldestDate(),
      'php_version' => PHP_VERSION,
      'wp_version' => get_bloginfo('version'),
    ];

    return rest_ensure_response($info);
  }
}
