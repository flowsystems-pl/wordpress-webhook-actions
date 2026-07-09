<?php

namespace FlowSystems\WebhookActions\Services\Ai;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Abilities\AbilityRegistry;
use FlowSystems\WebhookActions\Repositories\SchemaRepository;
use FlowSystems\WebhookActions\Repositories\WebhookRepository;

/**
 * The mechanics of undoing a single executed plan step: which steps are
 * revertible, snapshotting the state a step is about to overwrite (so it can be
 * restored), and applying the inverse change. {@see PlanExecutor} owns the undo
 * stack and persistence; this owns the per-step reversal itself.
 */
final class StepReverter {
  private AbilityRegistry $registry;

  public function __construct(AbilityRegistry $registry) {
    $this->registry = $registry;
  }

  /**
   * Whether a completed step's change can be undone.
   *
   * @param array<string, mixed> $step
   */
  public function isRevertible(array $step): bool {
    return in_array((string) ($step['ability'] ?? ''), [
      'create_webhook',
      'update_webhook',
      'set_mapping',
      'set_conditions',
      'assign_credential',
      'enable_webhook',
    ], true);
  }

  /**
   * Snapshot the current state an ability is about to overwrite, so it can be
   * restored later. Returns null for abilities whose change is not reverted this
   * way (create_webhook is undone by deleting its result; reads never mutate).
   *
   * @param array<string, mixed> $input Resolved step input.
   * @return array<string, mixed>|null
   */
  public function captureBefore(string $ability, array $input): ?array {
    $webhookRepo = new WebhookRepository();

    switch ($ability) {
      case 'update_webhook':
        $webhook = $webhookRepo->find((int) ($input['id'] ?? 0));
        if (!$webhook) {
          return null;
        }
        return array_intersect_key(
          $webhook,
          array_flip(['name', 'endpoint_url', 'http_method', 'triggers', 'auth_credential_id', 'custom_headers', 'url_params'])
        );

      case 'enable_webhook':
        $webhook = $webhookRepo->find((int) ($input['id'] ?? 0));
        return $webhook ? ['enabled' => !empty($webhook['is_enabled'])] : null;

      case 'assign_credential':
        $webhook = $webhookRepo->find((int) ($input['webhook_id'] ?? 0));
        return $webhook ? ['credential_id' => $webhook['auth_credential_id'] ?? null] : null;

      case 'set_mapping':
        $schema = (new SchemaRepository())->findByWebhookAndTrigger((int) ($input['webhook_id'] ?? 0), (string) ($input['trigger'] ?? ''));
        return ['field_mapping' => $schema['field_mapping'] ?? null];

      case 'set_conditions':
        $schema = (new SchemaRepository())->findByWebhookAndTrigger((int) ($input['webhook_id'] ?? 0), (string) ($input['trigger'] ?? ''));
        return [
          'conditions'             => $schema['conditions'] ?? null,
          'conditions_evaluate_on' => $schema['conditions_evaluate_on'] ?? 'original',
        ];

      default:
        return null;
    }
  }

  /**
   * Apply the inverse of a completed step. Returns the ability result, null when
   * there is nothing to do, or a WP_Error on failure.
   *
   * @param array<string, mixed> $step
   * @return array<string, mixed>|\WP_Error|null
   */
  public function applyRevert(array $step) {
    $ability = (string) ($step['ability'] ?? '');
    $input   = (array) ($step['input'] ?? []);
    $prev    = is_array($step['prev'] ?? null) ? $step['prev'] : [];
    $result  = (array) ($step['result'] ?? []);

    switch ($ability) {
      case 'create_webhook':
        $id = (int) ($result['webhook']['id'] ?? 0);
        return $id > 0 ? $this->registry->execute('delete_webhook', ['id' => $id]) : null;

      case 'update_webhook':
        if ($prev === []) {
          return null;
        }
        return $this->registry->execute('update_webhook', ['id' => (int) ($input['id'] ?? 0)] + $prev);

      case 'enable_webhook':
        return $this->registry->execute('enable_webhook', [
          'id'      => (int) ($input['id'] ?? 0),
          'enabled' => !empty($prev['enabled']),
        ]);

      case 'assign_credential':
        return $this->registry->execute('assign_credential', [
          'webhook_id'    => (int) ($input['webhook_id'] ?? 0),
          'credential_id' => $prev['credential_id'] ?? null,
        ]);

      case 'set_mapping':
        return $this->registry->execute('set_mapping', [
          'webhook_id'    => (int) ($input['webhook_id'] ?? 0),
          'trigger'       => (string) ($input['trigger'] ?? ''),
          'field_mapping' => $prev['field_mapping'] ?? [],
        ]);

      case 'set_conditions':
        return $this->registry->execute('set_conditions', [
          'webhook_id'             => (int) ($input['webhook_id'] ?? 0),
          'trigger'                => (string) ($input['trigger'] ?? ''),
          'conditions'             => $prev['conditions'] ?? [],
          'conditions_evaluate_on' => $prev['conditions_evaluate_on'] ?? 'original',
        ]);

      default:
        return null;
    }
  }
}
