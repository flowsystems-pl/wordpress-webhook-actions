<?php

namespace FlowSystems\WebhookActions\Services\Notifications\Channels;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * PagerDuty Events API v2. Failures trigger an incident keyed by webhook +
 * trigger; a success or recovery on the same key resolves it, so an outage
 * shows up as one incident with a lifecycle rather than a stream of pages.
 */
class PagerDutyDriver extends AbstractHttpDriver {
  private const ENDPOINT = 'https://events.pagerduty.com/v2/enqueue';

  public function type(): string {
    return 'pagerduty';
  }

  public function label(): string {
    return __('PagerDuty', 'flowsystems-webhook-actions');
  }

  public function description(): string {
    return __('Events API v2 integration key. Failures open an incident; a later success resolves it.', 'flowsystems-webhook-actions');
  }

  public function fields(): array {
    return [
      ['key' => 'routing_key', 'label' => __('Integration (routing) key', 'flowsystems-webhook-actions'), 'type' => 'secret', 'required' => true],
      ['key' => 'resolve_on_success', 'label' => __('Resolve the incident when the webhook succeeds again', 'flowsystems-webhook-actions'), 'type' => 'toggle', 'required' => false, 'default' => true, 'help' => __('Needs a rule on the "recovered" or "success" event pointing at this channel.', 'flowsystems-webhook-actions')],
      ['key' => 'severity', 'label' => __('Severity', 'flowsystems-webhook-actions'), 'type' => 'select', 'required' => false, 'default' => 'auto', 'options' => [
        ['value' => 'auto', 'label' => __('By event (permanent failure = critical, attempt = warning)', 'flowsystems-webhook-actions')],
        ['value' => 'critical', 'label' => 'critical'],
        ['value' => 'error', 'label' => 'error'],
        ['value' => 'warning', 'label' => 'warning'],
        ['value' => 'info', 'label' => 'info'],
      ]],
    ];
  }

  public function send(array $message, array $config, array $secrets): true|WP_Error {
    $key = $this->requireSecret($secrets, 'routing_key', __('Integration (routing) key', 'flowsystems-webhook-actions'));
    if (is_wp_error($key)) {
      return $key;
    }

    $dedup   = 'fswa-' . (int) $message['webhook_id'] . '-' . md5((string) $message['trigger']);
    $isOk    = in_array($message['severity'] ?? '', ['success'], true);
    $resolve = $isOk && (bool) $this->cfg($config, 'resolve_on_success', true);

    if ($isOk && !$resolve) {
      // A success with resolution off has nothing to page about.
      return true;
    }

    $severity = (string) $this->cfg($config, 'severity', 'auto');
    if ($severity === 'auto') {
      $severity = match ($message['severity'] ?? 'neutral') {
        'critical' => 'critical',
        'warning'  => 'warning',
        'success'  => 'info',
        default    => 'info',
      };
    }

    $details = [];
    foreach ($message['fields'] ?? [] as $field) {
      $details[(string) $field['label']] = (string) $field['value'];
    }
    $details['body'] = (string) $message['body'];

    $body = [
      'routing_key'  => $key,
      'event_action' => $resolve ? 'resolve' : 'trigger',
      'dedup_key'    => $dedup,
      'payload'      => [
        'summary'        => $this->truncate((string) $message['title'], 1024),
        'source'         => (string) ($message['site_url'] ?: $message['site_name']),
        'severity'       => $severity,
        'component'      => (string) $message['webhook_name'],
        'group'          => 'webhook-actions',
        'class'          => (string) $message['event'],
        'custom_details' => $details,
      ],
    ];
    if (!empty($message['link']['url'])) {
      $body['links'] = [['href' => (string) $message['link']['url'], 'text' => (string) $message['link']['label']]];
    }

    $result = $this->post(self::ENDPOINT, $body);

    return $this->outcome($result, 200, 202);
  }
}
