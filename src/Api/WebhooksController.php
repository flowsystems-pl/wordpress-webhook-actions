<?php

namespace FlowSystems\WebhookActions\Api;

defined('ABSPATH') || exit;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use FlowSystems\WebhookActions\Repositories\WebhookRepository;
use FlowSystems\WebhookActions\Repositories\SchemaRepository;
use FlowSystems\WebhookActions\Services\QueueService;
use FlowSystems\WebhookActions\Services\LogService;
use FlowSystems\WebhookActions\Services\PayloadTransformer;
use FlowSystems\WebhookActions\Services\Dispatcher;
use FlowSystems\WebhookActions\Services\WPHttpTransport;
use FlowSystems\WebhookActions\Api\AuthHelper;

class WebhooksController extends WP_REST_Controller {
  protected $namespace = 'fswa/v1';
  protected $rest_base = 'webhooks';

  private WebhookRepository $repository;
  private SchemaRepository $schemaRepository;
  private QueueService $queueService;
  private LogService $logService;
  private PayloadTransformer $payloadTransformer;

  public function __construct() {
    $this->repository         = new WebhookRepository();
    $this->schemaRepository   = new SchemaRepository();
    $this->queueService       = new QueueService();
    $this->logService         = new LogService();
    $this->payloadTransformer = new PayloadTransformer();
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

    register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)/test', [
      'methods'             => WP_REST_Server::CREATABLE,
      'callback'            => [$this, 'testItem'],
      'permission_callback' => [$this, 'updateItemPermissionsCheck'],
      'args' => [
        'id' => [
          'description' => __('Unique identifier for the webhook.', 'flowsystems-webhook-actions'),
          'type'        => 'integer',
        ],
        'payload_source' => [
          'description' => __('Where to source the test payload from.', 'flowsystems-webhook-actions'),
          'type'        => 'string',
          'enum'        => ['captured', 'mapped', 'custom'],
          'default'     => 'captured',
        ],
        'trigger' => [
          'description' => __('Trigger name to use for the test.', 'flowsystems-webhook-actions'),
          'type'        => 'string',
        ],
        'payload' => [
          'description' => __('Custom payload JSON (required when payload_source is custom).', 'flowsystems-webhook-actions'),
          'type'        => 'object',
        ],
        'mode' => [
          'description' => __('How to run the test: now (synchronous) or queue (async).', 'flowsystems-webhook-actions'),
          'type'        => 'string',
          'enum'        => ['now', 'queue'],
          'default'     => 'queue',
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
   * Strip auth_header for non-full-scope callers and allow extensions to append fields.
   */
  private function prepareWebhook(array $webhook, WP_REST_Request $request): array {
    if (!AuthHelper::requestHasScope($request, AuthHelper::SCOPE_FULL)) {
      $webhook['auth_header'] = __('You don\'t have permissions to see it.', 'flowsystems-webhook-actions');
    }

    /**
     * Filter webhook data before it is returned in a REST response.
     * Extensions can append or transform fields here.
     *
     * @param array           $webhook  The webhook data array.
     * @param WP_REST_Request $request  The current REST request.
     */
    return apply_filters('fswa_webhook_data', $webhook, $request);
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
      'name'           => sanitize_text_field($request->get_param('name')),
      'endpoint_url'   => esc_url_raw($request->get_param('endpoint_url')),
      'auth_header'    => sanitize_text_field($request->get_param('auth_header') ?? ''),
      'is_enabled'     => (bool) $request->get_param('is_enabled'),
      'triggers'       => $request->get_param('triggers') ?? [],
      'http_method'    => strtoupper(sanitize_text_field($request->get_param('http_method') ?? 'POST')),
      'custom_headers' => $this->sanitizeKvArray($request->get_param('custom_headers') ?? []),
      'url_params'     => $this->sanitizeKvArray($request->get_param('url_params') ?? []),
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

    /**
     * Fires after a webhook is created. Extensions can persist additional fields.
     *
     * @param int             $webhookId The newly created webhook ID.
     * @param WP_REST_Request $request   The current REST request.
     */
    do_action('fswa_webhook_saved', $webhookId, $request);

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

    if ($request->has_param('http_method')) {
      $data['http_method'] = strtoupper(sanitize_text_field($request->get_param('http_method')));
    }

    if ($request->has_param('custom_headers')) {
      $data['custom_headers'] = $this->sanitizeKvArray($request->get_param('custom_headers') ?? []);
    }

    if ($request->has_param('url_params')) {
      $data['url_params'] = $this->sanitizeKvArray($request->get_param('url_params') ?? []);
    }

    $result = $this->repository->update($id, $data);

    if (!$result) {
      return new WP_Error(
        'rest_webhook_update_failed',
        __('Failed to update webhook.', 'flowsystems-webhook-actions'),
        ['status' => 500]
      );
    }

    /**
     * Fires after a webhook is updated. Extensions can persist additional fields.
     *
     * @param int             $id      The webhook ID.
     * @param WP_REST_Request $request The current REST request.
     */
    do_action('fswa_webhook_saved', $id, $request);

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
   * Enqueue a test dispatch for a webhook with a chosen payload source.
   */
  public function testItem($request): WP_REST_Response|WP_Error {
    $id = (int) $request->get_param('id');
    $webhook = $this->repository->find($id);

    if (!$webhook) {
      return new WP_Error(
        'rest_webhook_not_found',
        __('Webhook not found.', 'flowsystems-webhook-actions'),
        ['status' => 404]
      );
    }

    $triggers = $webhook['triggers'] ?? [];
    if (empty($triggers)) {
      return new WP_Error(
        'rest_webhook_no_triggers',
        __('Webhook has no triggers configured.', 'flowsystems-webhook-actions'),
        ['status' => 422]
      );
    }

    // Resolve trigger to use
    $requestedTrigger = $request->get_param('trigger');
    if ($requestedTrigger && in_array($requestedTrigger, $triggers, true)) {
      $trigger = sanitize_text_field($requestedTrigger);
    } else {
      $trigger = $triggers[0];
    }

    $source = $request->get_param('payload_source') ?? 'captured';

    switch ($source) {
      case 'custom':
        $raw = $request->get_param('payload');
        if (empty($raw) || !is_array($raw)) {
          return new WP_Error(
            'rest_missing_payload',
            __('A payload object is required for custom source.', 'flowsystems-webhook-actions'),
            ['status' => 400]
          );
        }
        $testPayload     = $raw;
        $mappingApplied  = false;
        $originalPayload = null;
        break;

      case 'mapped':
        $schema = $this->schemaRepository->findByWebhookAndTrigger($id, $trigger);
        $example = $schema ? ($schema['example_payload'] ?? null) : null;
        if (empty($example)) {
          return new WP_Error(
            'rest_no_captured_payload',
            __('No captured payload found for this trigger. Fire the trigger at least once first.', 'flowsystems-webhook-actions'),
            ['status' => 422]
          );
        }
        $decoded = is_string($example) ? json_decode($example, true) : $example;
        $mapped  = $this->payloadTransformer->applyStoredMapping($id, $trigger, $decoded ?? []);
        $testPayload     = $mapped['payload'];
        $mappingApplied  = $mapped['mapping_applied'];
        $originalPayload = $mappingApplied ? $decoded : null;
        break;

      case 'captured':
      default:
        $schema = $this->schemaRepository->findByWebhookAndTrigger($id, $trigger);
        $example = $schema ? ($schema['example_payload'] ?? null) : null;
        if (empty($example)) {
          return new WP_Error(
            'rest_no_captured_payload',
            __('No captured payload found for this trigger. Fire the trigger at least once first.', 'flowsystems-webhook-actions'),
            ['status' => 422]
          );
        }
        $testPayload     = is_string($example) ? json_decode($example, true) : $example;
        $mappingApplied  = false;
        $originalPayload = null;
        break;
    }

    $mode  = $request->get_param('mode') ?? 'queue';
    $logId = $this->logService->logPending($id, $trigger, $testPayload, $originalPayload, $mappingApplied);

    if ($mode === 'now') {
      $dispatcher = new Dispatcher(new WPHttpTransport(), $this->queueService);
      $dispatcher->sendToWebhook($webhook, $testPayload, $trigger, $logId, 0, true, $originalPayload ?? null);

      $log = $this->logService->getRepository()->find($logId);

      return rest_ensure_response([
        'mode'   => 'now',
        'log_id' => $logId,
        'log'    => $log,
      ]);
    }

    $jobId = $this->queueService->enqueue(
      $id,
      $trigger,
      [
        'webhook'          => $webhook,
        'payload'          => $testPayload,
        'log_id'           => $logId,
        'mapping_applied'  => $mappingApplied,
        'original_payload' => $originalPayload,
      ],
      null,
      $logId ?: null,
      true
    );

    return rest_ensure_response([
      'mode'   => 'queue',
      'job_id' => $jobId,
      'log_id' => $logId,
    ]);
  }

  private function sanitizeKvArray(array $pairs): array {
    return array_values(array_filter(
      array_map(function ($pair) {
        if (!is_array($pair) || empty($pair['key'])) return null;
        return [
          'key'   => sanitize_text_field($pair['key']),
          'value' => sanitize_text_field($pair['value'] ?? ''),
        ];
      }, $pairs)
    ));
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
        'http_method' => [
          'description' => __('HTTP method used for delivery.', 'flowsystems-webhook-actions'),
          'type'        => 'string',
          'enum'        => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
          'default'     => 'POST',
          'context'     => ['view', 'edit'],
        ],
        'custom_headers' => [
          'description' => __('Extra request headers as key-value pairs.', 'flowsystems-webhook-actions'),
          'type'        => 'array',
          'context'     => ['view', 'edit'],
          'items'       => [
            'type'       => 'object',
            'properties' => [
              'key'   => ['type' => 'string'],
              'value' => ['type' => 'string'],
            ],
          ],
        ],
        'url_params' => [
          'description' => __('Query parameters appended to the URL.', 'flowsystems-webhook-actions'),
          'type'        => 'array',
          'context'     => ['view', 'edit'],
          'items'       => [
            'type'       => 'object',
            'properties' => [
              'key'   => ['type' => 'string'],
              'value' => ['type' => 'string'],
            ],
          ],
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
