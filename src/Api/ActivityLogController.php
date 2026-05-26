<?php

namespace FlowSystems\WebhookActions\Api;

defined('ABSPATH') || exit;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use FlowSystems\WebhookActions\Repositories\ActivityLogRepository;
use FlowSystems\WebhookActions\Api\AuthHelper;

class ActivityLogController extends WP_REST_Controller {
  protected $namespace = 'fswa/v1';
  protected $rest_base = 'activity';

  private ActivityLogRepository $repository;

  public function __construct() {
    $this->repository = new ActivityLogRepository();
  }

  public function registerRoutes(): void {
    register_rest_route($this->namespace, '/' . $this->rest_base, [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [$this, 'getItems'],
        'permission_callback' => [$this, 'readPermissionsCheck'],
        'args'                => $this->getCollectionParams(),
      ],
      [
        'methods'             => WP_REST_Server::DELETABLE,
        'callback'            => [$this, 'deleteItems'],
        'permission_callback' => [$this, 'fullPermissionsCheck'],
        'args'                => [
          'older_than_days' => [
            'description' => __('Delete entries older than specified days.', 'flowsystems-webhook-actions'),
            'type'        => 'integer',
            'minimum'     => 0,
          ],
        ],
      ],
    ]);

    register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [$this, 'getItem'],
        'permission_callback' => [$this, 'readPermissionsCheck'],
        'args'                => [
          'id' => [
            'description' => __('Activity log entry ID.', 'flowsystems-webhook-actions'),
            'type'        => 'integer',
          ],
        ],
      ],
    ]);
  }

  public function readPermissionsCheck($request): bool|WP_Error {
    return AuthHelper::dualAuth($request, AuthHelper::SCOPE_READ);
  }

  public function fullPermissionsCheck($request): bool|WP_Error {
    return AuthHelper::dualAuth($request, AuthHelper::SCOPE_FULL);
  }

  public function getItems(WP_REST_Request $request): WP_REST_Response {
    $filters = [];

    if ($request->get_param('action')) {
      $filters['action'] = sanitize_text_field($request->get_param('action'));
    }

    if ($request->get_param('action_prefix')) {
      $filters['action_prefix'] = sanitize_text_field($request->get_param('action_prefix'));
    }

    if ($request->get_param('user_id')) {
      $filters['user_id'] = (int) $request->get_param('user_id');
    }

    if ($request->get_param('object_type')) {
      $filters['object_type'] = sanitize_text_field($request->get_param('object_type'));
    }

    if ($request->get_param('object_id')) {
      $filters['object_id'] = (int) $request->get_param('object_id');
    }

    if ($request->get_param('date_from')) {
      $filters['date_from'] = sanitize_text_field($request->get_param('date_from'));
    }

    if ($request->get_param('date_to')) {
      $filters['date_to'] = sanitize_text_field($request->get_param('date_to'));
    }

    $page    = max(1, (int) ($request->get_param('page') ?? 1));
    $perPage = max(1, min(100, (int) ($request->get_param('per_page') ?? 20)));

    $result = $this->repository->getPaginated($filters, $page, $perPage);

    $response = rest_ensure_response($result['items']);
    $response->header('X-WP-Total',      (string) $result['total']);
    $response->header('X-WP-TotalPages', (string) $result['pages']);

    return $response;
  }

  public function getItem(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $id    = (int) $request->get_param('id');
    $entry = $this->repository->find($id);

    if (!$entry) {
      return new WP_Error(
        'rest_activity_not_found',
        __('Activity log entry not found.', 'flowsystems-webhook-actions'),
        ['status' => 404]
      );
    }

    return rest_ensure_response($entry);
  }

  public function deleteItems(WP_REST_Request $request): WP_REST_Response {
    $days   = $request->has_param('older_than_days') ? (int) $request->get_param('older_than_days') : null;
    $cutoff = $days !== null
      ? gmdate('Y-m-d H:i:s', strtotime("-{$days} days"))
      : gmdate('Y-m-d H:i:s');

    global $wpdb;
    $table = $wpdb->prefix . 'fswa_activity_logs';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE created_at < %s", $cutoff));
    $deleted = (int) $wpdb->rows_affected;

    return rest_ensure_response(['deleted' => $deleted]);
  }

  private function getCollectionParams(): array {
    return [
      'page'          => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
      'per_page'      => ['type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100],
      'action'        => ['type' => 'string', 'description' => __('Filter by exact action string.', 'flowsystems-webhook-actions')],
      'action_prefix' => ['type' => 'string', 'description' => __('Filter by action prefix (e.g. "webhook").', 'flowsystems-webhook-actions')],
      'user_id'       => ['type' => 'integer'],
      'object_type'   => ['type' => 'string'],
      'object_id'     => ['type' => 'integer'],
      'date_from'     => ['type' => 'string', 'description' => __('Filter from UTC datetime (Y-m-d H:i:s).', 'flowsystems-webhook-actions')],
      'date_to'       => ['type' => 'string', 'description' => __('Filter until UTC datetime (Y-m-d H:i:s).', 'flowsystems-webhook-actions')],
    ];
  }
}
