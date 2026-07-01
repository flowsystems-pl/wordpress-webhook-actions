<?php

namespace FlowSystems\WebhookActions\Abilities;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Repositories\WebhookRepository;
use FlowSystems\WebhookActions\Repositories\SchemaRepository;
use FlowSystems\WebhookActions\Repositories\LogRepository;
use FlowSystems\WebhookActions\Repositories\CredentialRepository;
use FlowSystems\WebhookActions\Repositories\ChainRepository;
use FlowSystems\WebhookActions\Repositories\ChainLinkRepository;
use FlowSystems\WebhookActions\Services\HookDiscoveryService;
use FlowSystems\WebhookActions\Services\PayloadTransformer;
use FlowSystems\WebhookActions\Services\LogService;
use FlowSystems\WebhookActions\Services\Dispatcher;
use FlowSystems\WebhookActions\Services\QueueService;
use FlowSystems\WebhookActions\Services\WPHttpTransport;
use FlowSystems\WebhookActions\Services\CredentialCipher;
use FlowSystems\WebhookActions\Api\AuthHelper;
use WP_Error;

/**
 * Canonical registry of the operations the AI Builder agent can perform.
 *
 * Each ability is a thin, well-described wrapper over the same repositories and
 * services the REST controllers use. The registry is the single source of truth
 * for the toolset: the AgentOrchestrator calls execute() directly, and
 * AbilityRegistrar exposes the same definitions to the WordPress Abilities API
 * (WP 6.9+/7.0) so external MCP clients (Claude Code, Cursor) get an identical
 * toolset for free.
 *
 * Safety model:
 *  - `scope` mirrors the API token scopes (read / full). The agent token has
 *    `agent` scope which ranks as full for writes but can never reveal secrets.
 *  - `requires_confirm` abilities (enable / delete / edit-live) are surfaced to
 *    the UI so they pause for an explicit user confirmation before running.
 *  - create_webhook always creates the webhook DISABLED.
 */
class AbilityRegistry {
  /** Ability namespace used when registering with the WP Abilities API. */
  public const NAMESPACE = 'flowsystems-webhook-actions';

  /** Max bytes of a probe response body returned to the agent. */
  private const PROBE_BODY_LIMIT = 4096;

  /** Probe calls allowed per rolling minute (abuse guard). */
  private const PROBE_RATE_PER_MIN = 10;

  /**
   * Return all ability definitions keyed by short name.
   *
   * Each definition: label, description, category, scope, requires_confirm,
   * input_schema (JSON Schema), and a `callback` (callable(array $input):
   * array|WP_Error).
   *
   * @return array<string, array<string, mixed>>
   */
  public function definitions(): array {
    return [
      // ---- Discovery / read --------------------------------------------
      'list_triggers' => [
        'label'        => __('List available triggers', 'flowsystems-webhook-actions'),
        'description'  => __('List every WordPress do_action hook discovered on this site (runtime + static scan) that can be used as a webhook trigger.', 'flowsystems-webhook-actions'),
        'category'     => 'webhook-actions',
        'scope'        => AuthHelper::SCOPE_READ,
        'input_schema' => ['type' => 'object', 'properties' => (object) []],
        'callback'     => [$this, 'listTriggers'],
      ],
      'list_webhooks' => [
        'label'        => __('List webhooks', 'flowsystems-webhook-actions'),
        'description'  => __('List all configured webhooks with their triggers and status.', 'flowsystems-webhook-actions'),
        'category'     => 'webhook-actions',
        'scope'        => AuthHelper::SCOPE_READ,
        'input_schema' => ['type' => 'object', 'properties' => (object) []],
        'callback'     => [$this, 'listWebhooks'],
      ],
      'get_webhook' => [
        'label'        => __('Get a webhook', 'flowsystems-webhook-actions'),
        'description'  => __('Get a single webhook by id, including triggers, mapping, conditions, headers and credential assignment.', 'flowsystems-webhook-actions'),
        'category'     => 'webhook-actions',
        'scope'        => AuthHelper::SCOPE_READ,
        'input_schema' => [
          'type'       => 'object',
          'properties' => ['id' => ['type' => 'integer', 'description' => 'Webhook id']],
          'required'   => ['id'],
        ],
        'callback'     => [$this, 'getWebhook'],
      ],
      'get_trigger_schema' => [
        'label'        => __('Get captured payload + mapping for a trigger', 'flowsystems-webhook-actions'),
        'description'  => __('Return the last captured example payload, field mapping and conditions for a webhook+trigger so the agent can map against the real payload shape.', 'flowsystems-webhook-actions'),
        'category'     => 'webhook-actions',
        'scope'        => AuthHelper::SCOPE_READ,
        'input_schema' => [
          'type'       => 'object',
          'properties' => [
            'webhook_id' => ['type' => 'integer'],
            'trigger'    => ['type' => 'string'],
          ],
          'required'   => ['webhook_id', 'trigger'],
        ],
        'callback'     => [$this, 'getTriggerSchema'],
      ],
      'get_logs' => [
        'label'        => __('Get delivery logs', 'flowsystems-webhook-actions'),
        'description'  => __('Read recent delivery logs (optionally for one webhook) to verify what was sent and how the endpoint responded.', 'flowsystems-webhook-actions'),
        'category'     => 'webhook-actions',
        'scope'        => AuthHelper::SCOPE_READ,
        'input_schema' => [
          'type'       => 'object',
          'properties' => [
            'webhook_id' => ['type' => 'integer'],
            'limit'      => ['type' => 'integer', 'default' => 10],
          ],
        ],
        'callback'     => [$this, 'getLogs'],
      ],
      'list_credentials' => [
        'label'        => __('List credentials', 'flowsystems-webhook-actions'),
        'description'  => __('List vault credentials (names, types and masked hints only — secrets are never returned).', 'flowsystems-webhook-actions'),
        'category'     => 'webhook-actions',
        'scope'        => AuthHelper::SCOPE_FULL,
        'input_schema' => ['type' => 'object', 'properties' => (object) []],
        'callback'     => [$this, 'listCredentials'],
      ],

      // ---- Build / write -----------------------------------------------
      'create_webhook' => [
        'label'        => __('Create a webhook', 'flowsystems-webhook-actions'),
        'description'  => __('Create a new webhook (always created DISABLED until the user enables it). Provide name, endpoint_url, http_method and triggers.', 'flowsystems-webhook-actions'),
        'category'     => 'webhook-actions',
        'scope'        => AuthHelper::SCOPE_FULL,
        'input_schema' => [
          'type'       => 'object',
          'properties' => [
            'name'               => ['type' => 'string'],
            'endpoint_url'       => ['type' => 'string'],
            'http_method'        => ['type' => 'string', 'enum' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], 'default' => 'POST'],
            'triggers'           => ['type' => 'array', 'items' => ['type' => 'string']],
            'auth_credential_id' => ['type' => 'integer'],
            'custom_headers'     => ['type' => 'object'],
            'url_params'         => ['type' => 'object'],
          ],
          'required'   => ['name', 'endpoint_url'],
        ],
        'callback'     => [$this, 'createWebhook'],
      ],
      'update_webhook' => [
        'label'            => __('Update a webhook', 'flowsystems-webhook-actions'),
        'description'      => __('Update an existing webhook (endpoint, method, triggers, headers, credential).', 'flowsystems-webhook-actions'),
        'category'         => 'webhook-actions',
        'scope'            => AuthHelper::SCOPE_FULL,
        'requires_confirm' => false,
        'input_schema'     => [
          'type'       => 'object',
          'properties' => [
            'id'                 => ['type' => 'integer'],
            'name'               => ['type' => 'string'],
            'endpoint_url'       => ['type' => 'string'],
            'http_method'        => ['type' => 'string', 'enum' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']],
            'triggers'           => ['type' => 'array', 'items' => ['type' => 'string']],
            'auth_credential_id' => ['type' => 'integer'],
            'custom_headers'     => ['type' => 'object'],
            'url_params'         => ['type' => 'object'],
          ],
          'required'   => ['id'],
        ],
        'callback'         => [$this, 'updateWebhook'],
      ],
      'set_mapping' => [
        'label'        => __('Set field mapping', 'flowsystems-webhook-actions'),
        'description'  => __('Set the payload field mapping for a webhook+trigger (rename / restructure / exclude / type-cast fields with dot-notation paths).', 'flowsystems-webhook-actions'),
        'category'     => 'webhook-actions',
        'scope'        => AuthHelper::SCOPE_FULL,
        'input_schema' => [
          'type'       => 'object',
          'properties' => [
            'webhook_id'    => ['type' => 'integer'],
            'trigger'       => ['type' => 'string'],
            'field_mapping' => ['type' => 'object', 'description' => 'Mapping definition as used by the mapping UI.'],
          ],
          'required'   => ['webhook_id', 'trigger', 'field_mapping'],
        ],
        'callback'     => [$this, 'setMapping'],
      ],
      'set_conditions' => [
        'label'        => __('Set conditions', 'flowsystems-webhook-actions'),
        'description'  => __('Set conditional-dispatch rules for a webhook+trigger so only matching events leave the site.', 'flowsystems-webhook-actions'),
        'category'     => 'webhook-actions',
        'scope'        => AuthHelper::SCOPE_FULL,
        'input_schema' => [
          'type'       => 'object',
          'properties' => [
            'webhook_id'             => ['type' => 'integer'],
            'trigger'                => ['type' => 'string'],
            'conditions'             => ['type' => 'array', 'items' => ['type' => 'object']],
            'conditions_evaluate_on' => ['type' => 'string', 'enum' => ['original', 'transformed'], 'default' => 'original'],
          ],
          'required'   => ['webhook_id', 'trigger', 'conditions'],
        ],
        'callback'     => [$this, 'setConditions'],
      ],
      'assign_credential' => [
        'label'        => __('Assign a vault credential to a webhook', 'flowsystems-webhook-actions'),
        'description'  => __('Reference a stored vault credential from a webhook by id (the secret is injected at dispatch time and never exposed).', 'flowsystems-webhook-actions'),
        'category'     => 'webhook-actions',
        'scope'        => AuthHelper::SCOPE_FULL,
        'input_schema' => [
          'type'       => 'object',
          'properties' => [
            'webhook_id'    => ['type' => 'integer'],
            'credential_id' => ['type' => ['integer', 'null']],
          ],
          'required'   => ['webhook_id'],
        ],
        'callback'     => [$this, 'assignCredential'],
      ],
      'create_chain' => [
        'label'        => __('Create a webhook chain', 'flowsystems-webhook-actions'),
        'description'  => __('Create a named chain that wires 2xx completions of one webhook to downstream webhooks.', 'flowsystems-webhook-actions'),
        'category'     => 'webhook-actions',
        'scope'        => AuthHelper::SCOPE_FULL,
        'input_schema' => [
          'type'       => 'object',
          'properties' => [
            'name'        => ['type' => 'string'],
            'description' => ['type' => 'string'],
          ],
          'required'   => ['name'],
        ],
        'callback'     => [$this, 'createChain'],
      ],
      'create_chain_link' => [
        'label'        => __('Add a chain link', 'flowsystems-webhook-actions'),
        'description'  => __('Add a source→target edge to a chain. Rejected if it would create a cycle across any chain.', 'flowsystems-webhook-actions'),
        'category'     => 'webhook-actions',
        'scope'        => AuthHelper::SCOPE_FULL,
        'input_schema' => [
          'type'       => 'object',
          'properties' => [
            'chain_id'          => ['type' => 'integer'],
            'source_webhook_id' => ['type' => 'integer'],
            'target_webhook_id' => ['type' => 'integer'],
          ],
          'required'   => ['chain_id', 'source_webhook_id', 'target_webhook_id'],
        ],
        'callback'     => [$this, 'createChainLink'],
      ],

      // ---- Test / validate ---------------------------------------------
      'probe_endpoint' => [
        'label'        => __('Probe a target endpoint', 'flowsystems-webhook-actions'),
        'description'  => __('Make a guarded test HTTP call to validate an endpoint before going live. To probe a webhook you created, pass webhook_id (e.g. {{step_2.id}}) — its URL, credential and method are reused automatically, so never ask the user for the endpoint URL again. Only pass url for an endpoint not tied to a webhook. Defaults to GET/HEAD; other methods require confirmation. Returns status, redacted headers and a truncated, redacted body. The raw secret is never exposed.', 'flowsystems-webhook-actions'),
        'category'         => 'webhook-actions',
        'scope'            => AuthHelper::SCOPE_FULL,
        'requires_confirm' => 'when_unsafe_method',
        'input_schema'     => [
          'type'       => 'object',
          'properties' => [
            'webhook_id'         => ['type' => 'integer', 'description' => 'Probe an existing webhook by id; reuses its endpoint URL, credential and method. Prefer this over url when probing a webhook you just created.'],
            'url'                => ['type' => 'string'],
            'method'             => ['type' => 'string', 'enum' => ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'], 'default' => 'GET'],
            'auth_credential_id' => ['type' => 'integer'],
            'headers'            => ['type' => 'object'],
            'body'               => ['type' => 'object'],
            'confirmed'          => ['type' => 'boolean', 'default' => false],
          ],
          'required'   => [],
        ],
        'callback'         => [$this, 'probeEndpoint'],
      ],
      'test_dispatch' => [
        'label'        => __('Test-dispatch a webhook', 'flowsystems-webhook-actions'),
        'description'  => __('Send a synchronous test delivery for a webhook using a provided or captured payload, and return the HTTP result so the agent can verify the integration end to end.', 'flowsystems-webhook-actions'),
        'category'     => 'webhook-actions',
        'scope'        => AuthHelper::SCOPE_FULL,
        'input_schema' => [
          'type'       => 'object',
          'properties' => [
            'webhook_id' => ['type' => 'integer'],
            'trigger'    => ['type' => 'string'],
            'payload'    => ['type' => 'object', 'description' => 'Optional custom payload; falls back to the captured example.'],
          ],
          'required'   => ['webhook_id'],
        ],
        'callback'     => [$this, 'testDispatch'],
      ],

      // ---- Go-live / destructive (confirm) ------------------------------
      'enable_webhook' => [
        'label'            => __('Enable / disable a webhook', 'flowsystems-webhook-actions'),
        'description'      => __('Enable (go live) or disable a webhook. Enabling requires confirmation.', 'flowsystems-webhook-actions'),
        'category'         => 'webhook-actions',
        'scope'            => AuthHelper::SCOPE_FULL,
        'requires_confirm' => 'always',
        'input_schema'     => [
          'type'       => 'object',
          'properties' => [
            'id'      => ['type' => 'integer'],
            'enabled' => ['type' => 'boolean', 'default' => true],
          ],
          'required'   => ['id'],
        ],
        'callback'         => [$this, 'enableWebhook'],
      ],
      'delete_webhook' => [
        'label'            => __('Delete a webhook', 'flowsystems-webhook-actions'),
        'description'      => __('Permanently delete a webhook and its triggers / mapping. Requires confirmation.', 'flowsystems-webhook-actions'),
        'category'         => 'webhook-actions',
        'scope'            => AuthHelper::SCOPE_FULL,
        'requires_confirm' => 'always',
        'input_schema'     => [
          'type'       => 'object',
          'properties' => ['id' => ['type' => 'integer']],
          'required'   => ['id'],
        ],
        'callback'         => [$this, 'deleteWebhook'],
      ],
    ];
  }

  /**
   * Execute an ability by short name. Used by the orchestrator.
   *
   * @return array<string, mixed>|WP_Error
   */
  public function execute(string $name, array $input): array|WP_Error {
    $definitions = $this->definitions();
    if (!isset($definitions[$name])) {
      return new WP_Error('fswa_unknown_ability', sprintf(/* translators: %s: ability name */ __('Unknown ability: %s', 'flowsystems-webhook-actions'), $name), ['status' => 400]);
    }

    return call_user_func($definitions[$name]['callback'], $input);
  }

  // ===================================================================
  // Read implementations
  // ===================================================================

  public function listTriggers(array $input): array {
    return ['triggers' => (new HookDiscoveryService())->discover()];
  }

  public function listWebhooks(array $input): array {
    return ['webhooks' => (new WebhookRepository())->getAll()];
  }

  public function getWebhook(array $input): array|WP_Error {
    $webhook = (new WebhookRepository())->find((int) ($input['id'] ?? 0));
    if (!$webhook) {
      return $this->notFound();
    }
    $webhook['schemas'] = (new SchemaRepository())->getByWebhook((int) $webhook['id']);
    return ['webhook' => $webhook];
  }

  public function getTriggerSchema(array $input): array|WP_Error {
    $webhookId = (int) ($input['webhook_id'] ?? 0);
    $trigger   = (string) ($input['trigger'] ?? '');
    if ($webhookId <= 0 || $trigger === '') {
      return $this->invalid(__('webhook_id and trigger are required.', 'flowsystems-webhook-actions'));
    }
    $repo   = new SchemaRepository();
    $schema = $repo->findByWebhookAndTrigger($webhookId, $trigger);

    // Resolve the effective example: this webhook's own capture, or — when reuse
    // is enabled (the default) — the latest one for the same trigger on another
    // webhook (the do_action payload shape is trigger-global), so we don't force
    // a fresh test.
    $resolved = $repo->resolveExample($webhookId, $trigger, $schema);
    if ($resolved['example'] === null) {
      // Nothing captured anywhere yet → null signals "submit a test first".
      return ['schema' => null];
    }

    $schema = array_merge(
      $schema ?: ['webhook_id' => $webhookId, 'trigger_name' => $trigger],
      ['example_payload' => $resolved['example']]
    );
    if ($resolved['source'] === 'shared') {
      return ['schema' => $schema, 'borrowed_from_webhook_id' => $resolved['from_webhook_id']];
    }
    return ['schema' => $schema];
  }

  public function getLogs(array $input): array {
    $repo  = new LogRepository();
    $limit = max(1, min(100, (int) ($input['limit'] ?? 10)));
    if (!empty($input['webhook_id'])) {
      $result = $repo->getByWebhook((int) $input['webhook_id'], 1, $limit);
    } else {
      $result = $repo->getPaginated([], 1, $limit);
    }
    return ['logs' => $result['items'] ?? $result];
  }

  public function listCredentials(array $input): array {
    return ['credentials' => (new CredentialRepository())->getAll()];
  }

  // ===================================================================
  // Write implementations
  // ===================================================================

  public function createWebhook(array $input): array|WP_Error {
    $name = sanitize_text_field((string) ($input['name'] ?? ''));
    $url  = esc_url_raw((string) ($input['endpoint_url'] ?? ''));
    if ($name === '' || $url === '') {
      return $this->invalid(__('name and endpoint_url are required.', 'flowsystems-webhook-actions'));
    }

    $repo = new WebhookRepository();
    $id   = $repo->create([
      'name'               => $name,
      'endpoint_url'       => $url,
      'http_method'        => strtoupper((string) ($input['http_method'] ?? 'POST')),
      'triggers'           => array_map('sanitize_text_field', (array) ($input['triggers'] ?? [])),
      'auth_credential_id' => isset($input['auth_credential_id']) ? (int) $input['auth_credential_id'] : null,
      'custom_headers'     => $input['custom_headers'] ?? null,
      'url_params'         => $input['url_params'] ?? null,
      // Always created disabled — the agent must explicitly enable (with confirm).
      'is_enabled'         => 0,
    ]);

    if (!$id) {
      return new WP_Error('fswa_create_failed', __('Failed to create webhook.', 'flowsystems-webhook-actions'), ['status' => 500]);
    }

    return ['webhook' => $repo->find((int) $id), 'created_disabled' => true];
  }

  public function updateWebhook(array $input): array|WP_Error {
    $id   = (int) ($input['id'] ?? 0);
    $repo = new WebhookRepository();
    if (!$repo->find($id)) {
      return $this->notFound();
    }

    $data = [];
    foreach (['name', 'endpoint_url', 'http_method', 'triggers', 'auth_credential_id', 'custom_headers', 'url_params'] as $field) {
      if (array_key_exists($field, $input)) {
        $data[$field] = $input[$field];
      }
    }
    if (isset($data['name'])) {
      $data['name'] = sanitize_text_field((string) $data['name']);
    }
    if (isset($data['endpoint_url'])) {
      $data['endpoint_url'] = esc_url_raw((string) $data['endpoint_url']);
    }
    if (isset($data['triggers'])) {
      $data['triggers'] = array_map('sanitize_text_field', (array) $data['triggers']);
    }

    if (!$repo->update($id, $data)) {
      return new WP_Error('fswa_update_failed', __('Failed to update webhook.', 'flowsystems-webhook-actions'), ['status' => 500]);
    }

    return ['webhook' => $repo->find($id)];
  }

  public function setMapping(array $input): array|WP_Error {
    $webhookId = (int) ($input['webhook_id'] ?? 0);
    $trigger   = (string) ($input['trigger'] ?? '');
    if ($webhookId <= 0 || $trigger === '' || !isset($input['field_mapping'])) {
      return $this->invalid(__('webhook_id, trigger and field_mapping are required.', 'flowsystems-webhook-actions'));
    }
    $schemaId = (new SchemaRepository())->upsert($webhookId, $trigger, ['field_mapping' => $input['field_mapping']]);
    if (!$schemaId) {
      return new WP_Error('fswa_mapping_failed', __('Failed to save mapping.', 'flowsystems-webhook-actions'), ['status' => 500]);
    }
    return ['schema_id' => (int) $schemaId];
  }

  public function setConditions(array $input): array|WP_Error {
    $webhookId = (int) ($input['webhook_id'] ?? 0);
    $trigger   = (string) ($input['trigger'] ?? '');
    if ($webhookId <= 0 || $trigger === '' || !isset($input['conditions'])) {
      return $this->invalid(__('webhook_id, trigger and conditions are required.', 'flowsystems-webhook-actions'));
    }
    $schemaId = (new SchemaRepository())->upsert($webhookId, $trigger, [
      'conditions'             => $input['conditions'],
      'conditions_evaluate_on' => $input['conditions_evaluate_on'] ?? 'original',
    ]);
    if (!$schemaId) {
      return new WP_Error('fswa_conditions_failed', __('Failed to save conditions.', 'flowsystems-webhook-actions'), ['status' => 500]);
    }
    return ['schema_id' => (int) $schemaId];
  }

  public function assignCredential(array $input): array|WP_Error {
    $webhookId    = (int) ($input['webhook_id'] ?? 0);
    $repo         = new WebhookRepository();
    if (!$repo->find($webhookId)) {
      return $this->notFound();
    }
    $credentialId = array_key_exists('credential_id', $input) && $input['credential_id'] !== null
      ? (int) $input['credential_id']
      : null;

    if ($credentialId !== null && !(new CredentialRepository())->find($credentialId)) {
      return $this->invalid(__('Credential not found.', 'flowsystems-webhook-actions'));
    }

    $repo->update($webhookId, ['auth_credential_id' => $credentialId]);
    return ['webhook_id' => $webhookId, 'auth_credential_id' => $credentialId];
  }

  public function createChain(array $input): array|WP_Error {
    $name = sanitize_text_field((string) ($input['name'] ?? ''));
    if ($name === '') {
      return $this->invalid(__('Chain name is required.', 'flowsystems-webhook-actions'));
    }
    $repo = new ChainRepository();
    if ($repo->findByName($name)) {
      return new WP_Error('fswa_duplicate_chain', __('A chain with this name already exists.', 'flowsystems-webhook-actions'), ['status' => 409]);
    }
    $id = $repo->create(['name' => $name, 'description' => sanitize_text_field((string) ($input['description'] ?? ''))]);
    if (!$id) {
      return new WP_Error('fswa_chain_failed', __('Failed to create chain.', 'flowsystems-webhook-actions'), ['status' => 500]);
    }
    return ['chain' => $repo->find((int) $id)];
  }

  public function createChainLink(array $input): array|WP_Error {
    $chainId = (int) ($input['chain_id'] ?? 0);
    $source  = (int) ($input['source_webhook_id'] ?? 0);
    $target  = (int) ($input['target_webhook_id'] ?? 0);
    if ($chainId <= 0 || $source <= 0 || $target <= 0) {
      return $this->invalid(__('chain_id, source_webhook_id and target_webhook_id are required.', 'flowsystems-webhook-actions'));
    }
    $links = new ChainLinkRepository();
    if ($links->wouldCreateCycle($source, $target)) {
      return new WP_Error('fswa_chain_cycle', __('That link would create a cycle across an existing chain.', 'flowsystems-webhook-actions'), ['status' => 409]);
    }
    $id = $links->create($chainId, $source, $target);
    if (!$id) {
      return new WP_Error('fswa_link_failed', __('Failed to create chain link.', 'flowsystems-webhook-actions'), ['status' => 500]);
    }
    return ['link' => $links->find((int) $id)];
  }

  // ===================================================================
  // Test / validate implementations
  // ===================================================================

  /**
   * Guarded outbound test call. Reuses the same wp_remote_request path as
   * dispatch, with an SSRF guard, a rate limit, body-size cap, and vault-secret
   * injection by credential id (the raw secret never leaves the server).
   */
  public function probeEndpoint(array $input): array|WP_Error {
    $url    = esc_url_raw((string) ($input['url'] ?? ''));
    $method = strtoupper((string) ($input['method'] ?? ''));
    $authId = (int) ($input['auth_credential_id'] ?? 0);

    // Whether the caller explicitly asked for an unsafe method (vs. inheriting the
    // webhook's own configured method, which is pre-approved for the webhook the
    // user is building).
    $methodExplicit = $method !== '';

    // Probe a webhook we already created: reuse its endpoint URL, credential and
    // HTTP method so it validates the endpoint the way the webhook will actually
    // call it (e.g. a POST-only receiver correctly, instead of a false GET 404).
    // An empty body is sent — a real delivery with the payload is test_dispatch.
    $webhookId = (int) ($input['webhook_id'] ?? 0);
    if ($url === '' && $webhookId > 0) {
      $webhook = (new WebhookRepository())->find($webhookId);
      if (!$webhook) {
        return $this->notFound();
      }
      $url = esc_url_raw((string) ($webhook['endpoint_url'] ?? ''));
      if ($authId === 0 && !empty($webhook['auth_credential_id'])) {
        $authId = (int) $webhook['auth_credential_id'];
      }
      if ($method === '') {
        $method = strtoupper((string) ($webhook['http_method'] ?? 'GET'));
      }
    }

    if ($method === '') {
      $method = 'GET';
    }

    if ($url === '') {
      return $this->invalid(__('A url or webhook_id is required.', 'flowsystems-webhook-actions'));
    }

    // SSRF guard: WordPress rejects loopback / private / reserved hosts unless a
    // filter opts in. This also blocks link-local cloud-metadata endpoints.
    if (!wp_http_validate_url($url)) {
      return new WP_Error('fswa_probe_blocked', __('That URL is not allowed (private, reserved or invalid host).', 'flowsystems-webhook-actions'), ['status' => 422]);
    }

    // Confirmation guards caller-specified unsafe methods on arbitrary URLs. A
    // webhook's own method is pre-approved — the user is building that webhook.
    $unsafe = $methodExplicit && !in_array($method, ['GET', 'HEAD'], true);
    if ($unsafe && empty($input['confirmed'])) {
      return new WP_Error('fswa_probe_confirm', __('Non-idempotent probe methods require confirmation.', 'flowsystems-webhook-actions'), ['status' => 412]);
    }

    if (!$this->probeRateOk()) {
      return new WP_Error('fswa_probe_rate', __('Too many probe calls — try again in a minute.', 'flowsystems-webhook-actions'), ['status' => 429]);
    }

    $headers = [];
    foreach ((array) ($input['headers'] ?? []) as $k => $v) {
      $headers[sanitize_text_field((string) $k)] = sanitize_text_field((string) $v);
    }

    // Inject the vault credential without ever exposing it to the caller.
    if ($authId > 0) {
      $injected = $this->resolveCredentialHeader($authId);
      if (is_wp_error($injected)) {
        return $injected;
      }
      $headers = array_merge($headers, $injected);
    }

    $args = [
      'method'              => $method,
      'headers'             => $headers,
      'timeout'             => 8,
      'redirection'         => 2,
      'limit_response_size' => self::PROBE_BODY_LIMIT,
      'user-agent'          => 'WordPress/FlowSystemsWebhookActions-AIProbe',
    ];
    if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
      // Send a minimal empty JSON body when none is supplied, so POST-only
      // receivers accept the request instead of rejecting an empty payload.
      $args['headers']['Content-Type'] = 'application/json';
      $args['body']                    = wp_json_encode(isset($input['body']) ? $input['body'] : new \stdClass());
    }

    $response = wp_remote_request($url, $args);
    if (is_wp_error($response)) {
      return ['ok' => false, 'error' => $response->get_error_message()];
    }

    $body = $this->truncate((string) wp_remote_retrieve_body($response), self::PROBE_BODY_LIMIT);

    return [
      'ok'      => true,
      'status'  => (int) wp_remote_retrieve_response_code($response),
      'headers' => $this->redactHeaders((array) wp_remote_retrieve_headers($response)->getAll()),
      'body'    => $this->redactBody($body, $args['headers']),
    ];
  }

  /**
   * Synchronous test delivery. Mirrors WebhooksController::testItem for the
   * custom/captured payload paths, sending immediately so the agent sees a result.
   */
  public function testDispatch(array $input): array|WP_Error {
    $id       = (int) ($input['webhook_id'] ?? 0);
    $repo     = new WebhookRepository();
    $webhook  = $repo->find($id);
    if (!$webhook) {
      return $this->notFound();
    }

    $triggers = $webhook['triggers'] ?? [];
    $trigger  = (string) ($input['trigger'] ?? ($triggers[0] ?? ''));
    if ($trigger === '') {
      return $this->invalid(__('The webhook has no triggers; provide one.', 'flowsystems-webhook-actions'));
    }

    if (isset($input['payload']) && is_array($input['payload'])) {
      $payload = $input['payload'];
    } else {
      // Use this webhook's own captured example, or — when reuse is enabled — one
      // captured for the same trigger on another webhook (trigger-global shape).
      $example = (new SchemaRepository())->resolveExample($id, $trigger)['example'] ?? null;
      if (empty($example)) {
        return new WP_Error('fswa_no_payload', __('No payload provided and no captured example exists yet for this trigger.', 'flowsystems-webhook-actions'), ['status' => 422]);
      }
      $payload = is_string($example) ? (json_decode($example, true) ?: []) : (array) $example;
    }

    $logService = new LogService();
    $logId      = $logService->logPending($id, $trigger, $payload, null, false);

    $dispatcher = new Dispatcher(new WPHttpTransport(), new QueueService());
    $dispatcher->sendToWebhook($webhook, $payload, $trigger, $logId, 0, true, null);

    $log = $logService->getRepository()->find($logId);

    return [
      'log_id'   => $logId,
      'status'   => $log['status'] ?? null,
      'http_code' => $log['http_code'] ?? null,
      'response' => $this->truncate((string) ($log['response_body'] ?? ''), self::PROBE_BODY_LIMIT),
    ];
  }

  // ===================================================================
  // Go-live / destructive
  // ===================================================================

  public function enableWebhook(array $input): array|WP_Error {
    $id      = (int) ($input['id'] ?? 0);
    $repo    = new WebhookRepository();
    if (!$repo->find($id)) {
      return $this->notFound();
    }
    $enabled = array_key_exists('enabled', $input) ? (bool) $input['enabled'] : true;
    $repo->setEnabled($id, $enabled);
    return ['id' => $id, 'is_enabled' => $enabled];
  }

  public function deleteWebhook(array $input): array|WP_Error {
    $id   = (int) ($input['id'] ?? 0);
    $repo = new WebhookRepository();
    if (!$repo->find($id)) {
      return $this->notFound();
    }
    if (!$repo->delete($id)) {
      return new WP_Error('fswa_delete_failed', __('Failed to delete webhook.', 'flowsystems-webhook-actions'), ['status' => 500]);
    }
    return ['deleted' => true, 'id' => $id];
  }

  // ===================================================================
  // Helpers
  // ===================================================================

  /**
   * Resolve a vault credential into outgoing header(s) — internal only.
   *
   * @return array<string, string>|WP_Error
   */
  private function resolveCredentialHeader(int $credentialId): array|WP_Error {
    $row = (new CredentialRepository())->findWithSecret($credentialId);
    if (!$row) {
      return $this->invalid(__('Credential not found.', 'flowsystems-webhook-actions'));
    }
    $secret = (new CredentialCipher())->decrypt((string) ($row['secret_ciphertext'] ?? ''));
    if ($secret === null) {
      return new WP_Error('fswa_credential_undecryptable', __('Credential could not be decrypted.', 'flowsystems-webhook-actions'), ['status' => 500]);
    }

    $header = $row['header_name'] ?: 'Authorization';
    $value  = match ($row['type']) {
      'bearer' => 'Bearer ' . $secret,
      'basic'  => 'Basic ' . base64_encode($secret),
      default  => $secret,
    };

    return [$header => $value];
  }

  private function probeRateOk(): bool {
    $key   = 'fswa_probe_rl_' . gmdate('YmdHi');
    $count = (int) get_transient($key);
    if ($count >= self::PROBE_RATE_PER_MIN) {
      return false;
    }
    set_transient($key, $count + 1, MINUTE_IN_SECONDS);
    return true;
  }

  /**
   * Drop Authorization-style headers from a returned header set.
   *
   * @param array<string, mixed> $headers
   * @return array<string, mixed>
   */
  private function redactHeaders(array $headers): array {
    foreach (array_keys($headers) as $key) {
      if (preg_match('/authorization|cookie|set-cookie|api[-_]?key|token/i', (string) $key)) {
        $headers[$key] = '***';
      }
    }
    return $headers;
  }

  /**
   * Redact any secret we sent (the injected auth header values) from a response
   * body, in case a misconfigured target reflects our request headers back.
   *
   * @param array<string, mixed> $sentHeaders The outgoing request headers.
   */
  private function redactBody(string $body, array $sentHeaders): string {
    foreach ($sentHeaders as $key => $value) {
      $value = (string) $value;
      if ($value !== '' && preg_match('/authorization|cookie|api[-_]?key|token|secret/i', (string) $key)) {
        $body = str_replace($value, '***', $body);
      }
    }
    return $body;
  }

  private function truncate(string $value, int $limit): string {
    return strlen($value) > $limit ? substr($value, 0, $limit) . '…' : $value;
  }

  private function notFound(): WP_Error {
    return new WP_Error('fswa_not_found', __('Not found.', 'flowsystems-webhook-actions'), ['status' => 404]);
  }

  private function invalid(string $message): WP_Error {
    return new WP_Error('fswa_invalid', $message, ['status' => 400]);
  }
}
