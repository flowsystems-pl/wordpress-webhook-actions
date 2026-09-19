<?php

namespace FlowSystems\WebhookActions\Services\Notifications;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Repositories\NotificationLogRepository;
use FlowSystems\WebhookActions\Repositories\NotificationRuleRepository;
use FlowSystems\WebhookActions\Repositories\WebhookRepository;
use FlowSystems\WebhookActions\Services\QueueService;

/**
 * Listens on `fswa_delivery_event`, decides which rules fire for this webhook,
 * renders each rule's message once, and queues one row per channel. Sending
 * happens later, off the request that delivered the webhook.
 */
class NotificationDispatcher {
  public const SEND_HOOK = 'fswa_send_notifications';

  private NotificationRuleRepository $rules;
  private NotificationLogRepository  $log;
  private WebhookRepository          $webhooks;
  private RuleMatcher                $matcher;
  private MessageBuilder             $builder;

  public function __construct(
    ?NotificationRuleRepository $rules = null,
    ?NotificationLogRepository $log = null,
    ?WebhookRepository $webhooks = null,
    ?RuleMatcher $matcher = null,
    ?MessageBuilder $builder = null
  ) {
    $this->rules    = $rules ?? new NotificationRuleRepository();
    $this->log      = $log ?? new NotificationLogRepository();
    $this->webhooks = $webhooks ?? new WebhookRepository();
    $this->matcher  = $matcher ?? new RuleMatcher();
    $this->builder  = $builder ?? new MessageBuilder();
  }

  /**
   * `fswa_delivery_event` callback.
   *
   * @param array<string, mixed> $ctx Normalized context from DeliveryEvents
   */
  public function handle(string $event, array $ctx): void {
    try {
      $this->route($event, $ctx);
    } catch (\Throwable $e) {
      // A notification bug must never touch a delivery.
      if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[fswa] notification routing failed: ' . $e->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
      }
    }
  }

  /**
   * The rules that apply to one webhook for one event, mode and mutes applied.
   *
   * @return array<int, array<string, mixed>>
   */
  public function rulesFor(int $webhookId, string $event): array {
    $webhook = $this->webhook($webhookId);
    if ($webhook === null) {
      return [];
    }
    $mode  = (string) ($webhook['notifications_mode'] ?? 'inherit');
    $muted = array_map('intval', $webhook['muted_rule_ids'] ?? []);

    if ($mode === 'off') {
      return [];
    }

    $out = [];
    foreach ($this->rulesForEvent($event) as $rule) {
      $owner = $rule['webhook_id'];
      if ($owner === null) {
        if ($mode !== 'inherit' || in_array((int) $rule['id'], $muted, true)) {
          continue;
        }
        $out[] = $rule;
      } elseif ((int) $owner === $webhookId) {
        $out[] = $rule;
      }
    }

    return $out;
  }

  private function route(string $event, array $ctx): void {
    $webhookId = (int) ($ctx['webhook']['id'] ?? 0);
    if ($webhookId <= 0) {
      return;
    }

    $queued = 0;
    foreach ($this->rulesFor($webhookId, $event) as $rule) {
      if (!$this->matcher->matches($rule, $ctx)) {
        continue;
      }
      if (empty($rule['channel_ids'])) {
        continue;
      }

      $ruleId = (int) $rule['id'];

      if ($this->throttled($rule, $webhookId)) {
        $this->log->create([
          'rule_id'    => $ruleId,
          'channel_id' => null,
          'webhook_id' => $webhookId,
          'log_id'     => $ctx['log_id'] ?? null,
          'event'      => $event,
          'status'     => NotificationLogRepository::STATUS_THROTTLED,
          'subject'    => (string) ($ctx['webhook']['name'] ?? ''),
        ]);
        continue;
      }

      $message = $this->builder->build($rule, $ctx);
      $status  = !empty($rule['digest']) ? NotificationLogRepository::STATUS_DIGESTED : NotificationLogRepository::STATUS_PENDING;

      foreach ($rule['channel_ids'] as $channelId) {
        $this->log->create([
          'rule_id'    => $ruleId,
          'channel_id' => (int) $channelId,
          'webhook_id' => $webhookId,
          'log_id'     => $ctx['log_id'] ?? null,
          'event'      => $event,
          'status'     => $status,
          'subject'    => (string) $message['subject'],
          'message'    => $message,
        ]);
        if ($status === NotificationLogRepository::STATUS_PENDING) {
          $queued++;
        }
      }
    }

    if ($queued > 0) {
      $this->scheduleSend();
    }
  }

  /**
   * Book an immediate send. On WP-Cron the spawn happens at request end; with
   * Action Scheduler or a system cron the next tick picks it up anyway.
   */
  private function scheduleSend(): void {
    if (!wp_next_scheduled(self::SEND_HOOK)) {
      wp_schedule_single_event(time(), self::SEND_HOOK);
    }
    (new QueueService())->nudge();
  }

  private function throttled(array $rule, int $webhookId): bool {
    $seconds = (int) ($rule['throttle_seconds'] ?? 0);
    if ($seconds <= 0) {
      return false;
    }
    $last = $this->log->lastSentAt((int) $rule['id'], $webhookId);
    if ($last === null) {
      return false;
    }

    return (time() - strtotime($last . ' UTC')) < $seconds;
  }

  /**
   * Read fresh on every event: this listener lives for the whole process, and
   * under Action Scheduler, External Cron or WP-CLI that process handles many
   * deliveries in a row — a rule or mode changed meanwhile must apply at once.
   *
   * @return array<int, array<string, mixed>>
   */
  private function rulesForEvent(string $event): array {
    return $this->rules->getEnabledForEvent($event);
  }

  private function webhook(int $id): ?array {
    return $this->webhooks->find($id);
  }
}
