<?php

namespace FlowSystems\WebhookActions\Api;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use FlowSystems\WebhookActions\Services\LogArchiver;
use FlowSystems\WebhookActions\Repositories\LogRepository;
use FlowSystems\WebhookActions\Services\ActivityLogService;
use FlowSystems\WebhookActions\Services\GluePermissions;
use FlowSystems\WebhookActions\Services\Ai\AgentTraceLog;
use FlowSystems\WebhookActions\Abilities\AbilityRegistrar;

class SettingsController extends WP_REST_Controller {
  protected $namespace = 'fswa/v1';
  protected $rest_base = 'settings';

  private const DEFAULT_RETENTION_DAYS = 30;
  /**
   * Off for a new install since 3.4.3. A site that was archiving before the
   * update has the option written explicitly by migration 2.6.0 and keeps it.
   */
  public const DEFAULT_ARCHIVE_LOGS = false;
  private const DEFAULT_MENU_UNDER_TOOLS = false;
  private const DEFAULT_ACTIVITY_RETENTION_DAYS = 90;
  private const DEFAULT_AI_TRACE_ENABLED = false;

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
          'menu_under_tools' => [
            'type' => 'boolean',
          ],
          'activity_log_retention_days' => [
            'type'    => 'integer',
            'minimum' => 1,
            'maximum' => 365,
          ],
          'ai_trace_enabled' => [
            'type' => 'boolean',
          ],
          'ai_debug_enabled' => [
            'type' => 'boolean',
          ],
          'mcp_expose_writes' => [
            'type' => 'boolean',
          ],
          'glue_token_writes' => [
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
      'log_retention_days'          => (int) get_option('fswa_log_retention_days', self::DEFAULT_RETENTION_DAYS),
      'archive_logs'                => (bool) get_option('fswa_archive_logs', self::DEFAULT_ARCHIVE_LOGS),
      'menu_under_tools'            => (bool) get_option('fswa_menu_under_tools', self::DEFAULT_MENU_UNDER_TOOLS),
      'activity_log_retention_days' => (int) get_option('fswa_activity_log_retention_days', self::DEFAULT_ACTIVITY_RETENTION_DAYS),
      'ai_trace_enabled'            => (bool) get_option('fswa_ai_trace_enabled', self::DEFAULT_AI_TRACE_ENABLED),
      'ai_debug_enabled'            => (bool) get_option('fswa_ai_debug', AgentTraceLog::DEFAULT_ENABLED),
      'mcp_expose_writes'           => AbilityRegistrar::writesExposed(),
      'glue_token_writes'           => (new GluePermissions())->tokenWritesEnabled(),
      // Read-only: whether Code Glue can be written here at all, and why not.
      // The admin renders the reason rather than guessing at it, because only
      // one of the three has a switch on this screen.
      'glue'                        => (new GluePermissions())->status(),
    ];

    return rest_ensure_response($settings);
  }

  /**
   * Update settings
   */
  public function updateSettings($request): WP_REST_Response {
    $oldValues = [];
    $newValues = [];

    if ($request->has_param('log_retention_days')) {
      $days = max(1, min(365, (int) $request->get_param('log_retention_days')));
      $oldValues['log_retention_days'] = (int) get_option('fswa_log_retention_days', self::DEFAULT_RETENTION_DAYS);
      update_option('fswa_log_retention_days', $days);
      $newValues['log_retention_days'] = $days;
    }

    if ($request->has_param('archive_logs')) {
      $val = (bool) $request->get_param('archive_logs');
      $oldValues['archive_logs'] = (bool) get_option('fswa_archive_logs', self::DEFAULT_ARCHIVE_LOGS);
      // Stored as 1/0: update_option() silently skips writing `false` to an
      // option that does not exist yet, so "off" never saved on a fresh install.
      update_option('fswa_archive_logs', $val ? 1 : 0);
      $newValues['archive_logs'] = $val;
    }

    if ($request->has_param('menu_under_tools')) {
      $val = (bool) $request->get_param('menu_under_tools');
      $oldValues['menu_under_tools'] = (bool) get_option('fswa_menu_under_tools', self::DEFAULT_MENU_UNDER_TOOLS);
      update_option('fswa_menu_under_tools', $val);
      $newValues['menu_under_tools'] = $val;
    }

    if ($request->has_param('activity_log_retention_days')) {
      $actDays = max(1, min(365, (int) $request->get_param('activity_log_retention_days')));
      $oldValues['activity_log_retention_days'] = (int) get_option('fswa_activity_log_retention_days', self::DEFAULT_ACTIVITY_RETENTION_DAYS);
      update_option('fswa_activity_log_retention_days', $actDays);
      $newValues['activity_log_retention_days'] = $actDays;
    }

    if ($request->has_param('ai_trace_enabled')) {
      $val = (bool) $request->get_param('ai_trace_enabled');
      $oldValues['ai_trace_enabled'] = (bool) get_option('fswa_ai_trace_enabled', self::DEFAULT_AI_TRACE_ENABLED);
      update_option('fswa_ai_trace_enabled', $val);
      $newValues['ai_trace_enabled'] = $val;
    }

    if ($request->has_param('ai_debug_enabled')) {
      $val = (bool) $request->get_param('ai_debug_enabled');
      $oldValues['ai_debug_enabled'] = (bool) get_option('fswa_ai_debug', AgentTraceLog::DEFAULT_ENABLED);
      update_option('fswa_ai_debug', $val ? 1 : 0, false);
      $newValues['ai_debug_enabled'] = $val;
    }

    if ($request->has_param('mcp_expose_writes')) {
      $val = (bool) $request->get_param('mcp_expose_writes');
      $oldValues['mcp_expose_writes'] = (bool) get_option(AbilityRegistrar::OPTION_EXPOSE_WRITES, true);
      update_option(AbilityRegistrar::OPTION_EXPOSE_WRITES, $val);
      $newValues['mcp_expose_writes'] = $val;
    }

    // Turning this on makes a full-scope API token able to run PHP on this
    // site, so it is only ever changed by an admin session that could do that
    // anyway — never by a token.
    if ($request->has_param('glue_token_writes') && current_user_can('manage_options')) {
      $val = (bool) $request->get_param('glue_token_writes');
      $oldValues['glue_token_writes'] = (new GluePermissions())->tokenWritesEnabled();
      update_option(GluePermissions::OPTION_TOKEN_WRITES, $val);
      $newValues['glue_token_writes'] = $val;
    }

    if (!empty($newValues)) {
      (new ActivityLogService())->log('settings.updated', 'settings', null, null, ['old' => $oldValues, 'new' => $newValues]);
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
   *
   * The ZIP is built in the plugin's private folder, sent in this response and
   * deleted. Until 3.4.3 it was left in uploads and its URL returned, which
   * made every archived log a public file.
   */
  public function downloadArchive($request) {
    // Raw log rows leave the site here, so this is for an administrator in the
    // browser only. An application password or API token gets its log data
    // through the routes that scrub it (see RestPayloadRedaction).
    if (!RestPayloadRedaction::isAdminScreen($request)) {
      return new WP_Error(
        'rest_forbidden',
        __('The log archive can only be downloaded from the settings screen.', 'flowsystems-webhook-actions'),
        ['status' => 403]
      );
    }

    $archiver = new LogArchiver();
    $zipPath = $archiver->createArchiveZip();

    if (!$zipPath || !file_exists($zipPath)) {
      return new WP_Error(
        'rest_archive_not_found',
        __('No archive files found or failed to create ZIP.', 'flowsystems-webhook-actions'),
        ['status' => 404]
      );
    }

    // Deleted even when the browser drops the connection halfway through.
    ignore_user_abort(true);
    register_shutdown_function(static function () use ($zipPath): void {
      if (file_exists($zipPath)) {
        wp_delete_file($zipPath);
      }
    });

    while (ob_get_level() > 0) {
      ob_end_clean();
    }

    nocache_headers();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="fswa-logs-archive-' . gmdate('Y-m-d-His') . '.zip"');
    header('Content-Length: ' . (string) filesize($zipPath));
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow', true);

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streams the file; WP_Filesystem would load the whole archive into memory.
    readfile($zipPath);
    wp_delete_file($zipPath);
    exit;
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
