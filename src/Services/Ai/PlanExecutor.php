<?php

namespace FlowSystems\WebhookActions\Services\Ai;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Abilities\AbilityRegistry;
use FlowSystems\WebhookActions\Repositories\AgentConversationRepository;
use FlowSystems\WebhookActions\Repositories\WebhookRepository;
use FlowSystems\WebhookActions\Services\ActivityLogService;
use WP_Error;

/**
 * Runs an agent plan against the AbilityRegistry: the step-by-step execution
 * state machine (advanceStep), plan seeding/normalization, the revert/undo
 * stack, and the small helpers that gate and resolve each step.
 *
 * The AgentOrchestrator owns the conversation turn and delegates all plan
 * execution and reverting here. Cohesive sub-concerns live in collaborators:
 * {@see BuildLedger} (idempotent reuse of already-built objects),
 * {@see StepReverter} (per-step undo mechanics) and {@see ProbeInterpreter}.
 */
class PlanExecutor {
  private AgentConversationRepository $conversations;
  private AbilityRegistry             $registry;
  private ActivityLogService          $activity;
  private BuildLedger                 $ledger;
  private StepReverter                $reverter;

  public function __construct(
    AgentConversationRepository $conversations,
    AbilityRegistry $registry,
    ActivityLogService $activity
  ) {
    $this->conversations = $conversations;
    $this->registry      = $registry;
    $this->activity      = $activity;
    $this->ledger        = new BuildLedger();
    $this->reverter      = new StepReverter($registry);
  }

  /**
   * Execute a plan (as stored, or a user-edited version passed in). Applies steps
   * in order; pauses at the first step that needs confirmation and isn't yet
   * confirmed. Returns what was applied plus an undo recipe.
   *
   * @param array<int, array<string, mixed>>|null $planOverride Edited plan from the UI, if any.
   * @param array<int, string>                    $confirmed    Step ids the user has confirmed.
   * @return array<string, mixed>|WP_Error
   */
  public function execute(int $conversationId, ?array $planOverride = null, array $confirmed = []): array|WP_Error {
    $conversation = $this->conversations->find($conversationId);
    if (!$conversation) {
      return new WP_Error('fswa_conversation_not_found', __('Conversation not found.', 'flowsystems-webhook-actions'), ['status' => 404]);
    }

    $plan      = $this->normalizePlan($planOverride ?? ($conversation['plan_json'] ?? []));
    $confirmed = array_map('strval', $confirmed);
    $applied   = is_array($conversation['last_recipe_json'] ?? null) ? $conversation['last_recipe_json'] : [];
    $results   = [];

    foreach ($plan as $step) {
      $stepId  = (string) $step['id'];
      $ability = (string) $step['ability'];
      $input   = (array) ($step['input'] ?? []);

      if ($this->stepNeedsConfirm($step) && !in_array($stepId, $confirmed, true)) {
        $this->persistRecipe($conversationId, $applied, $plan);
        return [
          'status'         => 'needs_confirm',
          'pending_step'   => $step,
          'applied'        => $applied,
          'results'        => $results,
        ];
      }

      $result = $this->registry->execute($ability, $input);

      if (is_wp_error($result)) {
        $this->persistRecipe($conversationId, $applied, $plan);
        return [
          'status'      => 'error',
          'failed_step' => $step,
          'error'       => $result->get_error_message(),
          'applied'     => $applied,
          'results'     => $results,
        ];
      }

      $this->activity->log(
        'agent.' . $ability,
        StepResult::objectType($ability),
        StepResult::objectId($result),
        $step['summary'] ?? null,
        $this->abilityLogContext($ability, $result) + ['_reason' => $step['summary'] ?? '']
      );

      $applied[] = ['id' => $stepId, 'ability' => $ability, 'result' => $result];
      $results[] = ['id' => $stepId, 'ability' => $ability, 'ok' => true, 'result' => $result];
    }

    $this->persistRecipe($conversationId, $applied, []);

    return ['status' => 'completed', 'applied' => $applied, 'results' => $results];
  }

  /**
   * The current execution mode: 'auto' (the agent runs the plan step by step) or
   * 'review' (the user reviews/edits the plan before running). Stored globally.
   */
  public function execMode(): string {
    return get_option('fswa_ai_exec_mode', 'auto') === 'review' ? 'review' : 'auto';
  }

  public function saveExecMode(string $mode): string {
    $mode = $mode === 'review' ? 'review' : 'auto';
    update_option('fswa_ai_exec_mode', $mode, false);
    return $mode;
  }

  /**
   * Advance the plan by exactly one step (the frontend calls this in a loop to
   * animate progress). Resolves step references, pauses for missing required
   * input, unmet prerequisites, or confirmation, then runs the ability.
   *
   * @param array{patch?:array<string,mixed>, confirm?:bool, skip?:bool} $opts
   * @return array<string, mixed>|WP_Error
   */
  public function advanceStep(int $conversationId, array $opts = []): array|WP_Error {
    $conversation = $this->conversations->find($conversationId);
    if (!$conversation) {
      return new WP_Error('fswa_conversation_not_found', __('Conversation not found.', 'flowsystems-webhook-actions'), ['status' => 404]);
    }

    $execution = is_array($conversation['execution_json'] ?? null) ? $conversation['execution_json'] : null;
    if ($execution === null) {
      // Seed lazily from the stored plan if a run hasn't been started yet.
      $plan = $this->normalizePlan($conversation['plan_json'] ?? []);
      if ($plan === []) {
        return new WP_Error('fswa_no_plan', __('There is no plan to run yet.', 'flowsystems-webhook-actions'), ['status' => 409]);
      }
      $execution = $this->seedExecution($plan);
    }

    $steps  = array_values((array) ($execution['steps'] ?? []));
    $refs   = (array) ($execution['refs'] ?? []);
    $cursor = (int) ($execution['cursor'] ?? 0);

    if ($cursor >= count($steps)) {
      return $this->persistExecution($conversationId, $execution, null, false, true);
    }

    $step = $steps[$cursor];

    // Already built earlier in this conversation (pre-marked done/reused when the
    // plan was re-seeded) — don't run it again. Carry its object id to downstream
    // steps and advance, so a re-proposed create_webhook / provision can't make a
    // duplicate even when auto mode fires the whole plan.
    if (in_array((string) ($step['status'] ?? ''), ['done', 'skipped', 'reverted'], true)) {
      $objectId = StepResult::objectId((array) ($step['result'] ?? []));
      if ($objectId !== null) {
        $refs[(string) ($step['id'] ?? '')] = $objectId;
        $execution['refs'] = $refs;
      }
      $execution['cursor'] = $cursor + 1;
      $finished            = ($cursor + 1) >= count($steps);
      return $this->persistExecution($conversationId, $execution, $step, !$finished, $finished);
    }

    // Skip on request — mark and advance.
    if (!empty($opts['skip'])) {
      $step['status']      = 'skipped';
      $steps[$cursor]      = $step;
      $execution['steps']  = $steps;
      $execution['cursor'] = $cursor + 1;
      $finished            = ($cursor + 1) >= count($steps);
      return $this->persistExecution($conversationId, $execution, $step, !$finished, $finished);
    }

    // Resolve references (e.g. "step_2" → the created webhook id), then apply any
    // user-supplied input (e.g. the endpoint_url they just typed).
    $input = $this->resolveRefs((array) ($step['input'] ?? []), $refs);
    if (!empty($opts['patch']) && is_array($opts['patch'])) {
      foreach ($opts['patch'] as $key => $value) {
        $input[(string) $key] = $value;
      }
    }
    $step['input'] = $input;

    // 0a) A probe should validate the webhook we just built, not a hallucinated
    // raw URL. When the step has no webhook_id but a webhook was created earlier in
    // the run, bind the probe to it so it reuses the real endpoint URL + credential.
    if ((string) $step['ability'] === 'probe_endpoint' && (int) ($input['webhook_id'] ?? 0) <= 0) {
      $wid = $this->createdWebhookId($steps, $cursor);
      if ($wid > 0) {
        unset($input['url']);
        $input['webhook_id'] = $wid;
        $step['input']       = $input;
      }
    }

    // 0b) Inline probe fix: correct the probed webhook (endpoint URL or credential)
    // then re-run the probe. probe_endpoint reuses the webhook's values, so fixing
    // the webhook is what makes the retry meaningful. Falls back to patching the
    // probe's own input when the step is not bound to a webhook.
    if (!empty($opts['probe_fix']) && is_array($opts['probe_fix']) && (string) $step['ability'] === 'probe_endpoint') {
      $webhookId = (int) ($input['webhook_id'] ?? 0);
      $fix       = $opts['probe_fix'];
      if (!empty($fix['endpoint_url'])) {
        if ($webhookId > 0) {
          $this->registry->execute('update_webhook', ['id' => $webhookId, 'endpoint_url' => (string) $fix['endpoint_url']]);
        } else {
          unset($input['webhook_id']);
          $input['url'] = (string) $fix['endpoint_url'];
        }
      }
      if (!empty($fix['auth_credential_id'])) {
        if ($webhookId > 0) {
          $this->registry->execute('assign_credential', ['webhook_id' => $webhookId, 'credential_id' => (int) $fix['auth_credential_id']]);
        } else {
          $input['auth_credential_id'] = (int) $fix['auth_credential_id'];
        }
      }
      $step['input'] = $input;
    }

    // 1) Required input still blank → pause for the user.
    $missing = $this->missingRequired($step);
    if ($missing !== []) {
      $step['status']     = 'blocked_input';
      $step['missing']    = $missing;
      $steps[$cursor]     = $step;
      $execution['steps'] = $steps;
      return $this->persistExecution($conversationId, $execution, $step, false, false);
    }
    unset($step['missing']);

    // 2) Confirmation gate (go-live / delete / edit-live / unsafe probe).
    if ($this->stepNeedsConfirm($step) && empty($opts['confirm'])) {
      $step['status']     = 'needs_confirm';
      $steps[$cursor]     = $step;
      $execution['steps'] = $steps;
      return $this->persistExecution($conversationId, $execution, $step, false, false);
    }

    // 3) Snapshot the object's state BEFORE mutating it (so we can revert), then
    // run the ability.
    $before = $this->reverter->captureBefore((string) $step['ability'], $input);
    $result = $this->registry->execute((string) $step['ability'], $input);

    // 3a) Prerequisite not met: get_trigger_schema has nothing captured yet.
    if ((string) $step['ability'] === 'get_trigger_schema' && is_array($result) && ($result['schema'] ?? null) === null) {
      $step['status'] = 'blocked_prereq';
      $step['prereq'] = [
        'kind'       => 'capture_payload',
        'webhook_id' => (int) ($input['webhook_id'] ?? 0),
        'trigger'    => (string) ($input['trigger'] ?? ''),
      ];
      $steps[$cursor]     = $step;
      $execution['steps'] = $steps;
      return $this->persistExecution($conversationId, $execution, $step, false, false);
    }
    unset($step['prereq']);

    // 3b) Error → pause; the user may retry (call again) or skip.
    if (is_wp_error($result)) {
      $step['status']     = 'failed';
      $step['error']      = $result->get_error_message();
      $steps[$cursor]     = $step;
      $execution['steps'] = $steps;
      return $this->persistExecution($conversationId, $execution, $step, false, false);
    }
    unset($step['error']);

    // 3b-2) probe_endpoint reached the target but got an actionable status
    // (auth needed / wrong endpoint / unreachable) — pause with guidance so the
    // user can fix the webhook and retry, rather than silently marking it done.
    if ((string) $step['ability'] === 'probe_endpoint' && is_array($result)) {
      $probe = ProbeInterpreter::interpret($result);
      if ($probe !== null) {
        $step['status']     = 'blocked_probe';
        $step['probe']      = $probe;
        $steps[$cursor]     = $step;
        $execution['steps'] = $steps;
        return $this->persistExecution($conversationId, $execution, $step, false, false);
      }
    }
    unset($step['probe']);

    // 3c) Success → record result (+ pre-state for undo), expose its id to
    // downstream steps, advance.
    $step['status'] = 'done';
    $step['result'] = $result;
    if ($before !== null) {
      $step['prev'] = $before;
    }
    $steps[$cursor] = $step;

    $objectId = StepResult::objectId((array) $result);
    if ($objectId !== null) {
      $refs[(string) $step['id']] = $objectId;
    }

    // 3c-1) Safety net: a freshly provisioned WP Application Password credential
    // is useless until it's attached to the webhook. Weaker models sometimes emit
    // the provision step but drop the follow-up assign_credential, leaving the
    // credential created-but-unassigned (nothing authenticates). If a webhook was
    // created earlier in THIS run and still has no credential, wire the new one to
    // it automatically. A later explicit assign_credential (if the model did add
    // one) simply re-assigns the same id — harmless.
    if ((string) $step['ability'] === 'provision_wp_app_password' && $objectId) {
      $wid = $this->createdWebhookId($steps, $cursor);
      if ($wid > 0) {
        $webhook = (new WebhookRepository())->find($wid);
        if ($webhook && empty($webhook['auth_credential_id'])) {
          $this->registry->execute('assign_credential', ['webhook_id' => $wid, 'credential_id' => $objectId]);
          $step['result']['auto_assigned_webhook_id'] = $wid;
          $steps[$cursor] = $step;
        }
      }
    }

    $this->activity->log(
      'agent.' . $step['ability'],
      StepResult::objectType((string) $step['ability']),
      $objectId,
      $step['summary'] ?? null,
      $this->abilityLogContext((string) $step['ability'], $result) + ['_reason' => $step['summary'] ?? '']
    );

    // Record a freshly created object in the build ledger so later steps in this
    // run — and the system prompt next turn — treat it as built (never re-create).
    if (BuildLedger::handles((string) $step['ability']) && $objectId) {
      $execution['ledger'] = $this->ledger->record(
        is_array($execution['ledger'] ?? null) ? $execution['ledger'] : [],
        $step,
        (int) $objectId
      );
    }

    $execution['steps']  = $steps;
    $execution['refs']   = $refs;
    $execution['cursor'] = $cursor + 1;
    $finished            = ($cursor + 1) >= count($steps);
    return $this->persistExecution($conversationId, $execution, $step, !$finished, $finished);
  }

  /**
   * Revert the most recent applied change in this build. Walks the executed steps
   * backwards, finds the last still-applied revertible step, restores its
   * pre-state (or deletes what it created), and marks it `reverted`. Repeated
   * calls walk further back — an undo stack.
   *
   * @return array<string, mixed>|WP_Error
   */
  public function revertLast(int $conversationId): array|WP_Error {
    $conversation = $this->conversations->find($conversationId);
    if (!$conversation) {
      return new WP_Error('fswa_conversation_not_found', __('Conversation not found.', 'flowsystems-webhook-actions'), ['status' => 404]);
    }

    $execution = is_array($conversation['execution_json'] ?? null) ? $conversation['execution_json'] : null;
    if ($execution === null) {
      return new WP_Error('fswa_nothing_to_revert', __('There is nothing to revert.', 'flowsystems-webhook-actions'), ['status' => 409]);
    }

    $steps = array_values((array) ($execution['steps'] ?? []));
    for ($i = count($steps) - 1; $i >= 0; $i--) {
      $step = $steps[$i];
      if ((string) ($step['status'] ?? '') !== 'done' || !$this->reverter->isRevertible($step)) {
        continue;
      }

      $revert = $this->reverter->applyRevert($step);
      if (is_wp_error($revert)) {
        return $revert;
      }
      if ($revert === null) {
        continue;
      }

      $step['status'] = 'reverted';
      $steps[$i]      = $step;
      $execution['steps'] = $steps;

      // Record the undo in the conversation so it shows in the chat and the model
      // knows the change was rolled back on the next turn.
      $transcript   = is_array($conversation['transcript_json'] ?? null) ? $conversation['transcript_json'] : [];
      $note         = sprintf(
        /* translators: %s: what was undone. */
        __('↩︎ Reverted: %s', 'flowsystems-webhook-actions'),
        (string) ($step['summary'] ?? $step['ability'])
      );
      $transcript[] = ['role' => 'assistant', 'content' => $note];

      $this->conversations->update($conversationId, [
        'execution'  => $execution,
        'transcript' => $transcript,
      ]);

      $this->activity->log(
        'agent.revert.' . $step['ability'],
        StepResult::objectType((string) $step['ability']),
        StepResult::objectId((array) ($step['result'] ?? [])),
        $step['summary'] ?? null,
        ['meta' => ['reverted' => $step['ability']], '_reason' => 'Reverted: ' . ($step['summary'] ?? '')]
      );

      return [
        'execution'  => $execution,
        'transcript' => $transcript,
        'reverted'   => $step,
        'continue'   => false,
        'finished'   => true,
      ];
    }

    return new WP_Error('fswa_nothing_to_revert', __('There is nothing left to revert.', 'flowsystems-webhook-actions'), ['status' => 409]);
  }

  /**
   * Best-effort undo of the last applied recipe: deletes created webhooks /
   * chains / links and disables anything that was enabled. Mapping/condition
   * edits are not reverted (they are non-destructive overwrites).
   *
   * @return array<string, mixed>|WP_Error
   */
  public function undoLast(int $conversationId): array|WP_Error {
    $conversation = $this->conversations->find($conversationId);
    if (!$conversation) {
      return new WP_Error('fswa_conversation_not_found', __('Conversation not found.', 'flowsystems-webhook-actions'), ['status' => 404]);
    }

    $recipe   = is_array($conversation['last_recipe_json'] ?? null) ? $conversation['last_recipe_json'] : [];
    $reverted = [];

    // Undo in reverse application order.
    foreach (array_reverse($recipe) as $entry) {
      $ability = (string) ($entry['ability'] ?? '');
      $result  = (array) ($entry['result'] ?? []);

      $undo = match ($ability) {
        'create_webhook' => $this->registry->execute('delete_webhook', ['id' => (int) ($result['webhook']['id'] ?? 0)]),
        'enable_webhook' => $this->registry->execute('enable_webhook', ['id' => (int) ($result['id'] ?? 0), 'enabled' => false]),
        default          => null,
      };

      if ($undo !== null && !is_wp_error($undo)) {
        $reverted[] = $ability;
      }
    }

    $this->conversations->update($conversationId, ['last_recipe' => null]);
    $this->activity->log('agent.undo', 'agent', null, null, ['meta' => ['reverted' => $reverted]]);

    return ['status' => 'undone', 'reverted' => $reverted];
  }

  /**
   * Build a fresh execution state machine from a normalized plan.
   *
   * A re-plan mid-build re-seeds this — so the prior run's applied-object ledger
   * is carried forward and any step that would re-create something already built
   * in this conversation is pre-marked `done` (reusing the recorded result). That
   * is what stops a re-proposed create_webhook / provision step from making a
   * duplicate when the run (in auto mode) fires again; downstream {{step.id}}
   * references still resolve because the reused object id is seeded into refs.
   *
   * @param array<int, array<string, mixed>> $plan
   * @param array<string, mixed>             $prior The prior execution_json, if any.
   * @return array<string, mixed>
   */
  public function seedExecution(array $plan, ?string $mode = null, array $prior = []): array {
    $ledger = $this->ledger->carryForward($prior);

    $steps = [];
    $refs  = [];
    foreach ($plan as $step) {
      $id    = (string) ($step['id'] ?? '');
      $entry = [
        'id'               => $id,
        'ability'          => (string) ($step['ability'] ?? ''),
        'summary'          => (string) ($step['summary'] ?? ''),
        'input'            => (array) ($step['input'] ?? []),
        'requires_confirm' => (bool) ($step['requires_confirm'] ?? false),
        'status'           => 'pending',
      ];

      // Already built earlier in this conversation? Reuse it: pre-mark the step
      // done with the recorded result and expose its id to downstream steps.
      $match = $this->ledger->match($ledger, $entry);
      if ($match !== null) {
        $entry['status'] = 'done';
        $entry['result'] = is_array($match['result'] ?? null) ? $match['result'] : [];
        $entry['reused'] = true;
        if ((int) ($match['object_id'] ?? 0) > 0 && $id !== '') {
          $refs[$id] = (int) $match['object_id'];
        }
      }

      $steps[] = $entry;
    }

    return [
      'mode'   => $mode ?: $this->execMode(),
      'cursor' => 0,
      'refs'   => $refs === [] ? (object) [] : $refs,
      'steps'  => $steps,
      'ledger' => $ledger,
    ];
  }

  /**
   * Normalize a plan into a stable, validated shape with per-step ids and
   * resolved confirmation flags (so the UI can render confirm controls).
   *
   * @param mixed $plan
   * @return array<int, array<string, mixed>>
   */
  public function normalizePlan($plan): array {
    if (!is_array($plan)) {
      return [];
    }

    $definitions = $this->registry->definitions();
    $normalized  = [];
    $i           = 0;

    foreach ($plan as $step) {
      if (!is_array($step) || empty($step['ability']) || !isset($definitions[$step['ability']])) {
        continue;
      }
      $normalized[] = [
        'id'               => (string) ($step['id'] ?? ('step_' . (++$i))),
        'ability'          => (string) $step['ability'],
        'summary'          => (string) ($step['summary'] ?? $definitions[$step['ability']]['label']),
        'input'            => (array) ($step['input'] ?? []),
        'requires_confirm' => $this->stepNeedsConfirm($step),
      ];
    }

    return $normalized;
  }

  // ===================================================================
  // Internals
  // ===================================================================

  /**
   * Persist execution state and shape the step response for the frontend loop.
   *
   * @param array<string, mixed>      $execution
   * @param array<string, mixed>|null $acted     The step processed this call.
   * @return array<string, mixed>
   */
  private function persistExecution(int $conversationId, array $execution, ?array $acted, bool $continue, bool $finished): array {
    $this->conversations->update($conversationId, ['execution' => $execution]);
    return [
      'execution' => $execution,
      'acted'     => $acted,
      'continue'  => $continue,
      'finished'  => $finished,
    ];
  }

  /**
   * Replace step-reference inputs (a string equal to a prior step id) with the
   * concrete id that step produced. Top-level values only.
   *
   * @param array<string, mixed> $input
   * @param array<string, mixed> $refs
   * @return array<string, mixed>
   */
  private function resolveRefs(array $input, array $refs): array {
    foreach ($input as $key => $value) {
      if (is_string($value)) {
        $input[$key] = $this->resolveRefValue($value, $refs);
      }
    }
    return $input;
  }

  /**
   * Resolve a single reference value to the id a prior step produced. Accepts the
   * mustache form the model emits — `{{step_2.id}}` or `{{step_2}}` — and the bare
   * `step_2`. Unknown references are left untouched (the step will then surface a
   * normal error rather than silently using a placeholder).
   *
   * @param array<string, mixed> $refs
   * @return mixed
   */
  private function resolveRefValue(string $value, array $refs) {
    // Models embed site placeholders inside longer strings (e.g.
    // "{{site.url}}/wp-json/wp/v2/users") — expand them in place. rest_url()
    // keeps its trailing slash (models append the route bare); a duplicate
    // slash from "{{site.rest_url}}/wp/v2/users" is collapsed afterwards.
    $expanded = preg_replace_callback('/\{\{\s*site\.(url|home_url|rest_url)\s*\}\}/', static function (array $m): string {
      return $m[1] === 'rest_url' ? rest_url() : untrailingslashit(home_url());
    }, $value, -1, $count);
    if ($count > 0) {
      $value = preg_replace('#(?<!:)//+#', '/', $expanded);
    }

    if (preg_match('/^\{\{\s*(step_[A-Za-z0-9]+)(?:\.[A-Za-z0-9_]+)?\s*\}\}$/', $value, $m)) {
      return array_key_exists($m[1], $refs) ? $refs[$m[1]] : $value;
    }
    return array_key_exists($value, $refs) ? $refs[$value] : $value;
  }

  /**
   * Required input keys that are still empty for this step (after ref resolution
   * and any user patch) — the values we must pause and ask the user for.
   *
   * @param array<string, mixed> $step
   * @return array<int, string>
   */
  private function missingRequired(array $step): array {
    $definitions = $this->registry->definitions();
    $ability     = (string) ($step['ability'] ?? '');
    $required    = $definitions[$ability]['input_schema']['required'] ?? [];
    $input       = (array) ($step['input'] ?? []);

    $missing = [];
    foreach ((array) $required as $key) {
      $value = $input[$key] ?? null;
      if ($value === null || $value === '' || (is_array($value) && $value === [])) {
        $missing[] = (string) $key;
      }
    }
    return $missing;
  }

  /**
   * Resolve whether a plan step must pause for user confirmation, based on the
   * ability's `requires_confirm` policy and live state.
   */
  private function stepNeedsConfirm(array $step): bool {
    $ability     = (string) ($step['ability'] ?? '');
    $definitions = $this->registry->definitions();
    $policy      = $definitions[$ability]['requires_confirm'] ?? false;

    return match ($policy) {
      'always'             => true,
      'when_live'          => $this->webhookIsLive((int) (($step['input']['id'] ?? 0))),
      'when_unsafe_method' => !in_array(strtoupper((string) ($step['input']['method'] ?? 'GET')), ['GET', 'HEAD'], true),
      default              => false,
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
   * The id of a webhook created earlier in this run — the one a probe/test step is
   * meant to validate. Prefers the most recent create_webhook before the cursor,
   * falling back to any created webhook in the plan.
   *
   * @param array<int, array<string, mixed>> $steps
   */
  private function createdWebhookId(array $steps, int $cursor): int {
    $latestBefore = 0;
    $fallback     = 0;
    foreach ($steps as $i => $s) {
      if ((string) ($s['ability'] ?? '') !== 'create_webhook') {
        continue;
      }
      $id = (int) ($s['result']['webhook']['id'] ?? 0);
      if ($id <= 0) {
        continue;
      }
      $fallback = $id;
      if ($i < $cursor) {
        $latestBefore = $id;
      }
    }
    return $latestBefore > 0 ? $latestBefore : $fallback;
  }

  private function persistRecipe(int $conversationId, array $applied, array $remainingPlan): void {
    $this->conversations->update($conversationId, [
      'last_recipe' => $applied,
      'plan'        => $remainingPlan,
    ]);
  }

  /**
   * Build the activity-log context for an executed ability. Write abilities store
   * the full result under `new` (the change made). Read/list abilities (e.g.
   * list_triggers, which returns the whole hook catalog) store only a compact
   * summary — recording that the agent ran it without bloating the log DB.
   *
   * @param mixed $result
   * @return array<string, mixed>
   */
  private function abilityLogContext(string $ability, $result): array {
    $definitions = $this->registry->definitions();
    $isRead      = (($definitions[$ability]['scope'] ?? '') === 'read');

    if ($isRead) {
      return ['meta' => ['result_summary' => $this->summarizeResult($result)]];
    }
    return ['new' => $result];
  }

  /**
   * Compact a (possibly large) ability result into counts/scalars for logging.
   *
   * @param mixed $result
   * @return array<string, mixed>
   */
  private function summarizeResult($result): array {
    if (!is_array($result)) {
      return ['type' => gettype($result)];
    }
    $summary = [];
    foreach ($result as $key => $value) {
      if (is_array($value)) {
        $summary[(string) $key] = ['count' => count($value)];
      } elseif (is_scalar($value) || $value === null) {
        $summary[(string) $key] = $value;
      } else {
        $summary[(string) $key] = gettype($value);
      }
    }
    return $summary;
  }

}
