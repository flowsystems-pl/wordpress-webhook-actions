<?php

namespace FlowSystems\WebhookActions\Api;

defined('ABSPATH') || exit;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use FlowSystems\WebhookActions\Repositories\NotificationChannelRepository;
use FlowSystems\WebhookActions\Repositories\NotificationLogRepository;
use FlowSystems\WebhookActions\Repositories\NotificationRuleRepository;
use FlowSystems\WebhookActions\Repositories\WebhookRepository;
use FlowSystems\WebhookActions\Services\ActivityLogService;
use FlowSystems\WebhookActions\Services\Notifications\DeliveryEvents;
use FlowSystems\WebhookActions\Services\Notifications\MessageBuilder;
use FlowSystems\WebhookActions\Services\Notifications\NotificationSender;
use FlowSystems\WebhookActions\Services\Notifications\PreviewContext;
use FlowSystems\WebhookActions\Services\Notifications\RuleInput;
use FlowSystems\WebhookActions\Services\Notifications\TemplateDrafter;
use FlowSystems\WebhookActions\Services\Notifications\TemplateLinter;
use FlowSystems\WebhookActions\Services\Notifications\TemplateRenderer;
use FlowSystems\WebhookActions\Services\Notifications\TemplateContext;

/**
 * Notification rules (site-wide and per webhook), template preview and lint,
 * the AI draft endpoint, and the sent log.
 */
class NotificationRulesController extends WP_REST_Controller {
  protected $namespace = 'fswa/v1';

  private NotificationRuleRepository    $rules;
  private NotificationChannelRepository $channels;
  private NotificationLogRepository     $log;
  private ActivityLogService            $activityLog;

  public function __construct() {
    $this->rules       = new NotificationRuleRepository();
    $this->channels    = new NotificationChannelRepository();
    $this->log         = new NotificationLogRepository();
    $this->activityLog = new ActivityLogService();
  }

  public function registerRoutes(): void {
    register_rest_route($this->namespace, '/notifications/rules', [
      ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'listRules'], 'permission_callback' => [$this, 'readPermissions']],
      ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'createRule'], 'permission_callback' => [$this, 'writePermissions']],
    ]);
    register_rest_route($this->namespace, '/notifications/rules/reorder', [
      'methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'reorder'], 'permission_callback' => [$this, 'writePermissions'],
    ]);
    register_rest_route($this->namespace, '/notifications/rules/(?P<id>[\d]+)', [
      ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'getRule'], 'permission_callback' => [$this, 'readPermissions']],
      ['methods' => WP_REST_Server::EDITABLE, 'callback' => [$this, 'updateRule'], 'permission_callback' => [$this, 'writePermissions']],
      ['methods' => WP_REST_Server::DELETABLE, 'callback' => [$this, 'deleteRule'], 'permission_callback' => [$this, 'writePermissions']],
    ]);
    register_rest_route($this->namespace, '/notifications/rules/(?P<id>[\d]+)/test', [
      'methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'testRule'], 'permission_callback' => [$this, 'writePermissions'],
    ]);
    register_rest_route($this->namespace, '/notifications/preview', [
      'methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'preview'], 'permission_callback' => [$this, 'readPermissions'],
    ]);
    register_rest_route($this->namespace, '/notifications/ai-draft', [
      'methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'aiDraft'], 'permission_callback' => [$this, 'writePermissions'],
    ]);
    register_rest_route($this->namespace, '/notifications/log', [
      'methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'sentLog'], 'permission_callback' => [$this, 'readPermissions'],
    ]);
    register_rest_route($this->namespace, '/notifications/log/(?P<id>[\d]+)/resend', [
      'methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'resend'], 'permission_callback' => [$this, 'writePermissions'],
    ]);
  }

  public function readPermissions(WP_REST_Request $request): bool|WP_Error {
    return AuthHelper::dualAuth($request, AuthHelper::SCOPE_READ);
  }

  public function writePermissions(WP_REST_Request $request): bool|WP_Error {
    return AuthHelper::dualAuth($request, AuthHelper::SCOPE_FULL);
  }

  // ===================================================================
  // Rules
  // ===================================================================

  /**
   * GET /notifications/rules            site-wide rules
   * GET /notifications/rules?webhook_id  that webhook's own rules
   * GET /notifications/rules?scope=all   everything
   */
  public function listRules(WP_REST_Request $request): WP_REST_Response {
    $webhookId = (int) $request->get_param('webhook_id');
    $scope     = (string) $request->get_param('scope');

    if ($scope === 'all') {
      $rules = $this->rules->getGlobal();
      foreach ((new WebhookRepository())->getAll() as $webhook) {
        $rules = array_merge($rules, $this->rules->getByWebhook((int) $webhook['id']));
      }
    } elseif ($webhookId > 0) {
      $rules = $this->rules->getByWebhook($webhookId);
    } else {
      $rules = $this->rules->getGlobal();
    }

    return rest_ensure_response(array_map([$this, 'present'], $rules));
  }

  public function getRule(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $rule = $this->rules->find((int) $request->get_param('id'));

    return $rule ? rest_ensure_response($this->present($rule)) : $this->notFound();
  }

  public function createRule(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $data = RuleInput::fromRequest($request->get_params(), true);
    if (is_wp_error($data)) {
      return $data;
    }
    $bad = $this->unknownChannels($data['channel_ids']);
    if ($bad) {
      return $bad;
    }

    $id = $this->rules->create($data);
    if (!$id) {
      return new WP_Error('rest_create_failed', __('Failed to create rule.', 'flowsystems-webhook-actions'), ['status' => 500]);
    }

    $rule = $this->rules->find($id);
    $this->activityLog->log('notification_rule.created', 'notification_rule', $id, $rule['name'] ?: $rule['event'], ['new' => $rule]);

    return rest_ensure_response($this->present($rule));
  }

  public function updateRule(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $id      = (int) $request->get_param('id');
    $current = $this->rules->find($id);
    if (!$current) {
      return $this->notFound();
    }

    $params = $request->get_params();
    unset($params['id']);
    $data = RuleInput::fromRequest($params, false);
    if (is_wp_error($data)) {
      return $data;
    }
    if (isset($data['channel_ids'])) {
      $bad = $this->unknownChannels($data['channel_ids']);
      if ($bad) {
        return $bad;
      }
    }

    if (!$this->rules->update($id, $data)) {
      return new WP_Error('rest_update_failed', __('Failed to update rule.', 'flowsystems-webhook-actions'), ['status' => 500]);
    }

    $rule = $this->rules->find($id);
    $this->activityLog->log('notification_rule.updated', 'notification_rule', $id, $rule['name'] ?: $rule['event'], ['old' => $current, 'new' => $rule]);

    return rest_ensure_response($this->present($rule));
  }

  public function deleteRule(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $id   = (int) $request->get_param('id');
    $rule = $this->rules->find($id);
    if (!$rule) {
      return $this->notFound();
    }
    if (!$this->rules->delete($id)) {
      return new WP_Error('rest_delete_failed', __('Failed to delete rule.', 'flowsystems-webhook-actions'), ['status' => 500]);
    }
    $this->activityLog->log('notification_rule.deleted', 'notification_rule', $id, $rule['name'] ?: $rule['event'], ['old' => $rule]);

    return rest_ensure_response(['deleted' => true]);
  }

  /**
   * POST /notifications/rules/reorder {ids: [...]}
   */
  public function reorder(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $ids = $request->get_param('ids');
    if (!is_array($ids)) {
      return new WP_Error('rest_invalid_param', __('ids must be a list.', 'flowsystems-webhook-actions'), ['status' => 400]);
    }
    $this->rules->reorder(array_map('intval', $ids));

    return rest_ensure_response(['ok' => true]);
  }

  /**
   * POST /notifications/rules/{id}/test — render against a log or the
   * webhook's example and send to every channel of the rule.
   */
  public function testRule(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $rule = $this->rules->find((int) $request->get_param('id'));
    if (!$rule) {
      return $this->notFound();
    }
    $webhookId = (int) ($request->get_param('webhook_id') ?: $rule['webhook_id'] ?: 0) ?: null;
    $preview   = (new PreviewContext())->build($rule['event'], $webhookId, (int) $request->get_param('log_id') ?: null);
    $message   = (new MessageBuilder())->buildFromRoots($rule['event'], $rule['template'], $preview['roots'], $preview['ctx']);
    $message['title'] = '[' . __('Test', 'flowsystems-webhook-actions') . '] ' . $message['title'];

    $sender  = new NotificationSender();
    $results = [];
    foreach ($this->channels->findManyWithSecrets($rule['channel_ids']) as $channelId => $channel) {
      $outcome = $sender->deliver($message, $channel);
      if ($outcome === true) {
        $this->channels->recordSent($channelId);
      } else {
        $this->channels->recordError($channelId, $outcome->get_error_message());
      }
      $results[] = [
        'channel_id' => $channelId,
        'channel'    => $channel['name'],
        'sent'       => $outcome === true,
        'error'      => $outcome === true ? null : $outcome->get_error_message(),
      ];
    }

    return rest_ensure_response(['results' => $results, 'message' => $message, 'source' => $preview['source']]);
  }

  // ===================================================================
  // Preview, lint, AI draft
  // ===================================================================

  /**
   * POST /notifications/preview {event, template?, webhook_id?, log_id?, channel_types?}
   * → rendered message, lint result, and the available field paths.
   */
  public function preview(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $event = sanitize_key((string) $request->get_param('event'));
    if (!in_array($event, DeliveryEvents::ALL, true)) {
      return new WP_Error('rest_invalid_param', __('Unknown event.', 'flowsystems-webhook-actions'), ['status' => 400]);
    }
    $template = $request->get_param('template');
    $template = is_array($template) ? $template : null;
    $types    = array_map('sanitize_key', (array) ($request->get_param('channel_types') ?? []));

    $preview = (new PreviewContext())->build($event, (int) $request->get_param('webhook_id') ?: null, (int) $request->get_param('log_id') ?: null);
    $message = (new MessageBuilder())->buildFromRoots($event, $template, $preview['roots'], $preview['ctx']);
    $lint    = (new TemplateLinter())->lint($template ?? [], $preview['example'], $preview['mapped'], $types);

    return rest_ensure_response([
      'message' => $message,
      'lint'    => $lint,
      'source'  => $preview['source'],
      'paths'   => $this->availablePaths($preview['roots']),
    ]);
  }

  /**
   * POST /notifications/ai-draft {event, channel_types[], webhook_id?, log_id?, instructions?, current_template?}
   */
  public function aiDraft(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $event = sanitize_key((string) $request->get_param('event'));
    if (!in_array($event, DeliveryEvents::ALL, true)) {
      return new WP_Error('rest_invalid_param', __('Unknown event.', 'flowsystems-webhook-actions'), ['status' => 400]);
    }
    $types   = array_map('sanitize_key', (array) ($request->get_param('channel_types') ?? []));
    $preview = (new PreviewContext())->build($event, (int) $request->get_param('webhook_id') ?: null, (int) $request->get_param('log_id') ?: null);
    $current = $request->get_param('current_template');

    $draft = (new TemplateDrafter())->draft([
      'event'         => $event,
      'channel_types' => $types,
      'instructions'  => sanitize_textarea_field((string) ($request->get_param('instructions') ?? '')),
      'current'       => is_array($current) ? $current : null,
      'roots'         => $preview['roots'],
      'example'       => $preview['example'],
      'mapped'        => $preview['mapped'],
      'webhook'       => $preview['webhook'],
    ]);
    if (is_wp_error($draft)) {
      return $draft;
    }

    $message = (new MessageBuilder())->buildFromRoots($event, $draft['template'], $preview['roots'], $preview['ctx']);

    $this->activityLog->log('notification_template.drafted', 'notification_rule', null, $preview['webhook']['name'] ?? null, [
      'meta' => ['event' => $event, 'provider' => $draft['provider'], 'channel_types' => $types],
    ]);

    return rest_ensure_response([
      'template' => $draft['template'],
      'lint'     => $draft['lint'],
      'message'  => $message,
      'provider' => $draft['provider'],
      'source'   => $preview['source'],
    ]);
  }

  // ===================================================================
  // Sent log
  // ===================================================================

  public function sentLog(WP_REST_Request $request): WP_REST_Response {
    $filters = [];
    foreach (['webhook_id', 'rule_id', 'channel_id', 'log_id'] as $key) {
      if ((int) $request->get_param($key) > 0) {
        $filters[$key] = (int) $request->get_param($key);
      }
    }
    if ($request->get_param('status')) {
      $filters['status'] = sanitize_key((string) $request->get_param('status'));
    }
    $page    = max(1, (int) ($request->get_param('page') ?: 1));
    $perPage = min(100, max(1, (int) ($request->get_param('per_page') ?: 20)));

    $result = $this->log->getPaginated($filters, $page, $perPage);

    return rest_ensure_response([
      'items'    => $result['items'],
      'total'    => $result['total'],
      'page'     => $page,
      'per_page' => $perPage,
      'pending'  => $this->log->countPending(),
    ]);
  }

  /**
   * POST /notifications/log/{id}/resend — put a failed row back in the queue.
   */
  public function resend(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $row = $this->log->find((int) $request->get_param('id'));
    if (!$row) {
      return new WP_Error('rest_not_found', __('Notification not found.', 'flowsystems-webhook-actions'), ['status' => 404]);
    }
    if (!is_array($row['message'])) {
      return new WP_Error('rest_no_message', __('This entry has no stored message to resend.', 'flowsystems-webhook-actions'), ['status' => 409]);
    }
    $this->log->markFailed((int) $row['id'], '', true);
    (new NotificationSender())->flushPending(10);

    return rest_ensure_response(['queued' => true, 'entry' => $this->log->find((int) $row['id'])]);
  }

  // ===================================================================
  // Helpers
  // ===================================================================

  private function unknownChannels(array $ids): ?WP_Error {
    $known = array_keys($this->channels->findManyWithSecrets($ids));
    $missing = array_values(array_diff(array_map('intval', $ids), $known));
    if (!empty($missing)) {
      return new WP_Error('rest_unknown_channel', sprintf(
        /* translators: %s: comma-separated channel IDs */
        __('Unknown channel id(s): %s', 'flowsystems-webhook-actions'),
        implode(', ', $missing)
      ), ['status' => 400]);
    }

    return null;
  }

  /**
   * Dotted paths available to a template, for the field picker: delivery-level
   * leaves plus every leaf of the example payload.
   *
   * @return array<int, array{path:string, sample:string}>
   */
  private function availablePaths(array $roots): array {
    $out = [];
    foreach (['webhook', 'event', 'delivery', 'site'] as $root) {
      foreach ($roots[$root] ?? [] as $key => $value) {
        $out[] = ['path' => $root . '.' . $key, 'sample' => $this->sampleText($value)];
      }
    }
    foreach (['payload', 'args'] as $root) {
      if (!is_array($roots[$root] ?? null)) {
        continue;
      }
      foreach ($this->flatten($roots[$root], $root) as $path => $value) {
        $out[] = ['path' => $path, 'sample' => $value];
      }
    }

    return array_slice($out, 0, 400);
  }

  /**
   * @return array<string, string>
   */
  private function flatten(array $data, string $prefix, int $depth = 0): array {
    $out = [];
    foreach ($data as $key => $value) {
      $path = $prefix . '.' . $key;
      if (is_array($value) && $depth < 5 && !empty($value)) {
        $out += $this->flatten($value, $path, $depth + 1);
        continue;
      }
      $out[$path] = $this->sampleText($value);
    }

    return $out;
  }

  private function sampleText(mixed $value): string {
    if (is_array($value)) {
      return empty($value) ? '[]' : '[…]';
    }
    if (is_bool($value)) {
      return $value ? 'true' : 'false';
    }
    if ($value === null) {
      return '';
    }

    return mb_substr(str_replace(["\n", "\r"], ' ', (string) $value), 0, 80);
  }

  private function present(array $rule): array {
    $rule['event_label'] = TemplateContext::eventLabel($rule['event']);

    return $rule;
  }

  private function notFound(): WP_Error {
    return new WP_Error('rest_not_found', __('Rule not found.', 'flowsystems-webhook-actions'), ['status' => 404]);
  }
}
