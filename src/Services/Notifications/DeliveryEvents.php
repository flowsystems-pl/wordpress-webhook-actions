<?php

namespace FlowSystems\WebhookActions\Services\Notifications;

defined('ABSPATH') || exit;

/**
 * The delivery lifecycle as one action, `fswa_delivery_event`, fired from the
 * Dispatcher at every state it writes to the log — with the whole context in
 * hand so a listener never has to query anything back.
 *
 * Events:
 *   success            2xx on any attempt
 *   recovered          2xx after at least one failed attempt (fires with success)
 *   failed_attempt     transport error or non-2xx on one attempt
 *   retry_scheduled    the queue booked another attempt
 *   permanently_failed out of attempts (reason=exhausted) or not retryable (reason=non_retryable)
 *   skipped            conditions did not pass
 */
class DeliveryEvents {
  public const SUCCESS            = 'success';
  public const RECOVERED          = 'recovered';
  public const FAILED_ATTEMPT     = 'failed_attempt';
  public const RETRY_SCHEDULED    = 'retry_scheduled';
  public const PERMANENTLY_FAILED = 'permanently_failed';
  public const SKIPPED            = 'skipped';

  public const ALL = [
    self::SUCCESS,
    self::RECOVERED,
    self::FAILED_ATTEMPT,
    self::RETRY_SCHEDULED,
    self::PERMANENTLY_FAILED,
    self::SKIPPED,
  ];

  public const REASON_EXHAUSTED     = 'exhausted';
  public const REASON_NON_RETRYABLE = 'non_retryable';

  /** Longest response body carried in the context (bytes). */
  private const BODY_CAP = 4096;

  /**
   * Fire one lifecycle event. `success` with had_failures also fires
   * `recovered`, so a rule can subscribe to either.
   *
   * @param array<string, mixed> $ctx Partial context; see normalize() for keys.
   */
  public static function fire(string $event, array $ctx): void {
    $ctx = self::normalize($event, $ctx);

    /**
     * Fires at every delivery state change with the full context.
     *
     * @param string $event One of DeliveryEvents::ALL.
     * @param array  $ctx   webhook, trigger, log_id, event_uuid, event_timestamp,
     *                      attempt (1-based), max_attempts, next_attempt_at,
     *                      http_code, error_message, response_body, duration_ms,
     *                      request_url, is_test, payload, original_payload,
     *                      reason, had_failures.
     */
    do_action('fswa_delivery_event', $event, $ctx);

    if ($event === self::SUCCESS && !empty($ctx['had_failures'])) {
      $ctx['event'] = self::RECOVERED;
      do_action('fswa_delivery_event', self::RECOVERED, $ctx);
    }
  }

  /**
   * Fill every key a listener may read, derive the event identity from the
   * envelope when the caller did not pass it, and cap the response body.
   *
   * @param array<string, mixed> $ctx
   * @return array<string, mixed>
   */
  public static function normalize(string $event, array $ctx): array {
    $webhook  = is_array($ctx['webhook'] ?? null) ? $ctx['webhook'] : [];
    $payload  = is_array($ctx['payload'] ?? null) ? $ctx['payload'] : [];
    $original = is_array($ctx['original_payload'] ?? null) ? $ctx['original_payload'] : null;
    $envelope = $original ?? $payload;

    $body = $ctx['response_body'] ?? null;
    if (is_string($body) && strlen($body) > self::BODY_CAP) {
      $body = substr($body, 0, self::BODY_CAP) . '…';
    }

    $attempt = (int) ($ctx['attempt'] ?? 1);

    return [
      'event'            => $event,
      'webhook'          => [
        'id'           => (int) ($webhook['id'] ?? 0),
        'uuid'         => (string) ($webhook['webhook_uuid'] ?? ''),
        'name'         => (string) ($webhook['name'] ?? ''),
        'endpoint_url' => (string) ($webhook['endpoint_url'] ?? ''),
        'is_enabled'   => (bool) ($webhook['is_enabled'] ?? true),
        'is_synchronous' => (bool) ($webhook['is_synchronous'] ?? false),
      ],
      'trigger'          => (string) ($ctx['trigger'] ?? ''),
      'log_id'           => isset($ctx['log_id']) ? (int) $ctx['log_id'] : null,
      'event_uuid'       => (string) ($ctx['event_uuid'] ?? ($envelope['event']['id'] ?? '')),
      'event_timestamp'  => (string) ($ctx['event_timestamp'] ?? ($envelope['event']['timestamp'] ?? gmdate('Y-m-d\TH:i:s\Z'))),
      'attempt'          => max(1, $attempt),
      'max_attempts'     => isset($ctx['max_attempts']) ? (int) $ctx['max_attempts'] : null,
      'next_attempt_at'  => $ctx['next_attempt_at'] ?? null,
      'http_code'        => isset($ctx['http_code']) ? (int) $ctx['http_code'] : null,
      'error_message'    => isset($ctx['error_message']) ? (string) $ctx['error_message'] : null,
      'response_body'    => is_string($body) ? $body : null,
      'duration_ms'      => isset($ctx['duration_ms']) ? (int) $ctx['duration_ms'] : null,
      'request_url'      => isset($ctx['request_url']) ? (string) $ctx['request_url'] : (string) ($webhook['endpoint_url'] ?? ''),
      'is_test'          => (bool) ($ctx['is_test'] ?? false),
      'payload'          => $payload,
      'original_payload' => $original,
      'reason'           => $ctx['reason'] ?? null,
      'had_failures'     => (bool) ($ctx['had_failures'] ?? ($attempt > 1)),
      'fired_at'         => gmdate('Y-m-d\TH:i:s\Z'),
    ];
  }
}
