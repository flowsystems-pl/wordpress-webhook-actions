<?php

namespace FlowSystems\WebhookActions\Services\Notifications;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Repositories\ChainLinkRepository;
use FlowSystems\WebhookActions\Repositories\ChainRepository;
use FlowSystems\WebhookActions\Services\Ai\PayloadRedactor;

/**
 * Turns a delivery-event context into the roots a template can address:
 *
 *   webhook.*   event.*   delivery.*   payload.*   original.*   args.*   site.*
 *
 * Payload roots pass through the redactor first, so a template can never leak
 * a value the AI-facing reads would already hide (passwords, tokens).
 */
class TemplateContext {
  public const ROOTS = ['webhook', 'event', 'delivery', 'payload', 'original', 'args', 'site'];

  public const ADMIN_PAGE = 'fswa-webhook-actions';

  /**
   * @param array<string, mixed> $ctx A normalized DeliveryEvents context.
   * @return array<string, mixed>
   */
  public static function fromDeliveryContext(array $ctx): array {
    $webhookId = (int) ($ctx['webhook']['id'] ?? 0);
    $payload   = is_array($ctx['payload'] ?? null) ? PayloadRedactor::redact($ctx['payload']) : [];
    $original  = is_array($ctx['original_payload'] ?? null) ? PayloadRedactor::redact($ctx['original_payload']) : $payload;
    $trigger   = (string) ($ctx['trigger'] ?? '');
    $logId     = isset($ctx['log_id']) ? (int) $ctx['log_id'] : null;

    return [
      'webhook' => [
        'id'           => $webhookId,
        'uuid'         => (string) ($ctx['webhook']['uuid'] ?? ''),
        'name'         => (string) ($ctx['webhook']['name'] ?? ''),
        'endpoint_url' => (string) ($ctx['webhook']['endpoint_url'] ?? ''),
        'edit_url'     => self::adminUrl('/webhooks/' . $webhookId),
        'logs_url'     => self::adminUrl('/webhooks/' . $webhookId . '/logs'),
      ],
      'event' => [
        'uuid'          => (string) ($ctx['event_uuid'] ?? ''),
        'timestamp'     => (string) ($ctx['event_timestamp'] ?? ''),
        'type'          => (string) ($ctx['event'] ?? ''),
        'type_label'    => self::eventLabel((string) ($ctx['event'] ?? '')),
        'trigger'       => $trigger,
        'trigger_label' => self::triggerLabel($trigger),
      ],
      'delivery' => [
        'attempt'         => (int) ($ctx['attempt'] ?? 1),
        'max_attempts'    => $ctx['max_attempts'] ?? null,
        'next_attempt_at' => $ctx['next_attempt_at'] ?? null,
        'http_code'       => $ctx['http_code'] ?? null,
        'error_message'   => $ctx['error_message'] ?? null,
        'response_body'   => $ctx['response_body'] ?? null,
        'duration_ms'     => $ctx['duration_ms'] ?? null,
        'request_url'     => (string) ($ctx['request_url'] ?? ''),
        'status'          => self::statusLabel($ctx),
        'reason'          => $ctx['reason'] ?? null,
        'is_test'         => (bool) ($ctx['is_test'] ?? false),
        'log_id'          => $logId,
        'log_url'         => $logId ? self::adminUrl('/logs?log_id=' . $logId) : self::adminUrl('/logs'),
      ],
      'payload'  => $payload,
      'original' => $original,
      'args'     => is_array($original['args'] ?? null) ? $original['args'] : [],
      'site'     => [
        'name'      => (string) get_bloginfo('name'),
        'url'       => home_url(),
        'admin_url' => self::adminUrl('/'),
      ],
    ];
  }

  /**
   * Roots for a preview or an AI draft when no delivery has happened yet:
   * the webhook row plus its captured example, with a plausible delivery.
   *
   * @param array<string, mixed>|null $webhook
   * @param array<string, mixed>|null $example Captured example payload (envelope shape)
   * @return array<string, mixed>
   */
  public static function sample(string $event, ?array $webhook, ?array $example, ?array $mapped = null): array {
    $failure = in_array($event, [DeliveryEvents::FAILED_ATTEMPT, DeliveryEvents::RETRY_SCHEDULED, DeliveryEvents::PERMANENTLY_FAILED], true);
    $ctx     = DeliveryEvents::normalize($event, [
      'webhook'          => $webhook ?? ['id' => 0, 'name' => __('Sample webhook', 'flowsystems-webhook-actions'), 'endpoint_url' => 'https://example.com/hook'],
      'trigger'          => (string) ($example['hook'] ?? ($webhook['triggers'][0] ?? 'sample_trigger')),
      'log_id'           => null,
      'attempt'          => $event === DeliveryEvents::PERMANENTLY_FAILED ? 5 : ($event === DeliveryEvents::RECOVERED ? 2 : 1),
      'max_attempts'     => 5,
      'next_attempt_at'  => $event === DeliveryEvents::RETRY_SCHEDULED ? gmdate('Y-m-d H:i:s', time() + 60) : null,
      'http_code'        => $failure ? 502 : ($event === DeliveryEvents::SKIPPED ? null : 200),
      'error_message'    => $failure ? 'HTTP 502: Bad Gateway' : ($event === DeliveryEvents::SKIPPED ? 'Condition not met' : null),
      'response_body'    => $failure ? '{"error":"upstream unavailable"}' : '{"ok":true}',
      'duration_ms'      => 812,
      'is_test'          => false,
      'payload'          => $mapped ?? ($example ?? []),
      'original_payload' => $example,
      'reason'           => $event === DeliveryEvents::PERMANENTLY_FAILED ? DeliveryEvents::REASON_EXHAUSTED : null,
      'had_failures'     => $event === DeliveryEvents::RECOVERED,
    ]);

    return self::fromDeliveryContext($ctx);
  }

  public static function adminUrl(string $hashPath): string {
    return admin_url('admin.php?page=' . self::ADMIN_PAGE . '#' . $hashPath);
  }

  public static function eventLabel(string $event): string {
    return match ($event) {
      DeliveryEvents::SUCCESS            => __('Delivered', 'flowsystems-webhook-actions'),
      DeliveryEvents::RECOVERED          => __('Recovered', 'flowsystems-webhook-actions'),
      DeliveryEvents::FAILED_ATTEMPT     => __('Attempt failed', 'flowsystems-webhook-actions'),
      DeliveryEvents::RETRY_SCHEDULED    => __('Retry scheduled', 'flowsystems-webhook-actions'),
      DeliveryEvents::PERMANENTLY_FAILED => __('Permanently failed', 'flowsystems-webhook-actions'),
      DeliveryEvents::SKIPPED            => __('Skipped', 'flowsystems-webhook-actions'),
      default                            => $event,
    };
  }

  /**
   * A readable name for a trigger. Chain-link triggers become
   * "Chain: <chain> (<source> → <target>)"; plain hooks stay as they are.
   */
  public static function triggerLabel(string $trigger): string {
    if (!preg_match('/^fswa_chain_link:(\d+)$/', $trigger, $m)) {
      return $trigger;
    }
    try {
      $link = (new ChainLinkRepository())->find((int) $m[1]);
      if (!$link) {
        return $trigger;
      }
      $chain = (new ChainRepository())->find((int) $link['chain_id']);
      $name  = $chain['name'] ?? ('#' . $link['chain_id']);
      return sprintf(
        /* translators: 1: chain name, 2: source webhook name, 3: target webhook name */
        __('Chain: %1$s (%2$s → %3$s)', 'flowsystems-webhook-actions'),
        $name,
        $link['source_webhook_name'] ?? ('#' . $link['source_webhook_id']),
        $link['target_webhook_name'] ?? ('#' . $link['target_webhook_id'])
      );
    } catch (\Throwable $e) {
      return $trigger;
    }
  }

  private static function statusLabel(array $ctx): string {
    $event = (string) ($ctx['event'] ?? '');
    if ($event === DeliveryEvents::PERMANENTLY_FAILED) {
      return ($ctx['reason'] ?? '') === DeliveryEvents::REASON_NON_RETRYABLE
        ? __('Permanently failed (not retryable)', 'flowsystems-webhook-actions')
        : __('Permanently failed (out of attempts)', 'flowsystems-webhook-actions');
    }

    return self::eventLabel($event);
  }
}
