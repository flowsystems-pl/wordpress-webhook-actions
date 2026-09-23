<?php

namespace FlowSystems\WebhookActions\Services\Notifications\Channels;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * Telegram Bot API sendMessage in HTML parse mode (the one mode where escaping
 * is just htmlspecialchars). Supports forum topics via message_thread_id.
 */
class TelegramDriver extends AbstractHttpDriver {
  private const LIMIT = 4096;

  public function type(): string {
    return 'telegram';
  }

  public function label(): string {
    return __('Telegram', 'flowsystems-webhook-actions');
  }

  public function description(): string {
    return __('A bot token from @BotFather and the chat or group ID to post to.', 'flowsystems-webhook-actions');
  }

  public function fields(): array {
    return [
      ['key' => 'bot_token', 'label' => __('Bot token', 'flowsystems-webhook-actions'), 'type' => 'secret', 'required' => true, 'placeholder' => '123456789:AA…'],
      ['key' => 'chat_id', 'label' => __('Chat ID', 'flowsystems-webhook-actions'), 'type' => 'text', 'required' => true, 'help' => __('A user, group (-100…) or channel (@name) ID. Send the bot a message first so it can reach you.', 'flowsystems-webhook-actions')],
      ['key' => 'thread_id', 'label' => __('Topic ID', 'flowsystems-webhook-actions'), 'type' => 'text', 'required' => false, 'help' => __('Forum topic (message_thread_id), optional.', 'flowsystems-webhook-actions')],
      ['key' => 'silent', 'label' => __('Send silently', 'flowsystems-webhook-actions'), 'type' => 'toggle', 'required' => false, 'default' => false],
    ];
  }

  public function send(array $message, array $config, array $secrets): true|WP_Error {
    $token = $this->requireSecret($secrets, 'bot_token', __('Bot token', 'flowsystems-webhook-actions'));
    if (is_wp_error($token)) {
      return $token;
    }
    $chatId = $this->requireConfig($config, 'chat_id', __('Chat ID', 'flowsystems-webhook-actions'));
    if (is_wp_error($chatId)) {
      return $chatId;
    }

    $esc  = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $text = '<b>' . $esc((string) $message['title']) . '</b>';
    if (!empty($message['body'])) {
      $text .= "\n" . $esc((string) $message['body']);
    }
    if (!empty($message['fields'])) {
      $text .= "\n";
      foreach ($message['fields'] as $field) {
        $text .= "\n<b>" . $esc((string) $field['label']) . ':</b> ' . $esc((string) $field['value']);
      }
    }
    if (!empty($message['link']['url'])) {
      $text .= "\n\n" . '<a href="' . $esc((string) $message['link']['url']) . '">' . $esc((string) $message['link']['label']) . '</a>';
    }
    if (mb_strlen($text) > self::LIMIT) {
      $text = $this->truncate($text, self::LIMIT - 20);
      // A cut may have opened a tag; drop everything after the last '<'.
      $lastOpen = mb_strrpos($text, '<');
      $lastClose = mb_strrpos($text, '>');
      if ($lastOpen !== false && ($lastClose === false || $lastOpen > $lastClose)) {
        $text = mb_substr($text, 0, $lastOpen);
      }
    }

    $body = [
      'chat_id'                  => $chatId,
      'text'                     => $text,
      'parse_mode'               => 'HTML',
      'disable_web_page_preview' => true,
      'disable_notification'     => (bool) $this->cfg($config, 'silent', false),
    ];
    $thread = trim((string) $this->cfg($config, 'thread_id'));
    if ($thread !== '') {
      $body['message_thread_id'] = (int) $thread;
    }

    // A token is digits:base64url — every character is path-safe, and a
    // percent-encoded colon is not something the Bot API promises to accept.
    $token  = (string) preg_replace('/[^A-Za-z0-9:_\-]/', '', $token);
    $result = $this->post('https://api.telegram.org/bot' . $token . '/sendMessage', $body);
    if (is_wp_error($result)) {
      return $result;
    }
    $decoded = json_decode($result['body'], true);
    if ($result['code'] === 200 && !empty($decoded['ok'])) {
      return true;
    }

    return new WP_Error('fswa_notification_http', sprintf('Telegram: %s', $decoded['description'] ?? ('HTTP ' . $result['code'])));
  }
}
