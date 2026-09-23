<?php

namespace FlowSystems\WebhookActions\Services\Notifications\Channels;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * Slack incoming webhook. Block Kit with a `text` fallback (attachments are
 * legacy). A Workflow Builder webhook takes the same POST.
 */
class SlackDriver extends AbstractHttpDriver {
  public function type(): string {
    return 'slack';
  }

  public function label(): string {
    return __('Slack', 'flowsystems-webhook-actions');
  }

  public function description(): string {
    return __('Incoming webhook URL from a Slack app. Posts a Block Kit card.', 'flowsystems-webhook-actions');
  }

  public function fields(): array {
    return [
      ['key' => 'webhook_url', 'label' => __('Incoming webhook URL', 'flowsystems-webhook-actions'), 'type' => 'secret', 'required' => true, 'placeholder' => 'https://hooks.slack.com/services/…'],
    ];
  }

  public function send(array $message, array $config, array $secrets): true|WP_Error {
    $url = $this->requireSecret($secrets, 'webhook_url', __('Incoming webhook URL', 'flowsystems-webhook-actions'));
    if (is_wp_error($url)) {
      return $url;
    }

    $mrk = static fn(string $s): string => str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);

    $blocks = [
      ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => $this->truncate((string) $message['title'], 150), 'emoji' => true]],
    ];
    if (!empty($message['body'])) {
      $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $this->truncate($mrk((string) $message['body']), 3000)]];
    }
    $fields = [];
    foreach (array_slice($message['fields'] ?? [], 0, 10) as $field) {
      $fields[] = ['type' => 'mrkdwn', 'text' => '*' . $mrk((string) $field['label']) . "*\n" . $this->truncate($mrk((string) $field['value']), 500)];
    }
    if (!empty($fields)) {
      $blocks[] = ['type' => 'section', 'fields' => $fields];
    }
    if (!empty($message['link']['url'])) {
      $blocks[] = ['type' => 'actions', 'elements' => [[
        'type' => 'button',
        'text' => ['type' => 'plain_text', 'text' => (string) $message['link']['label']],
        'url'  => (string) $message['link']['url'],
      ]]];
    }
    $blocks[] = ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => $mrk((string) $message['site_name']) . ' · ' . $mrk((string) $message['event_label'])]]];

    $result = $this->post($url, [
      'text'   => $this->truncate((string) $message['short'], 500),
      'blocks' => $blocks,
    ]);

    return $this->outcome($result);
  }
}
