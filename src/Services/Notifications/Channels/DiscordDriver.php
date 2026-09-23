<?php

namespace FlowSystems\WebhookActions\Services\Notifications\Channels;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * Discord webhook: one embed coloured by severity, facts as inline fields.
 */
class DiscordDriver extends AbstractHttpDriver {
  public function type(): string {
    return 'discord';
  }

  public function label(): string {
    return __('Discord', 'flowsystems-webhook-actions');
  }

  public function description(): string {
    return __('Channel webhook URL from Discord → Integrations. Posts an embed.', 'flowsystems-webhook-actions');
  }

  public function fields(): array {
    return [
      ['key' => 'webhook_url', 'label' => __('Webhook URL', 'flowsystems-webhook-actions'), 'type' => 'secret', 'required' => true, 'placeholder' => 'https://discord.com/api/webhooks/…'],
      ['key' => 'username', 'label' => __('Bot name', 'flowsystems-webhook-actions'), 'type' => 'text', 'required' => false, 'placeholder' => 'Webhook Actions'],
      ['key' => 'mention', 'label' => __('Mention', 'flowsystems-webhook-actions'), 'type' => 'text', 'required' => false, 'help' => __('Optional, e.g. @here or <@&roleId>, prepended to the message.', 'flowsystems-webhook-actions')],
    ];
  }

  public function send(array $message, array $config, array $secrets): true|WP_Error {
    $url = $this->requireSecret($secrets, 'webhook_url', __('Webhook URL', 'flowsystems-webhook-actions'));
    if (is_wp_error($url)) {
      return $url;
    }

    $fields = [];
    foreach (array_slice($message['fields'] ?? [], 0, 25) as $field) {
      $fields[] = [
        'name'   => $this->truncate((string) $field['label'], 256),
        'value'  => $this->truncate((string) $field['value'], 1024) ?: '—',
        'inline' => mb_strlen((string) $field['value']) < 40,
      ];
    }

    $embed = [
      'title'       => $this->truncate((string) $message['title'], 256),
      'description' => $this->truncate((string) $message['body'], 4096),
      'color'       => hexdec($this->colour($message)),
      'fields'      => $fields,
      'footer'      => ['text' => $this->truncate((string) $message['site_name'] . ' · ' . $message['event_label'], 2048)],
      'timestamp'   => (string) $message['fired_at'],
    ];
    if (!empty($message['link']['url'])) {
      $embed['url'] = (string) $message['link']['url'];
    }

    $body = ['embeds' => [$embed]];
    $mention = trim((string) $this->cfg($config, 'mention'));
    if ($mention !== '') {
      $body['content'] = $this->truncate($mention . ' ' . $message['short'], 2000);
    }
    $username = trim((string) $this->cfg($config, 'username'));
    if ($username !== '') {
      $body['username'] = $this->truncate($username, 80);
    }

    return $this->outcome($this->post($url, $body));
  }
}
