<?php

namespace FlowSystems\WebhookActions\Api;

defined('ABSPATH') || exit;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use FlowSystems\WebhookActions\Repositories\AgentConversationRepository;
use FlowSystems\WebhookActions\Repositories\CredentialRepository;
use FlowSystems\WebhookActions\Services\CredentialCipher;
use FlowSystems\WebhookActions\Services\ActivityLogService;
use FlowSystems\WebhookActions\Services\Ai\LlmTransport;
use FlowSystems\WebhookActions\Services\Ai\AgentOrchestrator;
use FlowSystems\WebhookActions\Services\Ai\AnthropicTransport;
use FlowSystems\WebhookActions\Services\Ai\OpenAiTransport;
use FlowSystems\WebhookActions\Services\Ai\GoogleTransport;
use FlowSystems\WebhookActions\Services\Ai\AgentTraceLog;
use FlowSystems\WebhookActions\Abilities\AbilityRegistry;

/**
 * REST surface for the AI Builder (the in-admin generative integration builder).
 *
 * Conversations hold the chat transcript + the current editable plan. Messages
 * return a plan-first response; execute applies an (optionally edited) plan with
 * hybrid confirmation. Provider onboarding stores a BYO key in the vault and the
 * non-secret selection in the fswa_ai_provider option.
 */
class AgentController extends WP_REST_Controller {
  protected $namespace = 'fswa/v1';
  protected $rest_base = 'agent';

  private AgentConversationRepository $conversations;
  private AgentOrchestrator           $orchestrator;
  private ActivityLogService          $activity;

  public function __construct() {
    $this->conversations = new AgentConversationRepository();
    $this->orchestrator  = new AgentOrchestrator();
    $this->activity      = new ActivityLogService();
  }

  public function registerRoutes(): void {
    $base = '/' . $this->rest_base;

    register_rest_route($this->namespace, $base . '/status', [
      ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'status'], 'permission_callback' => [$this, 'readCheck']],
    ]);

    register_rest_route($this->namespace, $base . '/preference', [
      ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'savePreference'], 'permission_callback' => [$this, 'writeCheck']],
    ]);

    register_rest_route($this->namespace, $base . '/source', [
      ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'saveSource'], 'permission_callback' => [$this, 'writeCheck']],
    ]);

    register_rest_route($this->namespace, $base . '/byok', [
      ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'saveByok'], 'permission_callback' => [$this, 'writeCheck']],
    ]);

    register_rest_route($this->namespace, $base . '/byok/(?P<provider>[a-z_]+)', [
      ['methods' => WP_REST_Server::DELETABLE, 'callback' => [$this, 'deleteByok'], 'permission_callback' => [$this, 'writeCheck']],
    ]);

    register_rest_route($this->namespace, $base . '/traces', [
      ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'listTraces'], 'permission_callback' => [$this, 'readCheck']],
      ['methods' => WP_REST_Server::DELETABLE, 'callback' => [$this, 'clearTraces'], 'permission_callback' => [$this, 'writeCheck']],
    ]);

    register_rest_route($this->namespace, $base . '/debug', [
      ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'setDebug'], 'permission_callback' => [$this, 'writeCheck']],
    ]);

    register_rest_route($this->namespace, $base . '/abilities', [
      ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'listAbilities'], 'permission_callback' => [$this, 'readCheck']],
    ]);

    register_rest_route($this->namespace, $base . '/conversations', [
      ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'listConversations'], 'permission_callback' => [$this, 'readCheck']],
      ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'createConversation'], 'permission_callback' => [$this, 'writeCheck']],
    ]);

    register_rest_route($this->namespace, $base . '/conversations/(?P<id>[\d]+)', [
      ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'getConversation'], 'permission_callback' => [$this, 'readCheck'], 'args' => $this->idArg()],
      ['methods' => WP_REST_Server::DELETABLE, 'callback' => [$this, 'deleteConversation'], 'permission_callback' => [$this, 'writeCheck'], 'args' => $this->idArg()],
    ]);

    register_rest_route($this->namespace, $base . '/conversations/(?P<id>[\d]+)/message', [
      ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'message'], 'permission_callback' => [$this, 'writeCheck'], 'args' => $this->idArg()],
    ]);

    register_rest_route($this->namespace, $base . '/conversations/(?P<id>[\d]+)/execute', [
      ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'execute'], 'permission_callback' => [$this, 'writeCheck'], 'args' => $this->idArg()],
    ]);

    register_rest_route($this->namespace, $base . '/conversations/(?P<id>[\d]+)/undo', [
      ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'undo'], 'permission_callback' => [$this, 'writeCheck'], 'args' => $this->idArg()],
    ]);

    register_rest_route($this->namespace, $base . '/conversations/(?P<id>[\d]+)/step', [
      ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'step'], 'permission_callback' => [$this, 'writeCheck'], 'args' => $this->idArg()],
    ]);

    register_rest_route($this->namespace, $base . '/conversations/(?P<id>[\d]+)/revert', [
      ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'revert'], 'permission_callback' => [$this, 'writeCheck'], 'args' => $this->idArg()],
    ]);

    register_rest_route($this->namespace, $base . '/exec-mode', [
      ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'setExecMode'], 'permission_callback' => [$this, 'writeCheck']],
    ]);
  }

  public function readCheck(WP_REST_Request $request): bool|WP_Error {
    return AuthHelper::dualAuth($request, AuthHelper::SCOPE_READ);
  }

  public function writeCheck(WP_REST_Request $request): bool|WP_Error {
    return AuthHelper::dualAuth($request, AuthHelper::SCOPE_FULL);
  }

  private function idArg(): array {
    return ['id' => ['type' => 'integer', 'required' => true]];
  }

  /**
   * GET /agent/status — transport configuration for the onboarding / setup card.
   */
  public function status(WP_REST_Request $request): WP_REST_Response {
    $status                  = (new LlmTransport())->status();
    $status['exec_mode']     = $this->orchestrator->execMode();
    // Whether the diagnostic trace panel should be available to this site
    // (Settings → AI Builder). Off by default; the dev server always shows it.
    $status['trace_enabled'] = (bool) get_option('fswa_ai_trace_enabled', false);
    return rest_ensure_response($status);
  }

  /**
   * POST /agent/source — pin which credential source the agent uses.
   * Body: { source: 'auto' | 'wp_ai_client' | 'byok' | 'hosted' }
   */
  public function saveSource(WP_REST_Request $request): WP_REST_Response {
    $source    = sanitize_text_field((string) $request->get_param('source'));
    $transport = new LlmTransport();
    $transport->saveSource($source);

    $this->activity->log('agent.source_set', 'agent', null, $source, [
      'new' => ['source' => $transport->source()],
    ]);

    return rest_ensure_response($transport->status());
  }

  /**
   * POST /agent/byok — connect or update a bring-your-own-key provider, and make
   * it the active BYO provider. Body: { provider, api_key?, model? }
   *
   * api_key is required when the provider has no stored key yet; when omitted on
   * an already-connected provider, only the model/active selection changes.
   */
  public function saveByok(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $provider = sanitize_text_field((string) $request->get_param('provider'));
    if (!isset(LlmTransport::BYOK_PROVIDERS[$provider])) {
      return new WP_Error('fswa_bad_provider', __('Unknown AI provider.', 'flowsystems-webhook-actions'), ['status' => 400]);
    }

    $apiKey = (string) $request->get_param('api_key');
    $model  = sanitize_text_field((string) $request->get_param('model')) ?: $this->defaultModelFor($provider);

    $repository = new CredentialRepository();
    $transport  = new LlmTransport();

    // Locate any existing credential for this provider.
    $byok         = $transport->status()['byok']['providers'];
    $credentialId = 0;
    foreach ($byok as $p) {
      if ($p['id'] === $provider && $p['connected']) {
        // Re-read the stored credential id from the option (status hides it).
        $credentialId = $this->byokCredentialId($provider);
        break;
      }
    }

    if ($apiKey === '' && $credentialId <= 0) {
      return new WP_Error('fswa_missing_key', __('An API key is required to connect this provider.', 'flowsystems-webhook-actions'), ['status' => 400]);
    }

    if ($apiKey !== '') {
      $cipher = new CredentialCipher();
      $fields = [
        'secret_ciphertext' => $cipher->encrypt($apiKey),
        'hint'              => '****' . substr($apiKey, -4),
      ];
      if ($credentialId > 0 && $repository->find($credentialId)) {
        $repository->update($credentialId, $fields);
      } else {
        $name = 'AI Provider — ' . $provider;
        if ($repository->nameExists($name)) {
          $name .= ' ' . gmdate('Y-m-d His');
        }
        $credentialId = (int) $repository->create(array_merge($fields, [
          'name'        => $name,
          'type'        => 'ai_provider',
          'header_name' => 'Authorization',
        ]));
        if (!$credentialId) {
          return new WP_Error('fswa_provider_failed', __('Failed to store the provider key.', 'flowsystems-webhook-actions'), ['status' => 500]);
        }
      }
    }

    $transport->saveByokProvider($provider, $credentialId, $model);

    $this->activity->log('agent.byok_configured', 'agent', null, $provider, [
      'new' => ['provider' => $provider, 'model' => $model, 'credential_id' => $credentialId],
    ]);

    return rest_ensure_response($transport->status());
  }

  /**
   * DELETE /agent/byok/{provider} — disconnect a BYO provider and delete its key.
   */
  public function deleteByok(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $provider = sanitize_text_field((string) $request->get_param('provider'));
    if (!isset(LlmTransport::BYOK_PROVIDERS[$provider])) {
      return new WP_Error('fswa_bad_provider', __('Unknown AI provider.', 'flowsystems-webhook-actions'), ['status' => 400]);
    }

    $transport    = new LlmTransport();
    $credentialId = $transport->removeByokProvider($provider);
    if ($credentialId > 0) {
      (new CredentialRepository())->delete($credentialId);
    }

    $this->activity->log('agent.byok_removed', 'agent', null, $provider, [
      'old' => ['provider' => $provider, 'credential_id' => $credentialId],
    ]);

    return rest_ensure_response($transport->status());
  }

  /**
   * Read the stored vault credential id for a BYO provider from the option.
   */
  private function byokCredentialId(string $provider): int {
    $config = get_option('fswa_ai_byok', []);
    return (int) ($config['providers'][$provider]['credential_id'] ?? 0);
  }

  /**
   * POST /agent/preference — choose which configured WP AI Client provider/model
   * the agent should prefer. Body: { provider, model }
   */
  public function savePreference(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $provider = sanitize_text_field((string) $request->get_param('provider'));
    $model    = sanitize_text_field((string) $request->get_param('model'));

    if ($provider === '' || $model === '') {
      return new WP_Error('fswa_missing_preference', __('A provider and model are required.', 'flowsystems-webhook-actions'), ['status' => 400]);
    }

    $transport = new LlmTransport();
    $transport->savePreference($provider, $model);

    $this->activity->log('agent.preference_set', 'agent', null, $provider, [
      'new' => ['provider' => $provider, 'model' => $model],
    ]);

    return rest_ensure_response($transport->status());
  }

  /**
   * Sensible default model id per BYO provider.
   */
  private function defaultModelFor(string $provider): string {
    return match ($provider) {
      'openai' => OpenAiTransport::DEFAULT_MODEL,
      'google' => GoogleTransport::DEFAULT_MODEL,
      default  => AnthropicTransport::DEFAULT_MODEL,
    };
  }

  /**
   * GET /agent/traces — recent LLM call traces for the dev panel.
   */
  public function listTraces(WP_REST_Request $request): WP_REST_Response {
    $trace = new AgentTraceLog();
    $limit = max(1, min(200, (int) ($request->get_param('limit') ?: 50)));
    return rest_ensure_response([
      'enabled' => $trace->isEnabled(),
      'entries' => $trace->recent($limit),
    ]);
  }

  /**
   * POST /agent/debug — turn trace logging on/off. Body: { enabled: bool }
   */
  public function setDebug(WP_REST_Request $request): WP_REST_Response {
    $trace = new AgentTraceLog();
    $trace->setEnabled(rest_sanitize_boolean($request->get_param('enabled')));
    return rest_ensure_response(['enabled' => $trace->isEnabled()]);
  }

  /**
   * DELETE /agent/traces — remove all stored trace files.
   */
  public function clearTraces(WP_REST_Request $request): WP_REST_Response {
    $removed = (new AgentTraceLog())->clear();
    return rest_ensure_response(['cleared' => true, 'files_removed' => $removed]);
  }

  /**
   * GET /agent/abilities — ability catalog (label, description, input schema) so
   * the UI can render editable plan-step fields with required markers.
   */
  public function listAbilities(WP_REST_Request $request): WP_REST_Response {
    $out = [];
    foreach ((new AbilityRegistry())->definitions() as $name => $def) {
      $out[$name] = [
        'label'            => $def['label'] ?? $name,
        'description'      => $def['description'] ?? '',
        'requires_confirm' => $def['requires_confirm'] ?? false,
        'input_schema'     => $def['input_schema'] ?? ['type' => 'object', 'properties' => (object) []],
      ];
    }
    return rest_ensure_response(['abilities' => $out]);
  }

  /**
   * GET /agent/conversations
   */
  public function listConversations(WP_REST_Request $request): WP_REST_Response {
    return rest_ensure_response(['conversations' => $this->conversations->getAll('active')]);
  }

  /**
   * POST /agent/conversations
   */
  public function createConversation(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $id = $this->conversations->create(['title' => sanitize_text_field((string) $request->get_param('title'))]);
    if (!$id) {
      return new WP_Error('fswa_conv_failed', __('Failed to start a conversation.', 'flowsystems-webhook-actions'), ['status' => 500]);
    }
    return rest_ensure_response($this->conversations->find((int) $id));
  }

  /**
   * GET /agent/conversations/{id}
   */
  public function getConversation(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $conversation = $this->conversations->find((int) $request->get_param('id'));
    if (!$conversation) {
      return $this->notFound();
    }
    return rest_ensure_response($conversation);
  }

  /**
   * DELETE /agent/conversations/{id}
   */
  public function deleteConversation(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $id = (int) $request->get_param('id');
    if (!$this->conversations->find($id)) {
      return $this->notFound();
    }
    $this->conversations->delete($id);
    return rest_ensure_response(['deleted' => true, 'id' => $id]);
  }

  /**
   * POST /agent/conversations/{id}/message  Body: { message }
   */
  public function message(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $message = trim((string) $request->get_param('message'));
    if ($message === '') {
      return new WP_Error('fswa_empty_message', __('A message is required.', 'flowsystems-webhook-actions'), ['status' => 400]);
    }
    $result = $this->orchestrator->converse((int) $request->get_param('id'), $message);
    return is_wp_error($result) ? $result : rest_ensure_response($result);
  }

  /**
   * POST /agent/conversations/{id}/execute  Body: { plan?, confirmed?[] }
   */
  public function execute(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $plan      = $request->get_param('plan');
    $confirmed = (array) ($request->get_param('confirmed') ?? []);
    $result    = $this->orchestrator->execute(
      (int) $request->get_param('id'),
      is_array($plan) ? $plan : null,
      $confirmed
    );
    return is_wp_error($result) ? $result : rest_ensure_response($result);
  }

  /**
   * POST /agent/conversations/{id}/undo
   */
  public function undo(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $result = $this->orchestrator->undoLast((int) $request->get_param('id'));
    return is_wp_error($result) ? $result : rest_ensure_response($result);
  }

  /**
   * POST /agent/conversations/{id}/revert — undo the most recent applied change.
   */
  public function revert(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $result = $this->orchestrator->revertLast((int) $request->get_param('id'));
    return is_wp_error($result) ? $result : rest_ensure_response($result);
  }

  /**
   * POST /agent/conversations/{id}/step — advance the plan by one step.
   * Body: { patch?: {key:value}, confirm?: bool, skip?: bool }
   */
  public function step(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $patch     = $request->get_param('patch');
    $probeFix  = $request->get_param('probe_fix');
    $opts      = [
      'patch'   => is_array($patch) ? $patch : [],
      'confirm' => rest_sanitize_boolean($request->get_param('confirm')),
      'skip'    => rest_sanitize_boolean($request->get_param('skip')),
    ];
    if (is_array($probeFix)) {
      $opts['probe_fix'] = [];
      if (isset($probeFix['endpoint_url'])) {
        $opts['probe_fix']['endpoint_url'] = esc_url_raw((string) $probeFix['endpoint_url']);
      }
      if (isset($probeFix['auth_credential_id'])) {
        $opts['probe_fix']['auth_credential_id'] = (int) $probeFix['auth_credential_id'];
      }
    }
    $result = $this->orchestrator->advanceStep((int) $request->get_param('id'), $opts);
    return is_wp_error($result) ? $result : rest_ensure_response($result);
  }

  /**
   * POST /agent/exec-mode — set the global execution mode. Body: { mode: 'auto'|'review' }
   */
  public function setExecMode(WP_REST_Request $request): WP_REST_Response {
    $mode = $this->orchestrator->saveExecMode((string) $request->get_param('mode'));
    return rest_ensure_response(['exec_mode' => $mode]);
  }

  private function notFound(): WP_Error {
    return new WP_Error('fswa_conversation_not_found', __('Conversation not found.', 'flowsystems-webhook-actions'), ['status' => 404]);
  }
}
