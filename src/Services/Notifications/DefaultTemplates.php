<?php

namespace FlowSystems\WebhookActions\Services\Notifications;

defined('ABSPATH') || exit;

/**
 * The message a rule sends when it has no template of its own. One per event,
 * plus a short single-line body for SMS-class channels.
 */
class DefaultTemplates {
  /**
   * @return array{subject:string, title:string, body:string, short:string, include_fields:bool}
   */
  public static function for(string $event): array {
    $templates = self::all();

    return $templates[$event] ?? $templates[DeliveryEvents::FAILED_ATTEMPT];
  }

  /**
   * @return array<string, array{subject:string, title:string, body:string, short:string, include_fields:bool}>
   */
  public static function all(): array {
    return [
      DeliveryEvents::SUCCESS => [
        'subject'        => '[{{ site.name }}] {{ webhook.name }} delivered',
        'title'          => '{{ webhook.name }} delivered',
        'body'           => "Trigger: {{ event.trigger_label }}\nHTTP {{ delivery.http_code }} in {{ delivery.duration_ms }} ms (attempt {{ delivery.attempt }}).\n{{ delivery.log_url }}",
        'short'          => '{{ webhook.name }} delivered ({{ event.trigger_label }}, HTTP {{ delivery.http_code }})',
        'include_fields' => true,
      ],
      DeliveryEvents::RECOVERED => [
        'subject'        => '[{{ site.name }}] {{ webhook.name }} recovered',
        'title'          => '{{ webhook.name }} recovered',
        'body'           => "Delivered on attempt {{ delivery.attempt }} after earlier failures.\nTrigger: {{ event.trigger_label }}\n{{ delivery.log_url }}",
        'short'          => '{{ webhook.name }} recovered on attempt {{ delivery.attempt }}',
        'include_fields' => true,
      ],
      DeliveryEvents::FAILED_ATTEMPT => [
        'subject'        => '[{{ site.name }}] {{ webhook.name }} failed (attempt {{ delivery.attempt }}/{{ delivery.max_attempts | default:"?" }})',
        'title'          => '{{ webhook.name }} failed — attempt {{ delivery.attempt }}',
        'body'           => "Trigger: {{ event.trigger_label }}\nError: {{ delivery.error_message | truncate:200 }}\n{{ delivery.log_url }}",
        'short'          => '{{ webhook.name }} failed (attempt {{ delivery.attempt }}): {{ delivery.error_message | truncate:80 }}',
        'include_fields' => true,
      ],
      DeliveryEvents::RETRY_SCHEDULED => [
        'subject'        => '[{{ site.name }}] {{ webhook.name }} will retry (attempt {{ delivery.attempt }} failed)',
        'title'          => '{{ webhook.name }} retry scheduled',
        'body'           => "Attempt {{ delivery.attempt }} of {{ delivery.max_attempts | default:\"?\" }} failed: {{ delivery.error_message | truncate:200 }}\nNext try: {{ delivery.next_attempt_at | date:\"Y-m-d H:i\" }} UTC\n{{ delivery.log_url }}",
        'short'          => '{{ webhook.name }} attempt {{ delivery.attempt }} failed, retrying at {{ delivery.next_attempt_at | date:"H:i" }}',
        'include_fields' => true,
      ],
      DeliveryEvents::PERMANENTLY_FAILED => [
        'subject'        => '[{{ site.name }}] {{ webhook.name }} permanently failed',
        'title'          => '{{ webhook.name }} permanently failed',
        'body'           => "{{ delivery.status }} after {{ delivery.attempt }} attempt(s).\nTrigger: {{ event.trigger_label }}\nError: {{ delivery.error_message | truncate:300 }}\n{{ delivery.log_url }}",
        'short'          => '{{ webhook.name }} permanently failed: {{ delivery.error_message | truncate:80 }}',
        'include_fields' => true,
      ],
      DeliveryEvents::SKIPPED => [
        'subject'        => '[{{ site.name }}] {{ webhook.name }} skipped',
        'title'          => '{{ webhook.name }} skipped',
        'body'           => "Conditions did not pass for {{ event.trigger_label }}.\n{{ delivery.error_message }}\n{{ delivery.log_url }}",
        'short'          => '{{ webhook.name }} skipped: {{ delivery.error_message | truncate:80 }}',
        'include_fields' => false,
      ],
    ];
  }

  /**
   * Merge a rule's partial template over the default for its event, so a
   * rule can override only the body and keep the rest.
   *
   * @param array<string, mixed>|null $template
   * @return array{subject:string, title:string, body:string, short:string, include_fields:bool}
   */
  public static function resolve(string $event, ?array $template): array {
    $base = self::for($event);
    if (!is_array($template)) {
      return $base;
    }
    foreach (['subject', 'title', 'body', 'short'] as $key) {
      if (isset($template[$key]) && is_string($template[$key]) && trim($template[$key]) !== '') {
        $base[$key] = $template[$key];
      }
    }
    if (array_key_exists('include_fields', $template)) {
      $base['include_fields'] = (bool) $template['include_fields'];
    }

    return $base;
  }
}
