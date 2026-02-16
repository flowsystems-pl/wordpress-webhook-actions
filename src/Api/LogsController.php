<?php

namespace FlowSystems\WebhookActions\Api;

defined('ABSPATH') || exit;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use FlowSystems\WebhookActions\Repositories\LogRepository;

class LogsController extends WP_REST_Controller {
  protected $namespace = 'fswa/v1';
  protected $rest_base = 'logs';

  private LogRepository $repository;

  public function __construct() {
    $this->repository = new LogRepository();
  }

  /**
   * Register routes
   */
  public function registerRoutes(): void {
    // Global logs
    register_rest_route($this->namespace, '/' . $this->rest_base, [
      [
        'methods' => WP_REST_Server::READABLE,
        'callback' => [$this, 'getItems'],
        'permission_callback' => [$this, 'getItemsPermissionsCheck'],
        'args' => $this->getCollectionParams(),
      ],
      [
        'methods' => WP_REST_Server::DELETABLE,
        'callback' => [$this, 'deleteItems'],
        'permission_callback' => [$this, 'deleteItemsPermissionsCheck'],
        'args' => [
          'older_than_days' => [
            'description' => __('Delete logs older than specified days.', 'flowsystems-webhook-actions'),
            'type' => 'integer',
            'minimum' => 1,
          ],
        ],
      ],
    ]);

    // Single log
    register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)', [
      [
        'methods' => WP_REST_Server::READABLE,
        'callback' => [$this, 'getItem'],
        'permission_callback' => [$this, 'getItemPermissionsCheck'],
      ],
      [
        'methods' => WP_REST_Server::DELETABLE,
        'callback' => [$this, 'deleteItem'],
        'permission_callback' => [$this, 'deleteItemPermissionsCheck'],
      ],
    ]);

    // Stats
    register_rest_route($this->namespace, '/' . $this->rest_base . '/stats', [
      'methods' => WP_REST_Server::READABLE,
      'callback' => [$this, 'getStats'],
      'permission_callback' => [$this, 'getItemsPermissionsCheck'],
      'args' => [
        'days' => [
          'description' => __('Number of days to look back.', 'flowsystems-webhook-actions'),
          'type' => 'integer',
          'default' => 7,
          'minimum' => 1,
          'maximum' => 365,
        ],
        'webhook_id' => [
          'description' => __('Filter by webhook ID.', 'flowsystems-webhook-actions'),
          'type' => 'integer',
        ],
      ],
    ]);

    // Webhook-specific logs
    register_rest_route($this->namespace, '/webhooks/(?P<webhook_id>[\d]+)/logs', [
      'methods' => WP_REST_Server::READABLE,
      'callback' => [$this, 'getWebhookLogs'],
      'permission_callback' => [$this, 'getItemsPermissionsCheck'],
      'args' => $this->getCollectionParams(),
    ]);
  }

  /**
   * Check permissions for getting items
   */
  public function getItemsPermissionsCheck($request): bool {
    return current_user_can('manage_options');
  }

  /**
   * Check permissions for getting single item
   */
  public function getItemPermissionsCheck($request): bool {
    return current_user_can('manage_options');
  }

  /**
   * Check permissions for deleting items
   */
  public function deleteItemsPermissionsCheck($request): bool {
    return current_user_can('manage_options');
  }

  /**
   * Check permissions for deleting single item
   */
  public function deleteItemPermissionsCheck($request): bool {
    return current_user_can('manage_options');
  }

  /**
   * Get paginated logs
   */
  public function getItems($request): WP_REST_Response {
    $filters = [];

    if ($request->get_param('webhook_id')) {
      $filters['webhook_id'] = (int) $request->get_param('webhook_id');
    }

    if ($request->get_param('status')) {
      $filters['status'] = sanitize_text_field($request->get_param('status'));
    }

    if ($request->get_param('trigger_name')) {
      $filters['trigger_name'] = sanitize_text_field($request->get_param('trigger_name'));
    }

    if ($request->get_param('date_from')) {
      $filters['date_from'] = sanitize_text_field($request->get_param('date_from'));
    }

    if ($request->get_param('date_to')) {
      $filters['date_to'] = sanitize_text_field($request->get_param('date_to'));
    }

    $page = (int) ($request->get_param('page') ?: 1);
    $perPage = (int) ($request->get_param('per_page') ?: 20);

    $result = $this->repository->getPaginated($filters, $page, $perPage);

    $response = rest_ensure_response($result['items']);

    $response->header('X-WP-Total', $result['total']);
    $response->header('X-WP-TotalPages', $result['pages']);

    return $response;
  }

  /**
   * Get a single log
   */
  public function getItem($request) {
    $id = (int) $request->get_param('id');
    $log = $this->repository->find($id);

    if (!$log) {
      return new WP_Error(
        'rest_log_not_found',
        __('Log not found.', 'flowsystems-webhook-actions'),
        ['status' => 404]
      );
    }

    return rest_ensure_response($log);
  }

  /**
   * Delete a single log
   */
  public function deleteItem($request) {
    $id = (int) $request->get_param('id');
    $log = $this->repository->find($id);

    if (!$log) {
      return new WP_Error(
        'rest_log_not_found',
        __('Log not found.', 'flowsystems-webhook-actions'),
        ['status' => 404]
      );
    }

    $result = $this->repository->delete($id);

    if (!$result) {
      return new WP_Error(
        'rest_log_delete_failed',
        __('Failed to delete log.', 'flowsystems-webhook-actions'),
        ['status' => 500]
      );
    }

    return rest_ensure_response(['deleted' => true, 'id' => $id]);
  }

  /**
   * Bulk delete logs
   */
  public function deleteItems($request) {
    $days = $request->get_param('older_than_days');

    if (!$days) {
      return new WP_Error(
        'rest_missing_param',
        __('Parameter older_than_days is required.', 'flowsystems-webhook-actions'),
        ['status' => 400]
      );
    }

    $date = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));
    $deleted = $this->repository->deleteOlderThan($date);

    return rest_ensure_response([
      'deleted' => $deleted,
      'older_than' => $date,
    ]);
  }

  /**
   * Get logs for a specific webhook
   */
  public function getWebhookLogs($request): WP_REST_Response {
    $webhookId = (int) $request->get_param('webhook_id');
    $page = (int) ($request->get_param('page') ?: 1);
    $perPage = (int) ($request->get_param('per_page') ?: 20);

    $result = $this->repository->getByWebhook($webhookId, $page, $perPage);

    $response = rest_ensure_response($result['items']);

    $response->header('X-WP-Total', $result['total']);
    $response->header('X-WP-TotalPages', $result['pages']);

    return $response;
  }

  /**
   * Get log statistics
   */
  public function getStats($request): WP_REST_Response {
    $days = (int) ($request->get_param('days') ?: 7);
    $webhookId = $request->get_param('webhook_id') ? (int) $request->get_param('webhook_id') : null;

    $stats = $this->repository->getStats($webhookId, $days);

    return rest_ensure_response($stats);
  }

  /**
   * Get collection params
   */
  public function getCollectionParams(): array {
    return [
      'page' => [
        'description' => __('Current page of results.', 'flowsystems-webhook-actions'),
        'type' => 'integer',
        'default' => 1,
        'minimum' => 1,
      ],
      'per_page' => [
        'description' => __('Number of results per page.', 'flowsystems-webhook-actions'),
        'type' => 'integer',
        'default' => 20,
        'minimum' => 1,
        'maximum' => 100,
      ],
      'webhook_id' => [
        'description' => __('Filter by webhook ID.', 'flowsystems-webhook-actions'),
        'type' => 'integer',
      ],
      'status' => [
        'description' => __('Filter by status.', 'flowsystems-webhook-actions'),
        'type' => 'string',
        'enum' => ['success', 'error', 'retry', 'pending'],
      ],
      'trigger_name' => [
        'description' => __('Filter by trigger name.', 'flowsystems-webhook-actions'),
        'type' => 'string',
      ],
      'date_from' => [
        'description' => __('Filter logs from this date.', 'flowsystems-webhook-actions'),
        'type' => 'string',
        'format' => 'date-time',
      ],
      'date_to' => [
        'description' => __('Filter logs until this date.', 'flowsystems-webhook-actions'),
        'type' => 'string',
        'format' => 'date-time',
      ],
    ];
  }
}
