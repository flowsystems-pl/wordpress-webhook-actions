<?php

namespace FlowSystems\WebhookActions\Api;

defined('ABSPATH') || exit;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use FlowSystems\WebhookActions\Repositories\WebhookRepository;

class WebhooksController extends WP_REST_Controller {
  protected $namespace = 'fswa/v1';
  protected $rest_base = 'webhooks';

  private WebhookRepository $repository;

  public function __construct() {
    $this->repository = new WebhookRepository();
  }

  /**
   * Register routes
   */
  public function registerRoutes(): void {
    register_rest_route($this->namespace, '/' . $this->rest_base, [
      [
        'methods' => WP_REST_Server::READABLE,
        'callback' => [$this, 'getItems'],
        'permission_callback' => [$this, 'getItemsPermissionsCheck'],
        'args' => $this->get_collection_params(),
      ],
      [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => [$this, 'createItem'],
        'permission_callback' => [$this, 'createItemPermissionsCheck'],
        'args' => $this->get_endpoint_args_for_item_schema(WP_REST_Server::CREATABLE),
      ],
      'schema' => [$this, 'get_public_item_schema'],
    ]);

    register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)', [
      [
        'methods' => WP_REST_Server::READABLE,
        'callback' => [$this, 'getItem'],
        'permission_callback' => [$this, 'getItemPermissionsCheck'],
        'args' => [
          'id' => [
            'description' => __('Unique identifier for the webhook.', 'flowsystems-webhook-actions'),
            'type' => 'integer',
          ],
        ],
      ],
      [
        'methods' => WP_REST_Server::EDITABLE,
        'callback' => [$this, 'updateItem'],
        'permission_callback' => [$this, 'updateItemPermissionsCheck'],
        'args' => $this->get_endpoint_args_for_item_schema(WP_REST_Server::EDITABLE),
      ],
      [
        'methods' => WP_REST_Server::DELETABLE,
        'callback' => [$this, 'deleteItem'],
        'permission_callback' => [$this, 'deleteItemPermissionsCheck'],
      ],
      'schema' => [$this, 'get_public_item_schema'],
    ]);

    register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)/toggle', [
      'methods' => WP_REST_Server::CREATABLE,
      'callback' => [$this, 'toggleItem'],
      'permission_callback' => [$this, 'updateItemPermissionsCheck'],
      'args' => [
        'id' => [
          'description' => __('Unique identifier for the webhook.', 'flowsystems-webhook-actions'),
          'type' => 'integer',
        ],
      ],
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
   * Check permissions for creating items
   */
  public function createItemPermissionsCheck($request): bool {
    return current_user_can('manage_options');
  }

  /**
   * Check permissions for updating items
   */
  public function updateItemPermissionsCheck($request): bool {
    return current_user_can('manage_options');
  }

  /**
   * Check permissions for deleting items
   */
  public function deleteItemPermissionsCheck($request): bool {
    return current_user_can('manage_options');
  }

  /**
   * Get all webhooks
   */
  public function getItems($request): WP_REST_Response {
    $webhooks = $this->repository->getAll();

    return rest_ensure_response($webhooks);
  }

  /**
   * Get a single webhook
   */
  public function getItem($request) {
    $id = (int) $request->get_param('id');
    $webhook = $this->repository->find($id);

    if (!$webhook) {
      return new WP_Error(
        'rest_webhook_not_found',
        __('Webhook not found.', 'flowsystems-webhook-actions'),
        ['status' => 404]
      );
    }

    return rest_ensure_response($webhook);
  }

  /**
   * Create a webhook
   */
  public function createItem($request) {
    $data = [
      'name' => sanitize_text_field($request->get_param('name')),
      'endpoint_url' => esc_url_raw($request->get_param('endpoint_url')),
      'auth_header' => sanitize_text_field($request->get_param('auth_header') ?? ''),
      'is_enabled' => (bool) $request->get_param('is_enabled'),
      'triggers' => $request->get_param('triggers') ?? [],
    ];

    // Validate
    if (empty($data['name'])) {
      return new WP_Error(
        'rest_missing_name',
        __('Webhook name is required.', 'flowsystems-webhook-actions'),
        ['status' => 400]
      );
    }

    if (empty($data['endpoint_url'])) {
      return new WP_Error(
        'rest_missing_endpoint_url',
        __('Endpoint URL is required.', 'flowsystems-webhook-actions'),
        ['status' => 400]
      );
    }

    if (!filter_var($data['endpoint_url'], FILTER_VALIDATE_URL)) {
      return new WP_Error(
        'rest_invalid_endpoint_url',
        __('Invalid endpoint URL.', 'flowsystems-webhook-actions'),
        ['status' => 400]
      );
    }

    // Sanitize triggers
    $data['triggers'] = array_map('sanitize_text_field', $data['triggers']);

    $webhookId = $this->repository->create($data);

    if (!$webhookId) {
      return new WP_Error(
        'rest_webhook_create_failed',
        __('Failed to create webhook.', 'flowsystems-webhook-actions'),
        ['status' => 500]
      );
    }

    $webhook = $this->repository->find($webhookId);

    return rest_ensure_response($webhook);
  }

  /**
   * Update a webhook
   */
  public function updateItem($request) {
    $id = (int) $request->get_param('id');
    $webhook = $this->repository->find($id);

    if (!$webhook) {
      return new WP_Error(
        'rest_webhook_not_found',
        __('Webhook not found.', 'flowsystems-webhook-actions'),
        ['status' => 404]
      );
    }

    $data = [];

    if ($request->has_param('name')) {
      $data['name'] = sanitize_text_field($request->get_param('name'));
    }

    if ($request->has_param('endpoint_url')) {
      $data['endpoint_url'] = esc_url_raw($request->get_param('endpoint_url'));

      if (!filter_var($data['endpoint_url'], FILTER_VALIDATE_URL)) {
        return new WP_Error(
          'rest_invalid_endpoint_url',
          __('Invalid endpoint URL.', 'flowsystems-webhook-actions'),
          ['status' => 400]
        );
      }
    }

    if ($request->has_param('auth_header')) {
      $data['auth_header'] = sanitize_text_field($request->get_param('auth_header'));
    }

    if ($request->has_param('is_enabled')) {
      $data['is_enabled'] = (bool) $request->get_param('is_enabled');
    }

    if ($request->has_param('triggers')) {
      $data['triggers'] = array_map('sanitize_text_field', $request->get_param('triggers'));
    }

    $result = $this->repository->update($id, $data);

    if (!$result) {
      return new WP_Error(
        'rest_webhook_update_failed',
        __('Failed to update webhook.', 'flowsystems-webhook-actions'),
        ['status' => 500]
      );
    }

    $webhook = $this->repository->find($id);

    return rest_ensure_response($webhook);
  }

  /**
   * Delete a webhook
   */
  public function deleteItem($request) {
    $id = (int) $request->get_param('id');
    $webhook = $this->repository->find($id);

    if (!$webhook) {
      return new WP_Error(
        'rest_webhook_not_found',
        __('Webhook not found.', 'flowsystems-webhook-actions'),
        ['status' => 404]
      );
    }

    $result = $this->repository->delete($id);

    if (!$result) {
      return new WP_Error(
        'rest_webhook_delete_failed',
        __('Failed to delete webhook.', 'flowsystems-webhook-actions'),
        ['status' => 500]
      );
    }

    return rest_ensure_response(['deleted' => true, 'id' => $id]);
  }

  /**
   * Toggle webhook enabled status
   */
  public function toggleItem($request): WP_REST_Response|WP_Error {
    $id = (int) $request->get_param('id');
    $webhook = $this->repository->find($id);

    if (!$webhook) {
      return new WP_Error(
        'rest_webhook_not_found',
        __('Webhook not found.', 'flowsystems-webhook-actions'),
        ['status' => 404]
      );
    }

    $newStatus = !$webhook['is_enabled'];
    $result = $this->repository->setEnabled($id, $newStatus);

    if (!$result) {
      return new WP_Error(
        'rest_webhook_toggle_failed',
        __('Failed to toggle webhook status.', 'flowsystems-webhook-actions'),
        ['status' => 500]
      );
    }

    $webhook = $this->repository->find($id);

    return rest_ensure_response($webhook);
  }

  /**
   * Get collection params
   */
  public function getCollectionParams(): array {
    return [
      'only_enabled' => [
        'description' => __('Only return enabled webhooks.', 'flowsystems-webhook-actions'),
        'type' => 'boolean',
        'default' => false,
      ],
    ];
  }

  /**
   * Get item schema
   */
  public function getItemSchema(): array {
    if ($this->schema) {
      return $this->add_additional_fields_schema($this->schema);
    }

    $this->schema = [
      '$schema' => 'http://json-schema.org/draft-04/schema#',
      'title' => 'webhook',
      'type' => 'object',
      'properties' => [
        'id' => [
          'description' => __('Unique identifier for the webhook.', 'flowsystems-webhook-actions'),
          'type' => 'integer',
          'context' => ['view', 'edit'],
          'readonly' => true,
        ],
        'name' => [
          'description' => __('Name of the webhook.', 'flowsystems-webhook-actions'),
          'type' => 'string',
          'context' => ['view', 'edit'],
          'required' => true,
        ],
        'endpoint_url' => [
          'description' => __('URL to send the webhook to.', 'flowsystems-webhook-actions'),
          'type' => 'string',
          'format' => 'uri',
          'context' => ['view', 'edit'],
          'required' => true,
        ],
        'auth_header' => [
          'description' => __('Authorization header value.', 'flowsystems-webhook-actions'),
          'type' => 'string',
          'context' => ['view', 'edit'],
        ],
        'is_enabled' => [
          'description' => __('Whether the webhook is enabled.', 'flowsystems-webhook-actions'),
          'type' => 'boolean',
          'context' => ['view', 'edit'],
          'default' => true,
        ],
        'triggers' => [
          'description' => __('List of trigger actions.', 'flowsystems-webhook-actions'),
          'type' => 'array',
          'items' => ['type' => 'string'],
          'context' => ['view', 'edit'],
        ],
        'created_at' => [
          'description' => __('Creation date.', 'flowsystems-webhook-actions'),
          'type' => 'string',
          'format' => 'date-time',
          'context' => ['view'],
          'readonly' => true,
        ],
        'updated_at' => [
          'description' => __('Last update date.', 'flowsystems-webhook-actions'),
          'type' => 'string',
          'format' => 'date-time',
          'context' => ['view'],
          'readonly' => true,
        ],
      ],
    ];

    return $this->add_additional_fields_schema($this->schema);
  }
}
