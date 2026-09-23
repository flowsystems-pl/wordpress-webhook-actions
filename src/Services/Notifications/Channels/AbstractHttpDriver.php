<?php

namespace FlowSystems\WebhookActions\Services\Notifications\Channels;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * Shared plumbing for the HTTP-backed drivers: one POST with a short timeout,
 * one retry on 429/5xx honouring Retry-After, and the message-to-text helpers
 * every chat card needs.
 */
abstract class AbstractHttpDriver implements ChannelDriver {
  protected const TIMEOUT = 10;

  /** Severity → hex colour for embeds and attachments. */
  protected const COLOURS = [
    'success'  => '2ecc71',
    'info'     => '3498db',
    'warning'  => 'f39c12',
    'critical' => 'e74c3c',
    'neutral'  => '95a5a6',
  ];

  public function description(): string {
    return '';
  }

  /**
   * @param array<string, mixed>|string $body Array = JSON, string = raw
   * @param array<string, string> $headers
   * @return array{code:int, body:string}|WP_Error
   */
  protected function post(string $url, array|string $body, array $headers = [], string $method = 'POST'): array|WP_Error {
    if (is_array($body)) {
      $headers['Content-Type'] = $headers['Content-Type'] ?? 'application/json';
      $body                    = wp_json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    $args = [
      'method'      => $method,
      'timeout'     => self::TIMEOUT,
      'headers'     => $headers,
      'body'        => $body,
      'redirection' => 2,
      'user-agent'  => 'WP-Webhook-Actions-Notifier/' . (defined('FSWA_VERSION') ? FSWA_VERSION : '0'),
    ];

    /**
     * Filter the HTTP arguments of an outgoing notification request.
     *
     * @param array  $args wp_remote_request() args
     * @param string $url  Destination
     * @param string $type Channel type
     */
    $args = (array) apply_filters('fswa_notification_http_args', $args, $url, $this->type());

    $attempt = 0;
    while (true) {
      $attempt++;
      $response = wp_remote_request($url, $args);

      if (is_wp_error($response)) {
        if ($attempt < 2) {
          continue;
        }
        return $response;
      }

      $code = (int) wp_remote_retrieve_response_code($response);
      $text = (string) wp_remote_retrieve_body($response);

      if (($code === 429 || $code >= 500) && $attempt < 2) {
        $wait = (int) wp_remote_retrieve_header($response, 'retry-after');
        if ($wait > 0 && $wait <= 5) {
          sleep($wait);
        }
        continue;
      }

      return ['code' => $code, 'body' => $text];
    }
  }

  /**
   * Turn a post() result into true or a WP_Error for the sender.
   *
   * @param array{code:int, body:string}|WP_Error $result
   */
  protected function outcome(array|WP_Error $result, int $okMin = 200, int $okMax = 299): true|WP_Error {
    if (is_wp_error($result)) {
      return $result;
    }
    if ($result['code'] >= $okMin && $result['code'] <= $okMax) {
      return true;
    }

    return new WP_Error(
      'fswa_notification_http',
      sprintf('HTTP %d: %s', $result['code'], mb_substr(trim($result['body']), 0, 300))
    );
  }

  /**
   * Plain-text rendering: title, body, then facts one per line.
   */
  protected function plainText(array $message, bool $withTitle = true): string {
    $lines = [];
    if ($withTitle && !empty($message['title'])) {
      $lines[] = $message['title'];
    }
    if (!empty($message['body'])) {
      $lines[] = $message['body'];
    }
    foreach ($message['fields'] ?? [] as $field) {
      $lines[] = $field['label'] . ': ' . $field['value'];
    }

    return implode("\n", $lines);
  }

  protected function colour(array $message): string {
    return self::COLOURS[$message['severity'] ?? 'neutral'] ?? self::COLOURS['neutral'];
  }

  protected function truncate(string $text, int $limit): string {
    return mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, $limit - 1)) . '…' : $text;
  }

  /**
   * Config value with a default.
   */
  protected function cfg(array $config, string $key, mixed $default = ''): mixed {
    $value = $config[$key] ?? null;
    if ($value === null || $value === '') {
      return $default;
    }

    return $value;
  }

  protected function requireSecret(array $secrets, string $key, string $label): string|WP_Error {
    $value = trim((string) ($secrets[$key] ?? ''));
    if ($value === '') {
      return new WP_Error('fswa_notification_config', sprintf(
        /* translators: %s: field label */
        __('%s is not set on this channel.', 'flowsystems-webhook-actions'),
        $label
      ));
    }

    return $value;
  }

  protected function requireConfig(array $config, string $key, string $label): string|WP_Error {
    $value = trim((string) ($config[$key] ?? ''));
    if ($value === '') {
      return new WP_Error('fswa_notification_config', sprintf(
        /* translators: %s: field label */
        __('%s is not set on this channel.', 'flowsystems-webhook-actions'),
        $label
      ));
    }

    return $value;
  }
}
