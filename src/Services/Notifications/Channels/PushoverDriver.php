<?php

namespace FlowSystems\WebhookActions\Services\Notifications\Channels;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * Pushover mobile push. Priority follows severity unless the channel pins one.
 */
class PushoverDriver extends AbstractHttpDriver {
  public function type(): string {
    return 'pushover';
  }

  public function label(): string {
    return __('Pushover', 'flowsystems-webhook-actions');
  }

  public function description(): string {
    return __('Push notifications to your phone. Needs an application token and your user key.', 'flowsystems-webhook-actions');
  }

  public function fields(): array {
    return [
      ['key' => 'app_token', 'label' => __('Application token', 'flowsystems-webhook-actions'), 'type' => 'secret', 'required' => true],
      ['key' => 'user_key', 'label' => __('User or group key', 'flowsystems-webhook-actions'), 'type' => 'secret', 'required' => true],
      ['key' => 'device', 'label' => __('Device', 'flowsystems-webhook-actions'), 'type' => 'text', 'required' => false],
      ['key' => 'priority', 'label' => __('Priority', 'flowsystems-webhook-actions'), 'type' => 'select', 'required' => false, 'default' => 'auto', 'options' => [
        ['value' => 'auto', 'label' => __('By severity (failures high, success low)', 'flowsystems-webhook-actions')],
        ['value' => '-2', 'label' => __('Lowest', 'flowsystems-webhook-actions')],
        ['value' => '-1', 'label' => __('Low', 'flowsystems-webhook-actions')],
        ['value' => '0', 'label' => __('Normal', 'flowsystems-webhook-actions')],
        ['value' => '1', 'label' => __('High', 'flowsystems-webhook-actions')],
      ]],
    ];
  }

  public function send(array $message, array $config, array $secrets): true|WP_Error {
    $token = $this->requireSecret($secrets, 'app_token', __('Application token', 'flowsystems-webhook-actions'));
    if (is_wp_error($token)) {
      return $token;
    }
    $user = $this->requireSecret($secrets, 'user_key', __('User or group key', 'flowsystems-webhook-actions'));
    if (is_wp_error($user)) {
      return $user;
    }

    $priority = (string) $this->cfg($config, 'priority', 'auto');
    if ($priority === 'auto') {
      $priority = match ($message['severity'] ?? 'neutral') {
        'critical' => '1',
        'warning'  => '0',
        'success'  => '-1',
        default    => '-1',
      };
    }

    $form = [
      'token'     => $token,
      'user'      => $user,
      'title'     => $this->truncate((string) $message['title'], 250),
      'message'   => $this->truncate($this->plainText($message, false), 1024),
      'priority'  => (int) $priority,
      'timestamp' => strtotime((string) $message['fired_at']) ?: time(),
    ];
    if (!empty($message['link']['url'])) {
      $form['url']       = (string) $message['link']['url'];
      $form['url_title'] = (string) $message['link']['label'];
    }
    $device = trim((string) $this->cfg($config, 'device'));
    if ($device !== '') {
      $form['device'] = $device;
    }

    $result = $this->post('https://api.pushover.net/1/messages.json', http_build_query($form), ['Content-Type' => 'application/x-www-form-urlencoded']);

    return $this->outcome($result);
  }
}
