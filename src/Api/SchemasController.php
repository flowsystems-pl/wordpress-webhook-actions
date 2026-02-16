<?php

namespace FlowSystems\WebhookActions\Api;

defined('ABSPATH') || exit;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use FlowSystems\WebhookActions\Repositories\SchemaRepository;
use FlowSystems\WebhookActions\Repositories\WebhookRepository;
use FlowSystems\WebhookActions\Services\PayloadTransformer;

class SchemasController extends WP_REST_Controller {
  protected $namespace = 'fswa/v1';
  protected $rest_base = 'schemas';

  private SchemaRepository $schemaRepository;
  private WebhookRepository $webhookRepository;
  private PayloadTransformer $payloadTransformer;

  public function __construct() {
    $this->schemaRepository = new SchemaRepository();
    $this->webhookRepository = new WebhookRepository();
    $this->payloadTransformer = new PayloadTransformer();
  }

  /**
   * Register routes
   */
  public function registerRoutes(): void {
    // Get all schemas for a webhook
    register_rest_route($this->namespace, '/' . $this->rest_base . '/webhook/(?P<id>[\d]+)', [
      [
        'methods' => WP_REST_Server::READABLE,
        'callback' => [$this, 'getWebhookSchemas'],
        'permission_callback' => [$this, 'permissionsCheck'],
        'args' => [
          'id' => [
            'description' => __('Webhook ID.', 'flowsystems-webhook-actions'),
            'type' => 'integer',
            'required' => true,
          ],
        ],
      ],
    ]);

    // Get/Update/Delete schema for specific webhook+trigger
    register_rest_route($this->namespace, '/' . $this->rest_base . '/webhook/(?P<id>[\d]+)/trigger/(?P<trigger>[a-zA-Z0-9_-]+)', [
      [
        'methods' => WP_REST_Server::READABLE,
        'callback' => [$this, 'getSchema'],
        'permission_callback' => [$this, 'permissionsCheck'],
        'args' => [
          'id' => [
            'description' => __('Webhook ID.', 'flowsystems-webhook-actions'),
            'type' => 'integer',
            'required' => true,
          ],
          'trigger' => [
            'description' => __('Trigger name.', 'flowsystems-webhook-actions'),
            'type' => 'string',
            'required' => true,
          ],
        ],
      ],
      [
        'methods' => WP_REST_Server::EDITABLE,
        'callback' => [$this, 'updateSchema'],
        'permission_callback' => [$this, 'permissionsCheck'],
        'args' => [
          'id' => [
            'description' => __('Webhook ID.', 'flowsystems-webhook-actions'),
            'type' => 'integer',
            'required' => true,
          ],
          'trigger' => [
            'description' => __('Trigger name.', 'flowsystems-webhook-actions'),
            'type' => 'string',
            'required' => true,
          ],
          'field_mapping' => [
            'description' => __('Field mapping configuration.', 'flowsystems-webhook-actions'),
            'type' => 'object',
          ],
          'include_user_data' => [
            'description' => __('Whether to include user data.', 'flowsystems-webhook-actions'),
            'type' => 'boolean',
          ],
        ],
      ],
      [
        'methods' => WP_REST_Server::DELETABLE,
        'callback' => [$this, 'deleteSchema'],
        'permission_callback' => [$this, 'permissionsCheck'],
        'args' => [
          'id' => [
            'description' => __('Webhook ID.', 'flowsystems-webhook-actions'),
            'type' => 'integer',
            'required' => true,
          ],
          'trigger' => [
            'description' => __('Trigger name.', 'flowsystems-webhook-actions'),
            'type' => 'string',
            'required' => true,
          ],
        ],
      ],
    ]);

    // Reset capture for specific webhook+trigger
    register_rest_route($this->namespace, '/' . $this->rest_base . '/webhook/(?P<id>[\d]+)/trigger/(?P<trigger>[a-zA-Z0-9_-]+)/capture', [
      [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => [$this, 'resetCapture'],
        'permission_callback' => [$this, 'permissionsCheck'],
        'args' => [
          'id' => [
            'description' => __('Webhook ID.', 'flowsystems-webhook-actions'),
            'type' => 'integer',
            'required' => true,
          ],
          'trigger' => [
            'description' => __('Trigger name.', 'flowsystems-webhook-actions'),
            'type' => 'string',
            'required' => true,
          ],
        ],
      ],
    ]);

    // Get user enrichment triggers info
    register_rest_route($this->namespace, '/' . $this->rest_base . '/user-triggers', [
      [
        'methods' => WP_REST_Server::READABLE,
        'callback' => [$this, 'getUserTriggers'],
        'permission_callback' => [$this, 'permissionsCheck'],
      ],
    ]);
  }

  /**
   * Permission check
   */
  public function permissionsCheck($request): bool {
    return current_user_can('manage_options');
  }

  /**
   * Get all schemas for a webhook
   */
  public function getWebhookSchemas(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $webhookId = (int) $request->get_param('id');

    // Verify webhook exists
    $webhook = $this->webhookRepository->find($webhookId);
    if (!$webhook) {
      return new WP_Error(
        'rest_webhook_not_found',
        __('Webhook not found.', 'flowsystems-webhook-actions'),
        ['status' => 404]
      );
    }

    $schemas = $this->schemaRepository->getByWebhook($webhookId);

    // Enhance with user trigger support info
    $userTriggers = $this->payloadTransformer->getUserEnrichmentTriggers();
    foreach ($schemas as &$schema) {
      $schema['supports_user_enrichment'] = in_array($schema['trigger_name'], $userTriggers, true);
    }

    return rest_ensure_response($schemas);
  }

  /**
   * Get schema for specific webhook+trigger
   */
  public function getSchema(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $webhookId = (int) $request->get_param('id');
    $trigger = sanitize_text_field($request->get_param('trigger'));

    // Verify webhook exists
    $webhook = $this->webhookRepository->find($webhookId);
    if (!$webhook) {
      return new WP_Error(
        'rest_webhook_not_found',
        __('Webhook not found.', 'flowsystems-webhook-actions'),
        ['status' => 404]
      );
    }

    $schema = $this->schemaRepository->findByWebhookAndTrigger($webhookId, $trigger);

    if (!$schema) {
      // Return empty schema structure
      $schema = [
        'webhook_id' => $webhookId,
        'trigger_name' => $trigger,
        'example_payload' => null,
        'field_mapping' => null,
        'include_user_data' => false,
        'captured_at' => null,
      ];
    }

    $schema['supports_user_enrichment'] = $this->payloadTransformer->supportsUserEnrichment($trigger);

    return rest_ensure_response($schema);
  }

  /**
   * Update schema for specific webhook+trigger
   */
  public function updateSchema(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $webhookId = (int) $request->get_param('id');
    $trigger = sanitize_text_field($request->get_param('trigger'));

    // Verify webhook exists
    $webhook = $this->webhookRepository->find($webhookId);
    if (!$webhook) {
      return new WP_Error(
        'rest_webhook_not_found',
        __('Webhook not found.', 'flowsystems-webhook-actions'),
        ['status' => 404]
      );
    }

    $data = [];

    if ($request->has_param('field_mapping')) {
      $data['field_mapping'] = $request->get_param('field_mapping');
    }

    if ($request->has_param('include_user_data')) {
      $data['include_user_data'] = (bool) $request->get_param('include_user_data');
    }

    if (empty($data)) {
      return new WP_Error(
        'rest_no_data',
        __('No data to update.', 'flowsystems-webhook-actions'),
        ['status' => 400]
      );
    }

    $result = $this->schemaRepository->upsert($webhookId, $trigger, $data);

    if ($result === false) {
      return new WP_Error(
        'rest_schema_update_failed',
        __('Failed to update schema.', 'flowsystems-webhook-actions'),
        ['status' => 500]
      );
    }

    $schema = $this->schemaRepository->findByWebhookAndTrigger($webhookId, $trigger);
    $schema['supports_user_enrichment'] = $this->payloadTransformer->supportsUserEnrichment($trigger);

    return rest_ensure_response($schema);
  }

  /**
   * Delete schema for specific webhook+trigger
   */
  public function deleteSchema(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $webhookId = (int) $request->get_param('id');
    $trigger = sanitize_text_field($request->get_param('trigger'));

    // Verify webhook exists
    $webhook = $this->webhookRepository->find($webhookId);
    if (!$webhook) {
      return new WP_Error(
        'rest_webhook_not_found',
        __('Webhook not found.', 'flowsystems-webhook-actions'),
        ['status' => 404]
      );
    }

    $result = $this->schemaRepository->deleteByWebhookAndTrigger($webhookId, $trigger);

    if (!$result) {
      return new WP_Error(
        'rest_schema_delete_failed',
        __('Failed to delete schema.', 'flowsystems-webhook-actions'),
        ['status' => 500]
      );
    }

    return rest_ensure_response(['deleted' => true]);
  }

  /**
   * Reset capture (clear example payload for re-capture)
   */
  public function resetCapture(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $webhookId = (int) $request->get_param('id');
    $trigger = sanitize_text_field($request->get_param('trigger'));

    // Verify webhook exists
    $webhook = $this->webhookRepository->find($webhookId);
    if (!$webhook) {
      return new WP_Error(
        'rest_webhook_not_found',
        __('Webhook not found.', 'flowsystems-webhook-actions'),
        ['status' => 404]
      );
    }

    $result = $this->schemaRepository->clearExamplePayload($webhookId, $trigger);

    if (!$result) {
      return new WP_Error(
        'rest_capture_reset_failed',
        __('Failed to reset capture.', 'flowsystems-webhook-actions'),
        ['status' => 500]
      );
    }

    $schema = $this->schemaRepository->findByWebhookAndTrigger($webhookId, $trigger);

    if ($schema) {
      $schema['supports_user_enrichment'] = $this->payloadTransformer->supportsUserEnrichment($trigger);
    }

    return rest_ensure_response([
      'reset' => true,
      'schema' => $schema,
    ]);
  }

  /**
   * Get list of triggers that support user enrichment
   */
  public function getUserTriggers(WP_REST_Request $request): WP_REST_Response {
    return rest_ensure_response([
      'triggers' => $this->payloadTransformer->getUserEnrichmentTriggers(),
    ]);
  }
}
