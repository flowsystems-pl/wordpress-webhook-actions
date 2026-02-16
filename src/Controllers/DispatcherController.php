<?php

namespace FlowSystems\WebhookActions\Controllers;

defined('ABSPATH') || exit;

use WP_REST_Server;
use WP_REST_Response;
use WP_REST_Request;
use WP_Error;
use FlowSystems\WebhookActions\Services\Dispatcher;
use FlowSystems\WebhookActions\Services\HooksHandler;
use FlowSystems\WebhookActions\Services\WPHttpTransport;
use FlowSystems\WebhookActions\Services\QueueService;

class DispatcherController {

  private Dispatcher $dispatcher;
  private QueueService $queueService;

  public function __construct() {
    $transport = new WPHttpTransport();
    $this->queueService = new QueueService();
    $this->dispatcher = new Dispatcher($transport, $this->queueService);

    new HooksHandler($this->dispatcher);

    // Register REST API endpoints
    add_action('rest_api_init', [$this, 'registerRoutes']);

    // Ensure cron secret exists
    $this->ensureCronSecret();
  }

  /**
   * Register REST API routes
   */
  public function registerRoutes(): void {
    // Admin endpoint (requires authentication)
    register_rest_route('fswa/v1', '/dispatcher/process', [
      [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => [$this, 'processQueue'],
        'permission_callback' => [$this, 'permissionsCheck'],
        'args' => [
          'batch_size' => [
            'type' => 'integer',
            'default' => 10,
            'minimum' => 1,
            'maximum' => 100,
          ],
        ],
      ],
    ]);

    // Cron endpoint (uses token authentication)
    register_rest_route('fswa/v1', '/cron/process', [
      [
        'methods' => WP_REST_Server::READABLE, // GET for easy curl usage
        'callback' => [$this, 'cronProcess'],
        'permission_callback' => [$this, 'cronPermissionsCheck'],
        'args' => [
          'token' => [
            'type' => 'string',
            'required' => true,
          ],
          'batch_size' => [
            'type' => 'integer',
            'default' => 10,
            'minimum' => 1,
            'maximum' => 100,
          ],
        ],
      ],
    ]);

    // Endpoint to regenerate cron token
    register_rest_route('fswa/v1', '/cron/regenerate-token', [
      [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => [$this, 'regenerateCronToken'],
        'permission_callback' => [$this, 'permissionsCheck'],
      ],
    ]);

    // Endpoint to get cron info
    register_rest_route('fswa/v1', '/cron/info', [
      [
        'methods' => WP_REST_Server::READABLE,
        'callback' => [$this, 'getCronInfo'],
        'permission_callback' => [$this, 'permissionsCheck'],
      ],
    ]);
  }

  /**
   * Check permissions for admin API access
   */
  public function permissionsCheck(WP_REST_Request $request): bool {
    return current_user_can('manage_options');
  }

  /**
   * Check permissions for cron endpoint (token-based)
   */
  public function cronPermissionsCheck(WP_REST_Request $request): bool|WP_Error {
    $token = $request->get_param('token');
    $storedToken = get_option('fswa_cron_secret');

    if (empty($storedToken)) {
      return new WP_Error(
        'cron_not_configured',
        __('Cron secret not configured.', 'flowsystems-webhook-actions'),
        ['status' => 500]
      );
    }

    if (!hash_equals($storedToken, $token)) {
      return new WP_Error(
        'invalid_token',
        __('Invalid cron token.', 'flowsystems-webhook-actions'),
        ['status' => 403]
      );
    }

    return true;
  }

  /**
   * Manually trigger batch processing (admin endpoint)
   */
  public function processQueue(WP_REST_Request $request): WP_REST_Response {
    $batchSize = (int) $request->get_param('batch_size');

    $result = $this->dispatcher->process($batchSize);

    return rest_ensure_response([
      'success' => true,
      'result' => $result,
      'message' => sprintf(
        // translators: %1$d: total jobs processed, %2$d: succeeded count, %3$d: failed count, %4$d: rescheduled count
        __('Processed %1$d jobs: %2$d succeeded, %3$d failed, %4$d rescheduled.', 'flowsystems-webhook-actions'),
        $result['processed'],
        $result['succeeded'],
        $result['failed'],
        $result['rescheduled']
      ),
    ]);
  }

  /**
   * Process queue via cron endpoint (token-authenticated)
   */
  public function cronProcess(WP_REST_Request $request): WP_REST_Response {
    $batchSize = (int) $request->get_param('batch_size');

    // Record last cron run
    update_option('fswa_last_cron_run', time());

    $result = $this->dispatcher->process($batchSize);

    // Return minimal response for cron
    return rest_ensure_response([
      'ok' => true,
      'processed' => $result['processed'],
      'succeeded' => $result['succeeded'],
      'failed' => $result['failed'],
    ]);
  }

  /**
   * Regenerate the cron secret token
   */
  public function regenerateCronToken(WP_REST_Request $request): WP_REST_Response {
    $newToken = $this->generateCronSecret();
    update_option('fswa_cron_secret', $newToken);

    return rest_ensure_response([
      'success' => true,
      'token' => $newToken,
      'cron_url' => $this->getCronUrl($newToken),
    ]);
  }

  /**
   * Get cron configuration info
   */
  public function getCronInfo(WP_REST_Request $request): WP_REST_Response {
    $token = get_option('fswa_cron_secret', '');
    $lastRun = get_option('fswa_last_cron_run', 0);
    $wpCronDisabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;

    return rest_ensure_response([
      'token' => $token,
      'cron_url' => $this->getCronUrl($token),
      'cron_command' => $this->getCronCommand($token),
      'last_run' => $lastRun ? gmdate('Y-m-d H:i:s', $lastRun) : null,
      'last_run_human' => $lastRun ? human_time_diff($lastRun, time()) . ' ago' : 'Never',
      'wp_cron_disabled' => $wpCronDisabled,
      'wp_cron_next' => wp_next_scheduled('fswa_process_queue'),
    ]);
  }

  /**
   * Get the cron URL
   */
  private function getCronUrl(string $token): string {
    return rest_url('fswa/v1/cron/process') . '?token=' . $token;
  }

  /**
   * Get the cron command for crontab
   */
  private function getCronCommand(string $token): string {
    $url = $this->getCronUrl($token);
    return "*/1 * * * * curl -fsS '{$url}' >/dev/null 2>&1";
  }

  /**
   * Ensure cron secret exists
   */
  private function ensureCronSecret(): void {
    if (!get_option('fswa_cron_secret')) {
      update_option('fswa_cron_secret', $this->generateCronSecret());
    }
  }

  /**
   * Generate a secure cron secret
   */
  private function generateCronSecret(): string {
    return wp_generate_password(32, false, false);
  }

  /**
   * Get the dispatcher instance
   */
  public function getDispatcher(): Dispatcher {
    return $this->dispatcher;
  }

  /**
   * Get the queue service instance
   */
  public function getQueueService(): QueueService {
    return $this->queueService;
  }
}
