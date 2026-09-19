<?php

namespace FlowSystems\WebhookActions\Services\Notifications\Channels;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * Microsoft Teams through a Workflows ("Post to a channel when a webhook
 * request is received") URL. Office 365 Connectors were retired in May 2026,
 * so only the Adaptive Card envelope Workflows accepts is produced here.
 */
class TeamsDriver extends AbstractHttpDriver {
  public function type(): string {
    return 'teams';
  }

  public function label(): string {
    return __('Microsoft Teams', 'flowsystems-webhook-actions');
  }

  public function description(): string {
    return __('Workflows webhook URL (Teams → Workflows → "Post to a channel when a webhook request is received").', 'flowsystems-webhook-actions');
  }

  public function fields(): array {
    return [
      ['key' => 'webhook_url', 'label' => __('Workflow URL', 'flowsystems-webhook-actions'), 'type' => 'secret', 'required' => true, 'placeholder' => 'https://….logic.azure.com/workflows/…'],
    ];
  }

  public function send(array $message, array $config, array $secrets): true|WP_Error {
    $url = $this->requireSecret($secrets, 'webhook_url', __('Workflow URL', 'flowsystems-webhook-actions'));
    if (is_wp_error($url)) {
      return $url;
    }

    $facts = [];
    foreach ($message['fields'] ?? [] as $field) {
      $facts[] = ['title' => (string) $field['label'], 'value' => $this->truncate((string) $field['value'], 500)];
    }

    $body = [
      ['type' => 'TextBlock', 'text' => (string) $message['event_label'], 'size' => 'Small', 'weight' => 'Bolder', 'color' => $this->accent($message), 'spacing' => 'None'],
      ['type' => 'TextBlock', 'text' => (string) $message['title'], 'size' => 'Large', 'weight' => 'Bolder', 'wrap' => true],
    ];
    if (!empty($message['body'])) {
      $body[] = ['type' => 'TextBlock', 'text' => (string) $message['body'], 'wrap' => true];
    }
    if (!empty($facts)) {
      $body[] = ['type' => 'FactSet', 'facts' => $facts];
    }

    $card = [
      '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
      'type'    => 'AdaptiveCard',
      'version' => '1.4',
      'msteams' => ['width' => 'Full'],
      'body'    => $body,
    ];
    if (!empty($message['link']['url'])) {
      $card['actions'] = [['type' => 'Action.OpenUrl', 'title' => (string) $message['link']['label'], 'url' => (string) $message['link']['url']]];
    }

    $result = $this->post($url, [
      'type'        => 'message',
      'attachments' => [[
        'contentType' => 'application/vnd.microsoft.card.adaptive',
        'contentUrl'  => null,
        'content'     => $card,
      ]],
    ]);

    // Workflows answers 202 Accepted.
    return $this->outcome($result, 200, 299);
  }

  private function accent(array $message): string {
    return match ($message['severity'] ?? 'neutral') {
      'success'  => 'Good',
      'warning'  => 'Warning',
      'critical' => 'Attention',
      default    => 'Accent',
    };
  }
}
