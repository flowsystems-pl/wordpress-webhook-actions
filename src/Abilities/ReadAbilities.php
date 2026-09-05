<?php

namespace FlowSystems\WebhookActions\Abilities;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Repositories\WebhookRepository;
use FlowSystems\WebhookActions\Repositories\SchemaRepository;
use FlowSystems\WebhookActions\Repositories\LogRepository;
use FlowSystems\WebhookActions\Repositories\CredentialRepository;
use FlowSystems\WebhookActions\Services\ExampleResolver;
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

  /** Max description characters list_webhooks returns per webhook. */
  private const DESCRIPTION_SNIPPET_MAX = 200;

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
    $webhooks = array_map(
      fn(array $w): array => $this->trimDescription($this->redactSecrets($w)),
      (new WebhookRepository())->getAll()
    );
    return ['webhooks' => $webhooks];
  }

  /**
   * Cut a webhook's description down to a snippet for the LIST read.
   *
   * Descriptions became a first-class feature in 2.4.0 and published builds ship
   * long markdown ones — on a real site they are the bulk of this response, and
   * every read here is replayed to the model on later rounds. The agent uses this
   * list to find a webhook by name or id, which a snippet serves just as well;
   * get_webhook still returns the description in full when it actually matters.
   *
   * @param array<string, mixed> $webhook
   * @return array<string, mixed>
   */
  private function trimDescription(array $webhook): array {
    $description = (string) ($webhook['description'] ?? '');
    if (mb_strlen($description) > self::DESCRIPTION_SNIPPET_MAX) {
      $webhook['description']           = mb_substr($description, 0, self::DESCRIPTION_SNIPPET_MAX) . '…';
      $webhook['description_truncated'] = true;
    }
    return $webhook;
  }

  public function getWebhook(array $input): array|WP_Error {
    $webhook = (new WebhookRepository())->find((int) ($input['id'] ?? 0));
    if (!$webhook) {
      return $this->notFound();
    }
    $webhook['schemas'] = (new SchemaRepository())->getByWebhook((int) $webhook['id']);
    return ['webhook' => $this->redactSecrets($webhook)];
  }

  /**
   * Mask the legacy plaintext auth_header before a webhook leaves this class.
   *
   * Everything on the abilities path is consumed by an AI: Build with AI replays
   * these results into the model's context on later rounds, and external MCP/REST
   * callers read them directly. Neither should ever receive a live credential, so
   * this is unconditional — unlike WebhooksController::prepareWebhook(), which
   * gates on AuthHelper::canRevealSecrets() because it also serves the admin UI,
   * where an administrator is entitled to see the value they typed in.
   *
   * Vault credentials never needed this: only the non-secret auth_credential_id
   * reference is stored on the webhook, and CredentialRepository selects a
   * public column list that excludes the ciphertext entirely.
   *
   * @param array<string, mixed> $webhook
   * @return array<string, mixed>
   */
  private function redactSecrets(array $webhook): array {
    if (!empty($webhook['auth_header'])) {
      $webhook['auth_header'] = '[redacted]';
    }
    return $webhook;
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

    // Resolve the effective example: this webhook's own capture, then a capture
    // for the same trigger on another webhook here (the do_action payload shape
    // is trigger-global), then — for hosted-AI installs — our reference payload
    // for this hook, so a build no longer stalls on a trigger the site has never
    // fired.
    $resolved = (new ExampleResolver($repo))->resolve($webhookId, $trigger, $schema);
    if ($resolved['example'] === null) {
      // Nothing captured anywhere yet. A bare null used to send agents hunting:
      // they would spend every read round guessing sibling hooks
      // (transition_post_status → wp_after_insert_post → save_post → …), which
      // all answer null too, and then plan against invented field paths. Answer
      // with the decisive facts instead: this one has nothing, here is the whole
      // set that DOES, and firing the event is the only way to get a capture.
      $captured = array_keys((new SchemaRepository())->latestExamplesPerTrigger(30));

      return [
        'schema'            => null,
        'captured_triggers' => $captured,
        'hint'              => sprintf(
          /* translators: 1: trigger name, 2: comma-separated list of triggers that have captures. */
          __('No payload has ever been captured for "%1$s", so there is nothing to map or filter on. Reading other triggers will not help: the ONLY triggers with a capture are: %2$s. Do NOT retry this with a different hook name, and do NOT propose set_mapping or set_conditions with guessed field paths. Either pick a trigger from that list, or stop and tell the user in plain words which event to fire (e.g. "publish a test post") so the payload is captured — then read this again.', 'flowsystems-webhook-actions'),
          $trigger,
          $captured === [] ? __('(none yet on this site)', 'flowsystems-webhook-actions') : implode(', ', $captured)
        ),
      ];
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

    // A library payload is OUR fixture's, not this site's. Say so, in the same
    // breath as the shape itself — the model must not treat these field paths
    // the way it treats a real capture, and the constraint has to travel with
    // the data rather than sit in the system prompt where it applies to every
    // trigger equally.
    if ($resolved['source'] === 'library' && is_array($resolved['library'])) {
      $result['example_source']  = 'library';
      $result['captured_from']   = $resolved['library']['captured_from'];
      $result['confidence']      = $resolved['library']['confidence'];
      $result['library_caveat']  = $resolved['library']['caveat'];
      $result['site_defined_paths'] = $resolved['library']['unsafe']['site_defined'] ?? [];
      $result['must_verify']     = $resolved['library']['verify'];
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
