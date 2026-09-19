<?php

namespace FlowSystems\WebhookActions\Services\Notifications\Channels;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * ntfy (ntfy.sh or self-hosted): POST the text to a topic with the title,
 * priority and click URL in headers.
 */
class NtfyDriver extends AbstractHttpDriver {
  public function type(): string {
    return 'ntfy';
  }

  public function label(): string {
    return __('ntfy', 'flowsystems-webhook-actions');
  }

  public function description(): string {
    return __('Push to a topic on ntfy.sh or your own ntfy server. No account needed for public topics.', 'flowsystems-webhook-actions');
  }

  public function fields(): array {
    return [
      ['key' => 'server', 'label' => __('Server', 'flowsystems-webhook-actions'), 'type' => 'url', 'required' => false, 'default' => 'https://ntfy.sh', 'placeholder' => 'https://ntfy.sh'],
      ['key' => 'topic', 'label' => __('Topic', 'flowsystems-webhook-actions'), 'type' => 'text', 'required' => true, 'help' => __('Pick something hard to guess; anyone who knows the topic can subscribe.', 'flowsystems-webhook-actions')],
      ['key' => 'access_token', 'label' => __('Access token', 'flowsystems-webhook-actions'), 'type' => 'secret', 'required' => false, 'help' => __('Only for protected topics.', 'flowsystems-webhook-actions')],
    ];
  }

  public function send(array $message, array $config, array $secrets): true|WP_Error {
    $topic = $this->requireConfig($config, 'topic', __('Topic', 'flowsystems-webhook-actions'));
    if (is_wp_error($topic)) {
      return $topic;
    }
    $server = rtrim((string) $this->cfg($config, 'server', 'https://ntfy.sh'), '/');

    $priority = match ($message['severity'] ?? 'neutral') {
      'critical' => '5',
      'warning'  => '4',
      'success'  => '2',
      default    => '3',
    };
    $tags = match ($message['severity'] ?? 'neutral') {
      'critical' => 'rotating_light',
      'warning'  => 'warning',
      'success'  => 'white_check_mark',
      default    => 'information_source',
    };

    // Header values must be Latin-1 safe; ntfy accepts RFC 2047 for the title.
    $title   = (string) $message['title'];
    $headers = [
      'Content-Type' => 'text/plain; charset=utf-8',
      'Title'        => preg_match('/[^\x20-\x7E]/', $title) ? '=?UTF-8?B?' . base64_encode($title) . '?=' : $title,
      'Priority'     => $priority,
      'Tags'         => $tags,
    ];
    if (!empty($message['link']['url'])) {
      $headers['Click'] = (string) $message['link']['url'];
    }
    $token = trim((string) ($secrets['access_token'] ?? ''));
    if ($token !== '') {
      $headers['Authorization'] = 'Bearer ' . $token;
    }

    $result = $this->post($server . '/' . rawurlencode($topic), $this->truncate($this->plainText($message, false), 4096), $headers);

    return $this->outcome($result);
  }
}
