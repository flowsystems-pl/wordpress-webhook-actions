<?php

namespace FlowSystems\WebhookActions\Services\Notifications\Channels;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * Google Chat space webhook: cardsV2 with a text fallback.
 */
class GoogleChatDriver extends AbstractHttpDriver {
  public function type(): string {
    return 'gchat';
  }

  public function label(): string {
    return __('Google Chat', 'flowsystems-webhook-actions');
  }

  public function description(): string {
    return __('Space webhook URL (Space → Apps & integrations → Webhooks).', 'flowsystems-webhook-actions');
  }

  public function fields(): array {
    return [
      ['key' => 'webhook_url', 'label' => __('Webhook URL', 'flowsystems-webhook-actions'), 'type' => 'secret', 'required' => true, 'placeholder' => 'https://chat.googleapis.com/v1/spaces/…'],
      ['key' => 'thread_key', 'label' => __('Thread key', 'flowsystems-webhook-actions'), 'type' => 'text', 'required' => false, 'help' => __('Optional. Same key = same thread; use {{ webhook.id }} to thread per webhook.', 'flowsystems-webhook-actions')],
    ];
  }

  public function send(array $message, array $config, array $secrets): true|WP_Error {
    $url = $this->requireSecret($secrets, 'webhook_url', __('Webhook URL', 'flowsystems-webhook-actions'));
    if (is_wp_error($url)) {
      return $url;
    }

    $widgets = [];
    if (!empty($message['body'])) {
      $widgets[] = ['textParagraph' => ['text' => htmlspecialchars((string) $message['body'], ENT_QUOTES)]];
    }
    foreach ($message['fields'] ?? [] as $field) {
      $widgets[] = ['decoratedText' => ['topLabel' => (string) $field['label'], 'text' => htmlspecialchars($this->truncate((string) $field['value'], 500), ENT_QUOTES), 'wrapText' => true]];
    }
    if (!empty($message['link']['url'])) {
      $widgets[] = ['buttonList' => ['buttons' => [['text' => (string) $message['link']['label'], 'onClick' => ['openLink' => ['url' => (string) $message['link']['url']]]]]]];
    }

    $body = [
      'text'    => $this->truncate((string) $message['short'], 4000),
      'cardsV2' => [[
        'cardId' => 'fswa-' . md5((string) ($message['event_uuid'] ?? '') . (string) ($message['log_id'] ?? '')),
        'card'   => [
          'header'   => ['title' => (string) $message['title'], 'subtitle' => (string) $message['event_label'] . ' · ' . $message['site_name']],
          'sections' => [['widgets' => $widgets]],
        ],
      ]],
    ];

    $threadKey = trim((string) $this->cfg($config, 'thread_key'));
    if ($threadKey !== '') {
      $threadKey = str_replace('{{ webhook.id }}', (string) $message['webhook_id'], $threadKey);
      $url      .= (strpos($url, '?') === false ? '?' : '&') . 'threadKey=' . rawurlencode($threadKey) . '&messageReplyOption=REPLY_MESSAGE_FALLBACK_TO_NEW_THREAD';
    }

    return $this->outcome($this->post($url, $body));
  }
}
