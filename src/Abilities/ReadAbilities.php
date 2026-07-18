<?php

namespace FlowSystems\WebhookActions\Abilities;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Repositories\WebhookRepository;
use FlowSystems\WebhookActions\Repositories\SchemaRepository;
use FlowSystems\WebhookActions\Repositories\LogRepository;
use FlowSystems\WebhookActions\Repositories\CredentialRepository;
use FlowSystems\WebhookActions\Services\HookDiscoveryService;
use FlowSystems\WebhookActions\Services\RestRouteInspector;
use WP_Error;

/**
 * Read-scoped ability handlers — the agent's GATHER phase. Everything here is
 * side-effect free and may run mid-conversation without user review (see
 * AbilityRegistry::readAbilityNames()). Results are replayed to the model on
 * later rounds, so handlers cap and trim their output.
 */
class ReadAbilities {
  use AbilityErrors;

  /** Max triggers a single list_triggers read returns (use search to narrow). */
  private const TRIGGERS_LIST_MAX = 200;

  public function listTriggers(array $input): array {
    $triggers = (new HookDiscoveryService())->discoverWithRuntimeHooks();

    $search = strtolower(trim((string) ($input['search'] ?? '')));
    if ($search !== '') {
      $triggers = array_filter(
        $triggers,
        static fn($source, $hook) => str_contains(strtolower((string) $hook), $search)
          || str_contains(strtolower((string) $source), $search),
        ARRAY_FILTER_USE_BOTH
      );
    }

    // Cap the result: the full catalog is hundreds of hooks and every read
    // result is replayed to the model on later rounds — unfiltered dumps blow
    // up the prompt (and were behind a 60s provider timeout in the field).
    $total = count($triggers);
    $out   = ['triggers' => array_slice($triggers, 0, self::TRIGGERS_LIST_MAX, true), 'total' => $total];
    if ($total > self::TRIGGERS_LIST_MAX) {
      $out['note'] = sprintf(
        'Showing %d of %d triggers — pass {"search":"..."} (hook name or plugin slug substring) to narrow.',
        self::TRIGGERS_LIST_MAX,
        $total
      );
    }
    return $out;
  }

  public function listWebhooks(array $input): array {
    return ['webhooks' => (new WebhookRepository())->getAll()];
  }

  public function getWebhook(array $input): array|WP_Error {
    $webhook = (new WebhookRepository())->find((int) ($input['id'] ?? 0));
    if (!$webhook) {
      return $this->notFound();
    }
    $webhook['schemas'] = (new SchemaRepository())->getByWebhook((int) $webhook['id']);
    return ['webhook' => $webhook];
  }

  public function getTriggerSchema(array $input): array|WP_Error {
    $webhookId = (int) ($input['webhook_id'] ?? 0);
    $trigger   = (string) ($input['trigger'] ?? '');
    if ($trigger === '') {
      return $this->invalid(__('trigger is required.', 'flowsystems-webhook-actions'));
    }
    // webhook_id 0 = trigger-wide lookup: no own row, resolveExample() borrows
    // the latest capture for this trigger from any webhook.
    $repo   = new SchemaRepository();
    $schema = $webhookId > 0 ? $repo->findByWebhookAndTrigger($webhookId, $trigger) : null;

    // Resolve the effective example: this webhook's own capture, or — when reuse
    // is enabled (the default) — the latest one for the same trigger on another
    // webhook (the do_action payload shape is trigger-global), so we don't force
    // a fresh test.
    $resolved = $repo->resolveExample($webhookId, $trigger, $schema);
    if ($resolved['example'] === null) {
      // Nothing captured anywhere yet → null signals "submit a test first".
      return ['schema' => null];
    }

    $schema = array_merge(
      $schema ?: ['webhook_id' => $webhookId, 'trigger_name' => $trigger],
      ['example_payload' => $resolved['example']]
    );
    $result = ['schema' => $schema];
    if ($this->captureIsOpaque($resolved['example'])) {
      $result['capture_warning'] = 'This capture is UNUSABLE for mapping or conditions: its args contain only opaque '
        . 'object placeholders (a lone "__type" key with no data fields) — typically captured by an older plugin '
        . 'version. Do NOT propose set_mapping or set_conditions from it and never invent field paths. Instead, show '
        . 'the user exactly what the capture contains, explain that it holds no usable fields, and ask them to fire '
        . 'the event once more (e.g. re-submit the form) so a fresh payload is captured — then re-read get_trigger_schema.';
    }
    if ($resolved['source'] === 'shared') {
      $result['borrowed_from_webhook_id'] = $resolved['from_webhook_id'];
    }
    return $result;
  }

  /**
   * True when a captured example's args carry no mappable data — every arg is
   * an opaque object placeholder like {"__type":"WPCF7_ContactForm"} (how
   * older plugin versions serialized objects they could not unpack). Such a
   * capture cannot back set_mapping or set_conditions.
   *
   * @param array<string, mixed> $example
   */
  private function captureIsOpaque(array $example): bool {
    $args = $example['args'] ?? null;
    if (!is_array($args) || $args === []) {
      return false;
    }
    foreach ($args as $arg) {
      if (!is_array($arg)) {
        return false; // Scalar arg — mappable as-is.
      }
      unset($arg['__type']);
      if ($arg !== []) {
        return false; // Carries real data fields.
      }
    }
    return true;
  }

  public function getLogs(array $input): array {
    $repo  = new LogRepository();
    $limit = max(1, min(100, (int) ($input['limit'] ?? 10)));
    if (!empty($input['webhook_id'])) {
      $result = $repo->getByWebhook((int) $input['webhook_id'], 1, $limit);
    } else {
      $result = $repo->getPaginated([], 1, $limit);
    }
    return ['logs' => $result['items'] ?? $result];
  }

  public function listCredentials(array $input): array {
    return ['credentials' => (new CredentialRepository())->getAll()];
  }

  public function getRestRouteSchema(array $input): array|WP_Error {
    return (new RestRouteInspector())->describe(
      (string) ($input['route'] ?? ''),
      (string) ($input['method'] ?? 'POST')
    );
  }
}
