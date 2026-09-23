<?php

namespace FlowSystems\WebhookActions\Services\Notifications;

defined('ABSPATH') || exit;

/**
 * Renders a rule's template against a delivery context into the neutral
 * message every channel driver formats:
 *
 *   subject, title, body, short   rendered text
 *   severity                      success | info | warning | critical | neutral
 *   fields[]                      label/value pairs (attempt, HTTP, error, …)
 *   link                          label/url to the delivery log
 */
class MessageBuilder {
  private TemplateRenderer $renderer;

  public function __construct(?TemplateRenderer $renderer = null) {
    $this->renderer = $renderer ?? new TemplateRenderer();
  }

  /**
   * @param array<string, mixed> $rule Cast rule row
   * @param array<string, mixed> $ctx  Normalized DeliveryEvents context
   * @return array<string, mixed>
   */
  public function build(array $rule, array $ctx): array {
    $roots = TemplateContext::fromDeliveryContext($ctx);

    return $this->buildFromRoots((string) ($ctx['event'] ?? ''), $rule['template'] ?? null, $roots, $ctx, $rule);
  }

  /**
   * Same, from pre-built roots (previews and AI drafts).
   *
   * @param array<string, mixed>|null $template
   * @param array<string, mixed>      $roots
   * @param array<string, mixed>      $ctx
   * @param array<string, mixed>|null $rule  The matching rule row, when there is one
   * @return array<string, mixed>
   */
  public function buildFromRoots(string $event, ?array $template, array $roots, array $ctx = [], ?array $rule = null): array {
    $tpl = DefaultTemplates::resolve($event, $template);

    $message = [
      'event'         => $event,
      'event_label'   => TemplateContext::eventLabel($event),
      'severity'      => self::severity($event),
      'subject'       => $this->line($this->renderer->render($tpl['subject'], $roots)),
      'title'         => $this->line($this->renderer->render($tpl['title'], $roots)),
      'body'          => trim($this->renderer->render($tpl['body'], $roots)),
      'short'         => $this->line($this->renderer->render($tpl['short'], $roots)),
      'fields'        => $tpl['include_fields'] ? $this->fields($roots) : [],
      'link'          => [
        'label' => __('Open delivery log', 'flowsystems-webhook-actions'),
        'url'   => (string) ($roots['delivery']['log_url'] ?? ''),
      ],
      'webhook_id'    => (int) ($roots['webhook']['id'] ?? 0),
      'webhook_name'  => (string) ($roots['webhook']['name'] ?? ''),
      'trigger'       => (string) ($roots['event']['trigger'] ?? ''),
      'trigger_label' => (string) ($roots['event']['trigger_label'] ?? ''),
      'log_id'        => $roots['delivery']['log_id'] ?? null,
      'event_uuid'    => (string) ($roots['event']['uuid'] ?? ''),
      'site_name'     => (string) ($roots['site']['name'] ?? ''),
      'site_url'      => (string) ($roots['site']['url'] ?? ''),
      'fired_at'      => (string) ($ctx['fired_at'] ?? gmdate('Y-m-d\TH:i:s\Z')),
    ];

    /**
     * Filter the rendered notification before any channel formats it.
     *
     * @param array $message  subject, title, body, short, severity, fields, link, …
     * @param array $rule     The matching rule row; for previews and tests
     *                        without a saved rule, just ['template' => …] or []
     * @param array $ctx      The delivery context
     */
    return (array) apply_filters('fswa_notification_message', $message, $rule ?? ($template !== null ? ['template' => $template] : []), $ctx);
  }

  /**
   * Mark a message as a test on every line a channel may show as the headline
   * — email uses the subject, chat cards the title, SMS the one-liner — so a
   * test can never be mistaken for a real alert in any inbox.
   *
   * @param array<string, mixed> $message
   * @return array<string, mixed>
   */
  public static function asTest(array $message): array {
    $prefix = '[' . __('Test', 'flowsystems-webhook-actions') . '] ';
    foreach (['subject', 'title', 'short'] as $key) {
      if (isset($message[$key]) && is_string($message[$key]) && $message[$key] !== '') {
        $message[$key] = $prefix . $message[$key];
      }
    }

    return $message;
  }

  public static function severity(string $event): string {
    return match ($event) {
      DeliveryEvents::SUCCESS, DeliveryEvents::RECOVERED => 'success',
      DeliveryEvents::FAILED_ATTEMPT, DeliveryEvents::RETRY_SCHEDULED => 'warning',
      DeliveryEvents::PERMANENTLY_FAILED => 'critical',
      default => 'neutral',
    };
  }

  /**
   * Standard key/value facts for the card-style channels.
   *
   * @param array<string, mixed> $roots
   * @return array<int, array{label:string, value:string}>
   */
  private function fields(array $roots): array {
    $d      = $roots['delivery'];
    $fields = [
      ['label' => __('Webhook', 'flowsystems-webhook-actions'), 'value' => (string) $roots['webhook']['name']],
      ['label' => __('Trigger', 'flowsystems-webhook-actions'), 'value' => (string) $roots['event']['trigger_label']],
    ];

    $attempt = (string) $d['attempt'];
    if (!empty($d['max_attempts'])) {
      $attempt .= ' / ' . $d['max_attempts'];
    }
    $fields[] = ['label' => __('Attempt', 'flowsystems-webhook-actions'), 'value' => $attempt];

    if ($d['http_code'] !== null) {
      $fields[] = ['label' => __('HTTP', 'flowsystems-webhook-actions'), 'value' => (string) $d['http_code']];
    }
    if ($d['duration_ms'] !== null) {
      $fields[] = ['label' => __('Duration', 'flowsystems-webhook-actions'), 'value' => $d['duration_ms'] . ' ms'];
    }
    if (!empty($d['error_message'])) {
      $fields[] = ['label' => __('Error', 'flowsystems-webhook-actions'), 'value' => mb_substr((string) $d['error_message'], 0, 300)];
    }
    if (!empty($d['next_attempt_at'])) {
      $fields[] = ['label' => __('Next attempt', 'flowsystems-webhook-actions'), 'value' => (string) $d['next_attempt_at'] . ' UTC'];
    }
    if (!empty($roots['event']['uuid'])) {
      $fields[] = ['label' => __('Event', 'flowsystems-webhook-actions'), 'value' => (string) $roots['event']['uuid']];
    }

    return $fields;
  }

  private function line(string $text): string {
    return trim((string) preg_replace('/\s+/', ' ', $text));
  }
}
