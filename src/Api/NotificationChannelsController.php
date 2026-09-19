<?php

namespace FlowSystems\WebhookActions\Api;

defined('ABSPATH') || exit;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use FlowSystems\WebhookActions\Repositories\NotificationChannelRepository;
use FlowSystems\WebhookActions\Services\ActivityLogService;
use FlowSystems\WebhookActions\Services\Notifications\Channels\ChannelRegistry;
use FlowSystems\WebhookActions\Services\Notifications\DefaultTemplates;
use FlowSystems\WebhookActions\Services\Notifications\DeliveryEvents;
use FlowSystems\WebhookActions\Services\Notifications\MessageBuilder;
use FlowSystems\WebhookActions\Services\Notifications\NotificationSender;
use FlowSystems\WebhookActions\Services\Notifications\PreviewContext;

/**
 * Notification channels — the destinations rules send to.
 *
 * Write-only for secrets, like the Credentials Vault: secret fields are
 * accepted on create/update, stored encrypted, and never returned. A response
 * carries `hint` (last characters of the main secret) and `has_secret`.
 */
class NotificationChannelsController extends WP_REST_Controller {
  protected $namespace = 'fswa/v1';
  protected $rest_base = 'notifications/channels';

  private NotificationChannelRepository $repository;
  private NotificationSender            $sender;
  private ActivityLogService            $activityLog;

  public function __construct() {
    $this->repository  = new NotificationChannelRepository();
    $this->sender      = new NotificationSender();
    $this->activityLog = new ActivityLogService();
  }

  public function registerRoutes(): void {
    register_rest_route($this->namespace, '/notifications/channel-types', [
      'methods'             => WP_REST_Server::READABLE,
      'callback'            => [$this, 'channelTypes'],
      'permission_callback' => [$this, 'readPermissions'],
    ]);

    register_rest_route($this->namespace, '/' . $this->rest_base, [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [$this, 'listChannels'],
        'permission_callback' => [$this, 'readPermissions'],
      ],
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [$this, 'createChannel'],
        'permission_callback' => [$this, 'writePermissions'],
      ],
    ]);

    register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [$this, 'getChannel'],
        'permission_callback' => [$this, 'readPermissions'],
      ],
      [
        'methods'             => WP_REST_Server::EDITABLE,
        'callback'            => [$this, 'updateChannel'],
        'permission_callback' => [$this, 'writePermissions'],
      ],
      [
        'methods'             => WP_REST_Server::DELETABLE,
        'callback'            => [$this, 'deleteChannel'],
        'permission_callback' => [$this, 'writePermissions'],
        'args'                => ['force' => ['type' => 'boolean', 'default' => false]],
      ],
    ]);

    register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)/test', [
      'methods'             => WP_REST_Server::CREATABLE,
      'callback'            => [$this, 'testChannel'],
      'permission_callback' => [$this, 'writePermissions'],
    ]);
  }

  public function readPermissions(WP_REST_Request $request): bool|WP_Error {
    return AuthHelper::dualAuth($request, AuthHelper::SCOPE_READ);
  }

  /** Channels hold secrets: admin session or a full/agent token. */
  public function writePermissions(WP_REST_Request $request): bool|WP_Error {
    return AuthHelper::dualAuth($request, AuthHelper::SCOPE_FULL);
  }

  /**
   * GET /notifications/channel-types — the driver catalog the form is built from.
   */
  public function channelTypes(WP_REST_Request $request): WP_REST_Response {
    return rest_ensure_response([
      'types'  => ChannelRegistry::catalog(),
      'events' => array_map(static fn(string $e): array => ['key' => $e, 'label' => \FlowSystems\WebhookActions\Services\Notifications\TemplateContext::eventLabel($e)], DeliveryEvents::ALL),
      'defaults' => DefaultTemplates::all(),
    ]);
  }

  public function listChannels(WP_REST_Request $request): WP_REST_Response {
    return rest_ensure_response(array_map([$this, 'present'], $this->repository->getAll()));
  }

  public function getChannel(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $channel = $this->repository->find((int) $request->get_param('id'));
    if (!$channel) {
      return $this->notFound();
    }

    return rest_ensure_response($this->present($channel));
  }

  public function createChannel(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $name = sanitize_text_field((string) $request->get_param('name'));
    $type = sanitize_key((string) $request->get_param('type'));

    if ($name === '') {
      return new WP_Error('rest_missing_name', __('Channel name is required.', 'flowsystems-webhook-actions'), ['status' => 400]);
    }
    $driver = ChannelRegistry::get($type);
    if ($driver === null) {
      return new WP_Error('rest_invalid_type', __('Unknown channel type.', 'flowsystems-webhook-actions'), ['status' => 400]);
    }
    if ($this->repository->nameExists($name)) {
      return new WP_Error('rest_duplicate_name', __('A channel with this name already exists.', 'flowsystems-webhook-actions'), ['status' => 409]);
    }

    $split = $this->splitFields($driver, (array) ($request->get_param('config') ?? []), true);
    if (is_wp_error($split)) {
      return $split;
    }

    $id = $this->repository->create([
      'name'              => $name,
      'type'              => $type,
      'config'            => $split['config'],
      'secret_ciphertext' => $this->sender->encryptSecrets($split['secrets']),
      'hint'              => $split['hint'],
      'is_enabled'        => $request->has_param('is_enabled') ? (bool) $request->get_param('is_enabled') : true,
    ]);
    if (!$id) {
      return new WP_Error('rest_create_failed', __('Failed to create channel.', 'flowsystems-webhook-actions'), ['status' => 500]);
    }

    $this->activityLog->log('notification_channel.created', 'notification_channel', $id, $name, [
      'new' => ['name' => $name, 'type' => $type, 'config' => $split['config']],
    ]);

    return rest_ensure_response($this->present($this->repository->find($id)));
  }

  public function updateChannel(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $id      = (int) $request->get_param('id');
    $current = $this->repository->find($id);
    if (!$current) {
      return $this->notFound();
    }

    $updates = [];
    $type    = (string) $current['type'];

    if ($request->has_param('name')) {
      $name = sanitize_text_field((string) $request->get_param('name'));
      if ($name === '') {
        return new WP_Error('rest_missing_name', __('Channel name is required.', 'flowsystems-webhook-actions'), ['status' => 400]);
      }
      if ($this->repository->nameExists($name, $id)) {
        return new WP_Error('rest_duplicate_name', __('A channel with this name already exists.', 'flowsystems-webhook-actions'), ['status' => 409]);
      }
      $updates['name'] = $name;
    }
    if ($request->has_param('type')) {
      $type = sanitize_key((string) $request->get_param('type'));
      if (!ChannelRegistry::has($type)) {
        return new WP_Error('rest_invalid_type', __('Unknown channel type.', 'flowsystems-webhook-actions'), ['status' => 400]);
      }
      $updates['type'] = $type;
    }
    if ($request->has_param('is_enabled')) {
      $updates['is_enabled'] = (bool) $request->get_param('is_enabled');
    }

    if ($request->has_param('config')) {
      $driver = ChannelRegistry::get($type);
      $split  = $this->splitFields($driver, (array) ($request->get_param('config') ?? []), false);
      if (is_wp_error($split)) {
        return $split;
      }
      $updates['config'] = $split['config'];

      // Secrets: only the ones the request actually sent are replaced; the
      // rest of the stored map is kept. A type change drops the old map.
      if (!empty($split['secrets']) || isset($updates['type'])) {
        $existing = [];
        if (!isset($updates['type'])) {
          $row      = $this->repository->findWithSecret($id);
          $existing = $row ? ($this->sender->secrets($row) ?: []) : [];
          if (is_wp_error($existing)) {
            $existing = [];
          }
        }
        $merged                       = array_merge($existing, $split['secrets']);
        $updates['secret_ciphertext'] = $this->sender->encryptSecrets($merged);
        $updates['hint']              = $this->hintFor($merged);
      }
    }

    if (!$this->repository->update($id, $updates)) {
      return new WP_Error('rest_update_failed', __('Failed to update channel.', 'flowsystems-webhook-actions'), ['status' => 500]);
    }

    $this->activityLog->log('notification_channel.updated', 'notification_channel', $id, $updates['name'] ?? $current['name'], [
      'old' => ['name' => $current['name'], 'type' => $current['type'], 'config' => $current['config'], 'is_enabled' => $current['is_enabled']],
      'new' => array_intersect_key($updates, array_flip(['name', 'type', 'config', 'is_enabled'])),
    ]);

    return rest_ensure_response($this->present($this->repository->find($id)));
  }

  public function deleteChannel(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $id      = (int) $request->get_param('id');
    $channel = $this->repository->find($id);
    if (!$channel) {
      return $this->notFound();
    }

    $inUse = $this->repository->countRulesUsing($id);
    if ($inUse > 0 && !(bool) $request->get_param('force')) {
      return new WP_Error('rest_channel_in_use', sprintf(
        /* translators: %d: number of rules */
        _n('This channel is used by %d rule. Remove it from the rule first, or pass force=true.', 'This channel is used by %d rules. Remove it from the rules first, or pass force=true.', $inUse, 'flowsystems-webhook-actions'),
        $inUse
      ), ['status' => 409, 'rules_using' => $inUse]);
    }
    if ($inUse > 0) {
      $this->repository->detachFromRules($id);
    }

    if (!$this->repository->delete($id)) {
      return new WP_Error('rest_delete_failed', __('Failed to delete channel.', 'flowsystems-webhook-actions'), ['status' => 500]);
    }

    $this->activityLog->log('notification_channel.deleted', 'notification_channel', $id, $channel['name'], [
      'old' => ['name' => $channel['name'], 'type' => $channel['type']],
    ]);

    return rest_ensure_response(['deleted' => true, 'detached_rules' => $inUse]);
  }

  /**
   * POST /notifications/channels/{id}/test — send a sample message.
   * Optional: event, webhook_id, log_id, template to render instead of the default.
   */
  public function testChannel(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $id      = (int) $request->get_param('id');
    $channel = $this->repository->findWithSecret($id);
    if (!$channel) {
      return $this->notFound();
    }

    $event = sanitize_key((string) ($request->get_param('event') ?: DeliveryEvents::FAILED_ATTEMPT));
    if (!in_array($event, DeliveryEvents::ALL, true)) {
      $event = DeliveryEvents::FAILED_ATTEMPT;
    }
    $preview  = (new PreviewContext())->build($event, (int) $request->get_param('webhook_id') ?: null, (int) $request->get_param('log_id') ?: null);
    $template = $request->get_param('template');
    $message  = (new MessageBuilder())->buildFromRoots($event, is_array($template) ? $template : null, $preview['roots'], $preview['ctx']);
    $message['title'] = '[' . __('Test', 'flowsystems-webhook-actions') . '] ' . $message['title'];

    $outcome = $this->sender->deliver($message, $channel);
    if ($outcome === true) {
      $this->repository->recordSent($id);
      return rest_ensure_response(['sent' => true, 'message' => $message]);
    }
    $this->repository->recordError($id, $outcome->get_error_message());

    return new WP_Error('fswa_test_failed', $outcome->get_error_message(), ['status' => 502, 'message' => $message]);
  }

  // ===================================================================
  // Helpers
  // ===================================================================

  /**
   * Split a submitted config map into non-secret config (sanitised per field
   * type) and the secret values, validating required fields.
   *
   * @return array{config:array<string,mixed>, secrets:array<string,string>, hint:string}|WP_Error
   */
  private function splitFields($driver, array $submitted, bool $requireAll): array|WP_Error {
    $config  = [];
    $secrets = [];

    foreach ($driver->fields() as $field) {
      $key      = (string) $field['key'];
      $type     = (string) ($field['type'] ?? 'text');
      $required = !empty($field['required']);
      $present  = array_key_exists($key, $submitted);
      $raw      = $submitted[$key] ?? null;

      if ($type === 'secret') {
        $value = is_scalar($raw) ? trim((string) $raw) : '';
        if ($value !== '') {
          $secrets[$key] = $value;
        } elseif ($required && $requireAll) {
          return $this->missing($field);
        }
        continue;
      }

      switch ($type) {
        case 'toggle':
          $value = $present ? (bool) $raw : (bool) ($field['default'] ?? false);
          break;
        case 'number':
          $value = $present && $raw !== '' && $raw !== null ? (int) $raw : ($field['default'] ?? null);
          break;
        case 'credential':
          $value = (int) $raw ?: null;
          break;
        case 'url':
          $value = $present ? esc_url_raw(trim((string) $raw)) : (string) ($field['default'] ?? '');
          break;
        case 'textarea':
          $value = $present ? sanitize_textarea_field((string) $raw) : (string) ($field['default'] ?? '');
          break;
        case 'emails':
          $value = $present ? implode(', ', array_filter(array_map('sanitize_email', preg_split('/[,;\s]+/', (string) $raw) ?: []))) : (string) ($field['default'] ?? '');
          break;
        case 'select':
          $allowed = array_map(static fn(array $o): string => (string) $o['value'], $field['options'] ?? []);
          $value   = $present && in_array((string) $raw, $allowed, true) ? (string) $raw : (string) ($field['default'] ?? ($allowed[0] ?? ''));
          break;
        default:
          $value = $present ? sanitize_text_field((string) $raw) : (string) ($field['default'] ?? '');
      }

      if ($required && ($value === '' || $value === null) && ($requireAll || $present)) {
        return $this->missing($field);
      }
      $config[$key] = $value;
    }

    return ['config' => $config, 'secrets' => $secrets, 'hint' => $this->hintFor($secrets)];
  }

  private function missing(array $field): WP_Error {
    return new WP_Error('rest_missing_field', sprintf(
      /* translators: %s: field label */
      __('%s is required.', 'flowsystems-webhook-actions'),
      (string) $field['label']
    ), ['status' => 400, 'field' => $field['key']]);
  }

  /**
   * Masked tail of the first secret, e.g. "…a1b2".
   *
   * @param array<string, string> $secrets
   */
  private function hintFor(array $secrets): string {
    foreach ($secrets as $value) {
      $value = (string) $value;
      if ($value === '') {
        continue;
      }
      $tail = mb_substr($value, -4);

      return '…' . $tail;
    }

    return '';
  }

  private function present(array $channel): array {
    $channel['has_secret'] = $channel['hint'] !== '';
    $driver                = ChannelRegistry::get((string) $channel['type']);
    $channel['type_label'] = $driver ? $driver->label() : $channel['type'];

    return $channel;
  }

  private function notFound(): WP_Error {
    return new WP_Error('rest_not_found', __('Channel not found.', 'flowsystems-webhook-actions'), ['status' => 404]);
  }
}
