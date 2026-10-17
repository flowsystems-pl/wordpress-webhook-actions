<?php

namespace FlowSystems\WebhookActions\Abilities;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Api\AuthHelper;
use FlowSystems\WebhookActions\Repositories\WebhookRepository;
use FlowSystems\WebhookActions\Services\Ai\PayloadRedactor;
use WP_Error;

/**
 * Canonical registry of the operations the AI Builder agent can perform.
 *
 * Each ability is a thin, well-described wrapper over the same repositories and
 * services the REST controllers use. The registry is the single source of truth
 * for the toolset: the AgentOrchestrator calls execute() directly, and
 * AbilityRegistrar exposes the same definitions to the WordPress Abilities API
 * (WP 6.9+/7.0) so external MCP clients (Claude Code, Cursor) get an identical
 * toolset for free.
 *
 * The registry itself is a coordinator: the declarative definitions live in
 * AbilityCatalog, and the handler implementations in three collaborators —
 * ReadAbilities (side-effect-free GATHER reads), WriteAbilities (reviewed PLAN
 * steps incl. go-live/delete), TestAbilities (live outbound probe/test calls)
 * and GlueAbilities (Code Glue PHP snippets) — which the catalog binds
 * callbacks to via reads()/writes()/tests()/glue().
 *
 * Safety model:
 *  - `scope` mirrors the API token scopes (read / full). The agent token has
 *    `agent` scope which ranks as full for writes but can never reveal secrets.
 *  - `requires_confirm` abilities (enable / delete / edit-live) are surfaced to
 *    the UI so they pause for an explicit user confirmation before running.
 *  - create_webhook always creates the webhook DISABLED.
 */
class AbilityRegistry {
  /** Ability namespace used when registering with the WP Abilities API. */
  public const NAMESPACE = 'flowsystems-webhook-actions';

  /** Canonical home moved to WriteAbilities; kept as an alias for callers. */
  public const CONDITION_OPERATORS = WriteAbilities::CONDITION_OPERATORS;

  private ?ReadAbilities  $reads  = null;
  private ?WriteAbilities $writes = null;
  private ?TestAbilities  $tests  = null;
  private ?GlueAbilities  $glue   = null;

  /**
   * Return all ability definitions keyed by short name.
   *
   * Each definition: label, description, category, scope, requires_confirm,
   * input_schema (JSON Schema), and a `callback` (callable(array $input):
   * array|WP_Error).
   *
   * Extensions (the Pro plugin) can add or adjust abilities via the
   * `fswa_ability_definitions` filter; everything downstream — the agent's
   * system-prompt catalog, plan execution, and WP Abilities/MCP registration —
   * flows through this method, so filtered abilities work everywhere.
   *
   * @return array<string, array<string, mixed>>
   */
  public function definitions(): array {
    $definitions = AbilityCatalog::build($this);

    /**
     * Filter the agent/MCP ability definitions.
     *
     * @param array<string, array<string, mixed>> $definitions Ability defs keyed by short name.
     */
    return apply_filters('fswa_ability_definitions', $definitions);
  }

  /**
   * Ability names the agent may execute directly as mid-conversation "reads",
   * without user review: everything read-scoped, plus list_credentials (full
   * scope for API tokens, but it only ever returns names/types/masked hints).
   *
   * @return array<int, string>
   */
  public function readAbilityNames(): array {
    $names = [];
    foreach ($this->definitions() as $name => $def) {
      if (($def['scope'] ?? '') === AuthHelper::SCOPE_READ) {
        $names[] = $name;
      }
    }
    $names[] = 'list_credentials';
    return $names;
  }

  /**
   * Whether an ability requires explicit confirmation for this input.
   *
   * Single source of truth for the `requires_confirm` policy, shared by the AI
   * Builder's plan gate (PlanExecutor::stepNeedsConfirm) and the Abilities/MCP
   * boundary (AbilityRegistrar), so the two can never drift apart.
   *
   * Deliberately NOT enforced inside execute(): undo (StepReverter) and the
   * executor's own rollback call delete_webhook/enable_webhook to put things
   * back, and a build that failed halfway must always be able to clean up after
   * itself. Confirmation belongs at the entry points, which is where a human is.
   *
   * @param array<string, mixed> $input
   */
  public function requiresConfirmation(string $name, array $input): bool {
    $definitions = $this->definitions();
    $policy      = $definitions[$name]['requires_confirm'] ?? false;

    return match ($policy) {
      'always'                  => true,
      // `id` for webhook-target abilities (enable/update/delete), `webhook_id`
      // for abilities that attach TO a webhook (assign_snippet, set_mapping…).
      'when_live'               => $this->webhookIsLive((int) ($input['id'] ?? $input['webhook_id'] ?? 0)),
      'when_destructive_method' => in_array(strtoupper((string) ($input['method'] ?? 'GET')), ['PUT', 'PATCH', 'DELETE'], true),
      default                   => false,
    };
  }

  private function webhookIsLive(int $webhookId): bool {
    if ($webhookId <= 0) {
      return false;
    }
    $webhook = (new WebhookRepository())->find($webhookId);
    return $webhook !== null && !empty($webhook['is_enabled']);
  }

  /**
   * Execute an ability by short name. Used by the orchestrator.
   *
   * @return array<string, mixed>|WP_Error
   */
  public function execute(string $name, array $input): array|WP_Error {
    $definitions = $this->definitions();
    if (!isset($definitions[$name])) {
      return new WP_Error('fswa_unknown_ability', sprintf(/* translators: %s: ability name */ __('Unknown ability: %s', 'flowsystems-webhook-actions'), $name), ['status' => 400]);
    }

    return call_user_func($definitions[$name]['callback'], $input);
  }

  /**
   * Scrub an ability result before it is shown to any model, local or remote.
   *
   * A definition opts out with `'redact_result' => false` when its result holds
   * no payload data and the leaf rule only misfires on it: list_triggers maps
   * hook NAME → plugin slug, so `after_password_reset` would lose its slug.
   *
   * @param array<string, mixed> $result
   * @return array<string, mixed>
   */
  public function redactResult(string $name, array $result): array {
    $definition = $this->definitions()[$name] ?? [];
    if (($definition['redact_result'] ?? true) === false) {
      return $result;
    }
    return PayloadRedactor::redact($result);
  }

  // ===================================================================
  // Handler collaborators (bound as callbacks by AbilityCatalog)
  // ===================================================================

  public function reads(): ReadAbilities {
    return $this->reads ??= new ReadAbilities();
  }

  public function writes(): WriteAbilities {
    return $this->writes ??= new WriteAbilities();
  }

  public function tests(): TestAbilities {
    return $this->tests ??= new TestAbilities();
  }

  public function glue(): GlueAbilities {
    return $this->glue ??= new GlueAbilities();
  }
}
