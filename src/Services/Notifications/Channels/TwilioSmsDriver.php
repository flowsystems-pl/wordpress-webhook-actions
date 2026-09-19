<?php

namespace FlowSystems\WebhookActions\Services\Notifications\Channels;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * Twilio SMS. One request per recipient; the body is the rule's short line.
 */
class TwilioSmsDriver extends AbstractHttpDriver {
  protected const LIMIT = 320;

  public function type(): string {
    return 'twilio_sms';
  }

  public function label(): string {
    return __('SMS (Twilio)', 'flowsystems-webhook-actions');
  }

  public function description(): string {
    return __('Text messages through your Twilio account. Sends the one-line "short" text of the template.', 'flowsystems-webhook-actions');
  }

  public function fields(): array {
    return [
      ['key' => 'account_sid', 'label' => __('Account SID', 'flowsystems-webhook-actions'), 'type' => 'text', 'required' => true, 'placeholder' => 'AC…'],
      ['key' => 'auth_token', 'label' => __('Auth token', 'flowsystems-webhook-actions'), 'type' => 'secret', 'required' => true],
      ['key' => 'from', 'label' => __('From', 'flowsystems-webhook-actions'), 'type' => 'text', 'required' => true, 'help' => __('A Twilio number in E.164 (+15551234567) or a Messaging Service SID (MG…).', 'flowsystems-webhook-actions')],
      ['key' => 'to', 'label' => __('To', 'flowsystems-webhook-actions'), 'type' => 'text', 'required' => true, 'help' => __('One or more E.164 numbers, separated by commas.', 'flowsystems-webhook-actions')],
    ];
  }

  public function send(array $message, array $config, array $secrets): true|WP_Error {
    $prepared = $this->prepare($config, $secrets);
    if (is_wp_error($prepared)) {
      return $prepared;
    }

    $text   = $this->truncate((string) ($message['short'] ?: $message['title']), static::LIMIT);
    $errors = [];
    foreach ($prepared['to'] as $recipient) {
      $result = $this->sendOne($prepared, $this->addressed($recipient), $this->addressed($prepared['from']), ['Body' => $text]);
      if (is_wp_error($result)) {
        $errors[] = $recipient . ': ' . $result->get_error_message();
      }
    }

    return empty($errors) ? true : new WP_Error('fswa_notification_http', implode('; ', $errors));
  }

  /**
   * @return array{sid:string, token:string, from:string, to:array<int,string>}|WP_Error
   */
  protected function prepare(array $config, array $secrets): array|WP_Error {
    $sid = $this->requireConfig($config, 'account_sid', __('Account SID', 'flowsystems-webhook-actions'));
    if (is_wp_error($sid)) {
      return $sid;
    }
    $token = $this->requireSecret($secrets, 'auth_token', __('Auth token', 'flowsystems-webhook-actions'));
    if (is_wp_error($token)) {
      return $token;
    }
    $from = $this->requireConfig($config, 'from', __('From', 'flowsystems-webhook-actions'));
    if (is_wp_error($from)) {
      return $from;
    }
    $to = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) $this->cfg($config, 'to')) ?: [])));
    if (empty($to)) {
      return new WP_Error('fswa_notification_config', __('No recipient number on this channel.', 'flowsystems-webhook-actions'));
    }

    return ['sid' => $sid, 'token' => $token, 'from' => $from, 'to' => $to];
  }

  /**
   * @param array{sid:string, token:string} $prepared
   * @param array<string, string> $params
   */
  protected function sendOne(array $prepared, string $to, string $from, array $params): true|WP_Error {
    $form = ['To' => $to] + $params;
    if (strncmp($from, 'MG', 2) === 0) {
      $form['MessagingServiceSid'] = $from;
    } else {
      $form['From'] = $from;
    }

    $result = $this->post(
      'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($prepared['sid']) . '/Messages.json',
      http_build_query($form),
      [
        'Content-Type'  => 'application/x-www-form-urlencoded',
        'Authorization' => 'Basic ' . base64_encode($prepared['sid'] . ':' . $prepared['token']),
      ]
    );
    if (is_wp_error($result)) {
      return $result;
    }
    if ($result['code'] >= 200 && $result['code'] < 300) {
      return true;
    }
    $decoded = json_decode($result['body'], true);

    return new WP_Error('fswa_notification_http', sprintf('Twilio %d: %s', $result['code'], $decoded['message'] ?? mb_substr($result['body'], 0, 200)));
  }

  /** SMS numbers go as-is; the WhatsApp driver prefixes them. */
  protected function addressed(string $number): string {
    return $number;
  }
}
