<?php

namespace FlowSystems\WebhookActions\Services\Notifications\Channels;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * WhatsApp through Twilio. Alerts are business-initiated and land outside the
 * 24-hour customer-service window, so they must go out as an approved Content
 * Template: the channel holds the Content SID and maps the template's
 * numbered variables to parts of the message. Free-form text is sent only
 * when no Content SID is configured (works inside an open window, e.g. the
 * Twilio sandbox).
 */
class TwilioWhatsappDriver extends TwilioSmsDriver {
  protected const LIMIT = 1024;

  public function type(): string {
    return 'twilio_whatsapp';
  }

  public function label(): string {
    return __('WhatsApp (Twilio)', 'flowsystems-webhook-actions');
  }

  public function description(): string {
    return __('WhatsApp messages through Twilio. Business-initiated alerts need an approved Content Template.', 'flowsystems-webhook-actions');
  }

  public function fields(): array {
    $fields   = parent::fields();
    $fields[] = ['key' => 'content_sid', 'label' => __('Content Template SID', 'flowsystems-webhook-actions'), 'type' => 'text', 'required' => false, 'placeholder' => 'HX…', 'help' => __('An approved template from Twilio → Content Template Builder. Required outside the 24-hour window.', 'flowsystems-webhook-actions')];
    $fields[] = ['key' => 'content_variables', 'label' => __('Template variables', 'flowsystems-webhook-actions'), 'type' => 'textarea', 'required' => false, 'default' => "1=title\n2=short", 'help' => __('One per line: number=source. Sources: title, short, body, webhook_name, trigger, event_label, error, site_name, link — or literal text in quotes.', 'flowsystems-webhook-actions')];

    return $fields;
  }

  public function send(array $message, array $config, array $secrets): true|WP_Error {
    $prepared = $this->prepare($config, $secrets);
    if (is_wp_error($prepared)) {
      return $prepared;
    }

    $contentSid = trim((string) $this->cfg($config, 'content_sid'));
    if ($contentSid !== '') {
      $params = [
        'ContentSid'       => $contentSid,
        'ContentVariables' => wp_json_encode($this->variables((string) $this->cfg($config, 'content_variables', "1=title\n2=short"), $message), JSON_UNESCAPED_UNICODE),
      ];
    } else {
      $params = ['Body' => $this->truncate($this->plainText($message), static::LIMIT)];
    }

    $errors = [];
    foreach ($prepared['to'] as $recipient) {
      $result = $this->sendOne($prepared, $this->addressed($recipient), $this->addressed($prepared['from']), $params);
      if (is_wp_error($result)) {
        $errors[] = $recipient . ': ' . $result->get_error_message();
      }
    }

    return empty($errors) ? true : new WP_Error('fswa_notification_http', implode('; ', $errors));
  }

  protected function addressed(string $number): string {
    if (strncmp($number, 'MG', 2) === 0 || strncmp($number, 'whatsapp:', 9) === 0) {
      return $number;
    }

    return 'whatsapp:' . $number;
  }

  /**
   * "1=title\n2=short" → {"1": "...", "2": "..."}
   *
   * @return array<string, string>
   */
  private function variables(string $spec, array $message): array {
    $sources = [
      'title'        => (string) $message['title'],
      'short'        => (string) $message['short'],
      'body'         => (string) $message['body'],
      'subject'      => (string) $message['subject'],
      'webhook_name' => (string) $message['webhook_name'],
      'trigger'      => (string) ($message['trigger_label'] ?: $message['trigger']),
      'event_label'  => (string) $message['event_label'],
      'site_name'    => (string) $message['site_name'],
      'link'         => (string) ($message['link']['url'] ?? ''),
      'error'        => '',
    ];
    foreach ($message['fields'] ?? [] as $field) {
      if (strcasecmp((string) $field['label'], 'Error') === 0 || $field['label'] === __('Error', 'flowsystems-webhook-actions')) {
        $sources['error'] = (string) $field['value'];
      }
    }

    $out = [];
    foreach (preg_split('/\r?\n/', $spec) ?: [] as $line) {
      if (!preg_match('/^\s*(\d+)\s*=\s*(.+?)\s*$/', $line, $m)) {
        continue;
      }
      $source = $m[2];
      if (strlen($source) >= 2 && $source[0] === '"' && substr($source, -1) === '"') {
        $out[$m[1]] = substr($source, 1, -1);
        continue;
      }
      $out[$m[1]] = $this->truncate($sources[$source] ?? $source, 1024);
    }

    return $out;
  }
}
