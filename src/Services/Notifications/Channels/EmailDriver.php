<?php

namespace FlowSystems\WebhookActions\Services\Notifications\Channels;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * Email through wp_mail(), so whatever SMTP plugin the site runs handles the
 * transport. HTML by default with a small inlined layout; plain text on request.
 */
class EmailDriver extends AbstractHttpDriver {
  public function type(): string {
    return 'email';
  }

  public function label(): string {
    return __('Email', 'flowsystems-webhook-actions');
  }

  public function description(): string {
    return __('Sends through wp_mail(), so your SMTP plugin applies.', 'flowsystems-webhook-actions');
  }

  public function fields(): array {
    return [
      ['key' => 'to', 'label' => __('Recipients', 'flowsystems-webhook-actions'), 'type' => 'emails', 'required' => true, 'help' => __('One or more addresses, separated by commas.', 'flowsystems-webhook-actions'), 'default' => get_option('admin_email')],
      ['key' => 'cc', 'label' => __('CC', 'flowsystems-webhook-actions'), 'type' => 'emails', 'required' => false],
      ['key' => 'from_name', 'label' => __('From name', 'flowsystems-webhook-actions'), 'type' => 'text', 'required' => false, 'placeholder' => get_bloginfo('name')],
      ['key' => 'html', 'label' => __('Send as HTML', 'flowsystems-webhook-actions'), 'type' => 'toggle', 'required' => false, 'default' => true],
    ];
  }

  public function send(array $message, array $config, array $secrets): true|WP_Error {
    $to = $this->addresses((string) $this->cfg($config, 'to'));
    if (empty($to)) {
      return new WP_Error('fswa_notification_config', __('No valid recipient address on this channel.', 'flowsystems-webhook-actions'));
    }

    $headers = [];
    $cc      = $this->addresses((string) $this->cfg($config, 'cc'));
    foreach ($cc as $address) {
      $headers[] = 'Cc: ' . $address;
    }
    $fromName = trim((string) $this->cfg($config, 'from_name'));
    if ($fromName !== '') {
      $headers[] = 'From: ' . sanitize_text_field($fromName) . ' <' . $this->fromAddress() . '>';
    }

    $html = (bool) $this->cfg($config, 'html', true);
    if ($html) {
      $headers[] = 'Content-Type: text/html; charset=UTF-8';
      $body      = $this->html($message);
    } else {
      $body = $this->plainText($message) . "\n\n" . ($message['link']['url'] ?? '');
    }

    $subject = $message['subject'] !== '' ? $message['subject'] : $message['title'];

    $sent = wp_mail($to, wp_specialchars_decode($subject, ENT_QUOTES), $body, $headers);

    return $sent ? true : new WP_Error('fswa_notification_mail', __('wp_mail() returned false. Check the site\'s mail configuration.', 'flowsystems-webhook-actions'));
  }

  /**
   * @return array<int, string>
   */
  private function addresses(string $raw): array {
    $out = [];
    foreach (preg_split('/[,;\s]+/', $raw) ?: [] as $piece) {
      $piece = trim($piece);
      if ($piece !== '' && is_email($piece)) {
        $out[] = $piece;
      }
    }

    return array_values(array_unique($out));
  }

  private function fromAddress(): string {
    $host = wp_parse_url(home_url(), PHP_URL_HOST) ?: 'localhost';
    $host = preg_replace('/^www\./', '', (string) $host);

    return 'wordpress@' . $host;
  }

  private function html(array $message): string {
    $colour = '#' . $this->colour($message);
    $esc    = static fn(string $s): string => esc_html($s);

    $rows = '';
    foreach ($message['fields'] ?? [] as $field) {
      $rows .= '<tr><td style="padding:6px 12px 6px 0;color:#6b7280;font-size:13px;white-space:nowrap;vertical-align:top">' . $esc((string) $field['label']) . '</td>'
        . '<td style="padding:6px 0;color:#111827;font-size:13px;word-break:break-word">' . $esc((string) $field['value']) . '</td></tr>';
    }

    $body = nl2br($esc((string) $message['body']));
    $link = $message['link']['url'] ?? '';

    return '<!doctype html><html><body style="margin:0;padding:24px;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif">'
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">'
      . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden">'
      . '<tr><td style="height:6px;background:' . $colour . '"></td></tr>'
      . '<tr><td style="padding:24px 28px 8px"><div style="font-size:12px;letter-spacing:.04em;text-transform:uppercase;color:' . $colour . ';font-weight:600">' . $esc((string) $message['event_label']) . '</div>'
      . '<h1 style="margin:6px 0 0;font-size:20px;line-height:1.3;color:#111827">' . $esc((string) $message['title']) . '</h1></td></tr>'
      . '<tr><td style="padding:8px 28px 16px;font-size:14px;line-height:1.55;color:#374151">' . $body . '</td></tr>'
      . ($rows !== '' ? '<tr><td style="padding:0 28px 16px"><table role="presentation" cellpadding="0" cellspacing="0" style="border-top:1px solid #e5e7eb;padding-top:8px">' . $rows . '</table></td></tr>' : '')
      . ($link !== '' ? '<tr><td style="padding:0 28px 28px"><a href="' . esc_url($link) . '" style="display:inline-block;padding:10px 16px;background:#111827;color:#ffffff;text-decoration:none;border-radius:6px;font-size:13px;font-weight:600">' . $esc((string) $message['link']['label']) . '</a></td></tr>' : '')
      . '<tr><td style="padding:12px 28px;background:#f9fafb;color:#9ca3af;font-size:11px">' . $esc((string) $message['site_name']) . ' · ' . $esc((string) $message['fired_at']) . '</td></tr>'
      . '</table></td></tr></table></body></html>';
  }
}
