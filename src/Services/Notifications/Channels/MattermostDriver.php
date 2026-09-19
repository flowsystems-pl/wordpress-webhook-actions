<?php

namespace FlowSystems\WebhookActions\Services\Notifications\Channels;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * Mattermost / Rocket.Chat and anything else that speaks the Slack-compatible
 * incoming-webhook payload (text + attachments).
 */
class MattermostDriver extends AbstractHttpDriver {
  public function type(): string {
    return 'mattermost';
  }

  public function label(): string {
    return __('Mattermost / Rocket.Chat', 'flowsystems-webhook-actions');
  }

  public function description(): string {
    return __('Incoming webhook in the Slack-compatible format. Works with Mattermost, Rocket.Chat and similar.', 'flowsystems-webhook-actions');
  }

  public function fields(): array {
    return [
      ['key' => 'webhook_url', 'label' => __('Incoming webhook URL', 'flowsystems-webhook-actions'), 'type' => 'secret', 'required' => true],
      ['key' => 'channel', 'label' => __('Channel', 'flowsystems-webhook-actions'), 'type' => 'text', 'required' => false, 'help' => __('Optional override, e.g. town-square.', 'flowsystems-webhook-actions')],
      ['key' => 'username', 'label' => __('Post as', 'flowsystems-webhook-actions'), 'type' => 'text', 'required' => false],
    ];
  }

  public function send(array $message, array $config, array $secrets): true|WP_Error {
    $url = $this->requireSecret($secrets, 'webhook_url', __('Incoming webhook URL', 'flowsystems-webhook-actions'));
    if (is_wp_error($url)) {
      return $url;
    }

    $fields = [];
    foreach ($message['fields'] ?? [] as $field) {
      $fields[] = ['short' => mb_strlen((string) $field['value']) < 40, 'title' => (string) $field['label'], 'value' => (string) $field['value']];
    }

    $body = [
      'text'        => '### ' . $message['title'],
      'attachments' => [[
        'color'      => '#' . $this->colour($message),
        'text'       => (string) $message['body'],
        'fields'     => $fields,
        'title'      => (string) $message['event_label'],
        'title_link' => (string) ($message['link']['url'] ?? ''),
        'footer'     => (string) $message['site_name'],
      ]],
    ];
    $channel = trim((string) $this->cfg($config, 'channel'));
    if ($channel !== '') {
      $body['channel'] = $channel;
    }
    $username = trim((string) $this->cfg($config, 'username'));
    if ($username !== '') {
      $body['username'] = $username;
    }

    return $this->outcome($this->post($url, $body));
  }
}
