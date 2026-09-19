<?php

namespace FlowSystems\WebhookActions\Services\Notifications;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Repositories\LogRepository;
use FlowSystems\WebhookActions\Repositories\SchemaRepository;
use FlowSystems\WebhookActions\Repositories\WebhookRepository;
use FlowSystems\WebhookActions\Services\PayloadTransformer;

/**
 * Template roots for previews, tests and AI drafts, from the best data at
 * hand: a real delivery log when one is named, else the webhook's captured
 * example (mapped through its field mapping), else a bare sample.
 */
class PreviewContext {
  private WebhookRepository  $webhooks;
  private SchemaRepository   $schemas;
  private LogRepository      $logs;
  private PayloadTransformer $transformer;

  public function __construct() {
    $this->webhooks    = new WebhookRepository();
    $this->schemas     = new SchemaRepository();
    $this->logs        = new LogRepository();
    $this->transformer = new PayloadTransformer();
  }

  /**
   * @return array{roots: array<string, mixed>, ctx: array<string, mixed>, source: string, example: ?array, mapped: ?array, webhook: ?array}
   */
  public function build(string $event, ?int $webhookId, ?int $logId = null): array {
    if ($logId) {
      $fromLog = $this->fromLog($event, $logId);
      if ($fromLog !== null) {
        return $fromLog;
      }
    }

    $webhook = $webhookId ? $this->webhooks->find($webhookId) : null;
    $example = null;
    $mapped  = null;
    $source  = 'sample';

    if ($webhook) {
      $trigger = (string) ($webhook['triggers'][0] ?? '');
      if ($trigger !== '') {
        $resolved = $this->schemas->resolveExample($webhookId, $trigger);
        $raw      = $resolved['example'];
        if (is_string($raw)) {
          $raw = json_decode($raw, true);
        }
        if (is_array($raw) && !empty($raw)) {
          $example = $raw;
          $source  = 'example';
          $applied = $this->transformer->applyStoredMapping($webhookId, $trigger, $raw);
          $mapped  = is_array($applied['payload'] ?? null) ? $applied['payload'] : $raw;
        }
      }
    }

    $roots = TemplateContext::sample($event, $webhook, $example, $mapped);

    return [
      'roots'   => $roots,
      'ctx'     => ['event' => $event, 'fired_at' => gmdate('Y-m-d\TH:i:s\Z')],
      'source'  => $source,
      'example' => $example,
      'mapped'  => $mapped,
      'webhook' => $webhook,
    ];
  }

  /**
   * @return array{roots: array<string, mixed>, ctx: array<string, mixed>, source: string, example: ?array, mapped: ?array, webhook: ?array}|null
   */
  private function fromLog(string $event, int $logId): ?array {
    $log = $this->logs->find($logId);
    if (!$log) {
      return null;
    }
    $webhook = !empty($log['webhook_id']) ? $this->webhooks->find((int) $log['webhook_id']) : null;
    $history = is_array($log['attempt_history'] ?? null) ? $log['attempt_history'] : [];
    $last    = !empty($history) ? end($history) : [];
    $attempt = count($history) ?: 1;

    $ctx = DeliveryEvents::normalize($event, [
      'webhook'          => $webhook ?? ['id' => (int) $log['webhook_id'], 'name' => (string) ($log['webhook_name'] ?? ''), 'endpoint_url' => (string) ($log['target_url'] ?? ''), 'webhook_uuid' => (string) ($log['webhook_uuid'] ?? '')],
      'trigger'          => (string) $log['trigger_name'],
      'log_id'           => (int) $log['id'],
      'event_uuid'       => (string) ($log['event_uuid'] ?? ''),
      'event_timestamp'  => (string) ($log['event_timestamp'] ?? $log['created_at']),
      'attempt'          => $attempt,
      'max_attempts'     => null,
      'next_attempt_at'  => $log['next_attempt_at'] ?? null,
      'http_code'        => $log['http_code'] ?? ($last['http_code'] ?? null),
      'error_message'    => $log['error_message'] ?? ($last['error_message'] ?? null),
      'response_body'    => is_string($log['response_body'] ?? null) ? $log['response_body'] : null,
      'duration_ms'      => $log['duration_ms'] ?? ($last['duration_ms'] ?? null),
      'request_url'      => (string) ($log['request_url'] ?? ''),
      'is_test'          => ($log['status'] ?? '') === 'test',
      'payload'          => is_array($log['request_payload'] ?? null) ? $log['request_payload'] : [],
      'original_payload' => is_array($log['original_payload'] ?? null) ? $log['original_payload'] : null,
      'reason'           => $event === DeliveryEvents::PERMANENTLY_FAILED ? DeliveryEvents::REASON_EXHAUSTED : null,
      'had_failures'     => $attempt > 1,
    ]);

    return [
      'roots'   => TemplateContext::fromDeliveryContext($ctx),
      'ctx'     => $ctx,
      'source'  => 'log',
      'example' => is_array($log['original_payload'] ?? null) ? $log['original_payload'] : (is_array($log['request_payload'] ?? null) ? $log['request_payload'] : null),
      'mapped'  => is_array($log['request_payload'] ?? null) ? $log['request_payload'] : null,
      'webhook' => $webhook,
    ];
  }
}
