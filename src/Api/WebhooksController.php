<?php

namespace FlowSystems\WebhookActions\Api;

defined('ABSPATH') || exit;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use FlowSystems\WebhookActions\Repositories\WebhookRepository;
use FlowSystems\WebhookActions\Api\AuthHelper;

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
      'permission_callback' => [$this, 'toggleItemPermissionsCheck'],
      'args' => [
        'id' => [
          'description' => __('Unique identifier for the webhook.', 'flowsystems-webhook-actions'),
          'type' => 'integer',
        ],
      ],
    ]);
  }

  public function getItemsPermissionsCheck($request): bool|WP_Error {
    return AuthHelper::dualAuth($request, AuthHelper::SCOPE_READ);
  }

  public function getItemPermissionsCheck($request): bool|WP_Error {
    return AuthHelper::dualAuth($request, AuthHelper::SCOPE_READ);
  }

  public function createItemPermissionsCheck($request): bool|WP_Error {
    return AuthHelper::dualAuth($request, AuthHelper::SCOPE_FULL);
  }

  public function updateItemPermissionsCheck($request): bool|WP_Error {
    return AuthHelper::dualAuth($request, AuthHelper::SCOPE_FULL);
  }

  public function toggleItemPermissionsCheck($request): bool|WP_Error {
    return AuthHelper::dualAuth($request, AuthHelper::SCOPE_OPERATIONAL);
  }

  public function deleteItemPermissionsCheck($request): bool|WP_Error {
    return AuthHelper::dualAuth($request, AuthHelper::SCOPE_FULL);
  }

  /**
   * Strip auth_header for non-full-scope callers.
   */
  private function prepareWebhook(array $webhook, WP_REST_Request $request): array {
    if (!AuthHelper::requestHasScope($request, AuthHelper::SCOPE_FULL)) {
      $webhook['auth_header'] = __('You don\'t have permissions to see it.', 'flowsystems-webhook-actions');
    }
    return $webhook;
  }

  /**
   * Get all webhooks
   */
  public function getItems($request): WP_REST_Response {
    $webhooks = $this->repository->getAll();
    $webhooks = array_map(fn($w) => $this->prepareWebhook($w, $request), $webhooks);

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

    return rest_ensure_response($this->prepareWebhook($webhook, $request));
  }

  /**
   * Create a webhook
   */
  public function createItem($request) {
    $data = [
      'name' => sanitize_text_field($request->get_param('name')),
      'endpoint_url' => esc_url_raw($request->get_param('endpoint_url')),
      'auth_header' => sanitize_text_field($request->get_param('auth_header') ?? ''),
      'is_enabled'  => (bool) $request->get_param('is_enabled'),
      'triggers'    => $request->get_param('triggers') ?? [],
      'conditions'  => $this->sanitizeConditions($request->get_param('conditions')),
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

    return rest_ensure_response($this->prepareWebhook($webhook, $request));
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

    if ($request->has_param('conditions')) {
      $data['conditions'] = $this->sanitizeConditions($request->get_param('conditions'));
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

    return rest_ensure_response($this->prepareWebhook($webhook, $request));
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

    return rest_ensure_response($this->prepareWebhook($webhook, $request));
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
   * Sanitize and validate a conditions payload from the request.
   *
   * @param mixed $raw
   * @return array|null
   */
  private function sanitizeConditions(mixed $raw): ?array {
    if ($raw === null || !is_array($raw)) {
      return null;
    }

    $allowedOperators = [
      'equals', 'not_equals', 'contains', 'not_contains',
      'greater_than', 'less_than', 'is_empty', 'is_not_empty',
      'is_true', 'is_false',
    ];

    $sanitized = [
      'enabled' => (bool) ($raw['enabled'] ?? false),
      'type'    => in_array($raw['type'] ?? 'and', ['and', 'or'], true)
        ? $raw['type']
        : 'and',
      'rules'   => [],
    ];

    foreach ((array) ($raw['rules'] ?? []) as $rule) {
      if (empty($rule['field']) || empty($rule['operator'])) {
        continue;
      }
      if (!in_array($rule['operator'], $allowedOperators, true)) {
        continue;
      }
      $sanitized['rules'][] = [
        'field'    => sanitize_text_field($rule['field']),
        'operator' => $rule['operator'],
        'value'    => isset($rule['value']) ? sanitize_text_field((string) $rule['value']) : '',
      ];
    }

    return $sanitized;
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
        'conditions' => [
          'description' => __('Conditional dispatch rules.', 'flowsystems-webhook-actions'),
          'type'        => ['object', 'null'],
          'context'     => ['view', 'edit'],
          'properties'  => [
            'enabled' => ['type' => 'boolean'],
            'type'    => ['type' => 'string', 'enum' => ['and', 'or']],
            'rules'   => [
              'type'  => 'array',
              'items' => [
                'type'       => 'object',
                'properties' => [
                  'field'    => ['type' => 'string'],
                  'operator' => ['type' => 'string'],
                  'value'    => ['type' => 'string'],
                ],
              ],
            ],
          ],
        ],
      ],
    ];

    return $this->add_additional_fields_schema($this->schema);
  }
}
