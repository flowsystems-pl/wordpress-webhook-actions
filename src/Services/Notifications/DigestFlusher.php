<?php

namespace FlowSystems\WebhookActions\Services\Notifications;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Repositories\NotificationLogRepository;
use FlowSystems\WebhookActions\Repositories\NotificationRuleRepository;

/**
 * Folds the rows a digest rule collected into one message per channel.
 * Hourly rules flush on every run; daily rules flush once a day at the
 * configured hour (site time, option fswa_notification_digest_hour, default 8).
 */
class DigestFlusher {
  public const HOOK = 'fswa_notification_digest';

  private NotificationLogRepository  $log;
  private NotificationRuleRepository $rules;

  public function __construct(?NotificationLogRepository $log = null, ?NotificationRuleRepository $rules = null) {
    $this->log   = $log ?? new NotificationLogRepository();
    $this->rules = $rules ?? new NotificationRuleRepository();
  }

  /**
   * @return int Digest messages queued
   */
  public function flush(bool $force = false): int {
    $dailyDue = $force || (int) wp_date('G') === (int) get_option('fswa_notification_digest_hour', 8);

    $due = [];
    foreach ($this->rules->getGlobal() as $rule) {
      $due[(int) $rule['id']] = $rule;
    }
    // Per-webhook digest rules: gather via the enabled-for-event listing per event.
    foreach (DeliveryEvents::ALL as $event) {
      foreach ($this->rules->getEnabledForEvent($event) as $rule) {
        $due[(int) $rule['id']] = $rule;
      }
    }

    $ruleIds = [];
    foreach ($due as $id => $rule) {
      $digest = (string) ($rule['digest'] ?? '');
      if ($digest === 'hourly' || ($digest === 'daily' && $dailyDue)) {
        $ruleIds[] = $id;
      }
    }
    if (empty($ruleIds)) {
      return 0;
    }

    $rows = $this->log->digestedForRules($ruleIds);
    if (empty($rows)) {
      return 0;
    }

    // Group by rule + channel.
    $buckets = [];
    foreach ($rows as $row) {
      $key             = $row['rule_id'] . ':' . $row['channel_id'];
      $buckets[$key][] = $row;
    }

    $queued = 0;
    foreach ($buckets as $bucket) {
      $first   = $bucket[0];
      $rule    = $due[(int) $first['rule_id']] ?? null;
      $message = $this->compose($rule, $bucket);

      $this->log->create([
        'rule_id'    => (int) $first['rule_id'],
        'channel_id' => (int) $first['channel_id'],
        'webhook_id' => $rule && $rule['webhook_id'] ? (int) $rule['webhook_id'] : null,
        'log_id'     => null,
        'event'      => (string) $first['event'],
        'status'     => NotificationLogRepository::STATUS_PENDING,
        'subject'    => (string) $message['subject'],
        'message'    => $message,
      ]);
      $this->log->markInDigest(array_map(static fn(array $r): int => (int) $r['id'], $bucket));
      $queued++;
    }

    if ($queued > 0) {
      if (!wp_next_scheduled(NotificationDispatcher::SEND_HOOK)) {
        wp_schedule_single_event(time(), NotificationDispatcher::SEND_HOOK);
      }
    }

    return $queued;
  }

  /**
   * @param array<string, mixed>|null $rule
   * @param array<int, array<string, mixed>> $rows
   * @return array<string, mixed>
   */
  private function compose(?array $rule, array $rows): array {
    $count    = count($rows);
    $ruleName = (string) ($rule['name'] ?? __('Notification digest', 'flowsystems-webhook-actions'));
    $lines    = [];
    $byHook   = [];
    $severity = 'info';
    $first    = $rows[0]['message'] ?? [];

    foreach ($rows as $row) {
      $m = is_array($row['message'] ?? null) ? $row['message'] : [];
      $lines[] = '• ' . wp_date('H:i', strtotime($row['created_at'] . ' UTC')) . ' ' . ($m['short'] ?? $m['title'] ?? $row['subject']);
      $name    = (string) ($m['webhook_name'] ?? '');
      if ($name !== '') {
        $byHook[$name] = ($byHook[$name] ?? 0) + 1;
      }
      if (($m['severity'] ?? '') === 'critical') {
        $severity = 'critical';
      } elseif (($m['severity'] ?? '') === 'warning' && $severity !== 'critical') {
        $severity = 'warning';
      }
    }
    if (count($lines) > 40) {
      $extra = count($lines) - 40;
      $lines = array_slice($lines, 0, 40);
      /* translators: %d: number of further notifications */
      $lines[] = sprintf(__('… and %d more', 'flowsystems-webhook-actions'), $extra);
    }

    $fields = [];
    arsort($byHook);
    foreach (array_slice($byHook, 0, 10, true) as $name => $n) {
      $fields[] = ['label' => $name, 'value' => (string) $n];
    }

    $siteName = (string) ($first['site_name'] ?? get_bloginfo('name'));
    /* translators: 1: number of notifications, 2: rule name */
    $title = sprintf(_n('%1$d notification — %2$s', '%1$d notifications — %2$s', $count, 'flowsystems-webhook-actions'), $count, $ruleName);

    return [
      'event'         => (string) ($first['event'] ?? 'digest'),
      'event_label'   => __('Digest', 'flowsystems-webhook-actions'),
      'severity'      => $severity,
      'subject'       => '[' . $siteName . '] ' . $title,
      'title'         => $title,
      'body'          => implode("\n", $lines),
      'short'         => $title,
      'fields'        => $fields,
      'link'          => ['label' => __('Open delivery logs', 'flowsystems-webhook-actions'), 'url' => TemplateContext::adminUrl('/logs')],
      'webhook_id'    => (int) ($rule['webhook_id'] ?? 0),
      'webhook_name'  => count($byHook) === 1 ? (string) array_key_first($byHook) : '',
      'trigger'       => '',
      'trigger_label' => '',
      'log_id'        => null,
      'event_uuid'    => '',
      'site_name'     => $siteName,
      'site_url'      => (string) ($first['site_url'] ?? home_url()),
      'fired_at'      => gmdate('Y-m-d\TH:i:s\Z'),
      'digest_count'  => $count,
    ];
  }
}
