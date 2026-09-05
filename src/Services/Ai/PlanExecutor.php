<?php

namespace FlowSystems\WebhookActions\Services\Ai;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Abilities\AbilityRegistry;
use FlowSystems\WebhookActions\Repositories\AgentConversationRepository;
use FlowSystems\WebhookActions\Repositories\WebhookRepository;
use FlowSystems\WebhookActions\Services\ActivityLogService;
use FlowSystems\WebhookActions\Services\ExampleResolver;
use WP_Error;

/**
 * Runs an agent plan against the AbilityRegistry: the step-by-step execution
 * state machine (advanceStep), plan seeding/normalization, the revert/undo
 * stack, and the small helpers that gate and resolve each step.
 *
 * The AgentOrchestrator owns the conversation turn and delegates all plan
 * execution and reverting here. Cohesive sub-concerns live in collaborators:
 * {@see BuildLedger} (idempotent reuse of already-built objects),
 * {@see StepReverter} (per-step undo mechanics), {@see ProbeInterpreter} and
 * {@see DispatchInterpreter} (which non-2xx outcomes should stop a run), and
 * {@see StepFeedback} (handing what actually happened back to the model).
 */
class PlanExecutor {
  private AgentConversationRepository $conversations;
  private AbilityRegistry             $registry;
  private ActivityLogService          $activity;
  private BuildLedger                 $ledger;
  private StepReverter                $reverter;

  /** @var array<int, string> Ability names normalizePlan() last dropped as unknown/unavailable. */
  private array $lastDroppedAbilities = [];

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
    $outcomes  = [];

    foreach ($plan as $step) {
      $stepId  = (string) $step['id'];
      $ability = (string) $step['ability'];
      $input   = (array) ($step['input'] ?? []);

      if ($this->stepNeedsConfirm($step, $input) && !in_array($stepId, $confirmed, true)) {
        $this->persistRecipe($conversationId, $applied, $plan, $outcomes);
        return [
          'status'         => 'needs_confirm',
          'pending_step'   => $step,
          'applied'        => $applied,
          'results'        => $results,
        ];
      }

      $result = $this->registry->execute($ability, $input);

      if (is_wp_error($result)) {
        $outcomes[] = StepFeedback::outcome($step, null, false, $result->get_error_message());
        $this->persistRecipe($conversationId, $applied, $plan, $outcomes);
        return [
          'status'      => 'error',
          'failed_step' => $step,
          'error'       => $result->get_error_message(),
          'applied'     => $applied,
          'results'     => $results,
        ];
      }

      // A test delivery that the endpoint refused is a failed build, not a
      // completed step — stop before the plan's enable_webhook takes it live.
      $dispatch = $ability === 'test_dispatch' ? DispatchInterpreter::interpret($result) : null;
      if ($dispatch !== null) {
        $outcomes[] = StepFeedback::outcome($step, $result, false, $dispatch['message']);
        $this->persistRecipe($conversationId, $applied, $plan, $outcomes);
        return [
          'status'      => 'error',
          'failed_step' => $step + ['dispatch' => $dispatch],
          'error'       => $dispatch['message'],
          'applied'     => $applied,
          'results'     => $results,
        ];
      }

      $outcomes[] = StepFeedback::outcome($step, $result, true);

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

    $this->persistRecipe($conversationId, $applied, [], $outcomes);

    return ['status' => 'completed', 'applied' => $applied, 'results' => $results];
  }

  /**
   * The current execution mode: 'auto' (the agent runs the plan step by step) or
   * 'review' (the user reviews/edits the plan before running). Stored globally.
   */
  /**
   * Ability names the most recent normalizePlan() call dropped because they are
   * not in the catalog (hallucinated, or Pro abilities on a site where Pro
   * isn't running). Lets the orchestrator warn instead of shipping a silently
   * thinned plan.
   *
   * @return array<int, string>
   */
  public function lastDroppedAbilities(): array {
    return $this->lastDroppedAbilities;
  }

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

    // 0c) Inline dispatch fix: a test_dispatch that came back 401/403 (see
    // DispatchInterpreter) is fixed the same way a probe's auth failure is —
    // attach the chosen/created credential to the webhook, then let the normal
    // flow below re-run the dispatch with it. test_dispatch always carries a
    // real webhook_id (it's a required field), so there is no "unbound" branch
    // to fall back to the way probe_fix needs one.
    if (!empty($opts['dispatch_fix']) && is_array($opts['dispatch_fix']) && (string) $step['ability'] === 'test_dispatch') {
      $webhookId = (int) ($input['webhook_id'] ?? 0);
      $fix       = $opts['dispatch_fix'];
      if ($webhookId > 0 && !empty($fix['auth_credential_id'])) {
        $this->registry->execute('assign_credential', ['webhook_id' => $webhookId, 'credential_id' => (int) $fix['auth_credential_id']]);
      }
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

    // 1a) Mapping/conditions against a trigger that has NEVER captured a payload.
    // Both take dot-paths INTO the captured shape, so with no capture the agent
    // can only have guessed them ("args.0", "args.2.post_type"). A guessed
    // mapping silently drops fields and a guessed condition silently passes
    // everything (a missing path is null, which negative operators accept), so
    // the build looks applied and is quietly broken. The prompt already forbids
    // this; weaker models still do it once their read budget runs out, so gate it
    // here where it cannot be talked around. Same pause the UI already offers for
    // a missing capture: fire the event, then retry.
    $prereq = $this->missingCapture($step, $input);
    if ($prereq !== null) {
      $step['status']     = 'blocked_prereq';
      $step['prereq']     = $prereq;
      $steps[$cursor]     = $step;
      $execution['steps'] = $steps;
      return $this->persistExecution($conversationId, $execution, $step, false, false);
    }

    // 2) Confirmation gate (go-live / delete / edit-live / unsafe probe /
    //    real test delivery). Surface the ability's side-effect notice if any.
    if ($this->stepNeedsConfirm($step, $input) && empty($opts['confirm'])) {
      $definitions = $this->registry->definitions();
      $notice      = (string) ($definitions[(string) $step['ability']]['confirm_notice'] ?? '');
      $step['status'] = 'needs_confirm';
      if ($notice !== '') {
        $step['confirm_notice'] = $notice;
      }
      $steps[$cursor]     = $step;
      $execution['steps'] = $steps;
      return $this->persistExecution($conversationId, $execution, $step, false, false);
    }

    // 2a) Past the confirm gate — so if this ability required confirmation, the
    // user has now given it. probe_endpoint carries its OWN `confirmed` guard for
    // unsafe methods (it's also callable directly via REST/MCP with no plan gate),
    // so bridge the confirmation into the input; otherwise a confirmed
    // POST/PUT/PATCH/DELETE probe rejects itself with "requires confirmation".
    // Harmless for other abilities, which don't read `confirmed`.
    if ($this->stepNeedsConfirm($step, $input)) {
      $input['confirmed'] = true;
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
      StepFeedback::record($execution, $step, null, false, $result->get_error_message());
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
        StepFeedback::record($execution, $step, $result, false, $probe['message']);
        return $this->persistExecution($conversationId, $execution, $step, false, false);
      }
    }
    unset($step['probe']);

    // 3b-3) test_dispatch sends the REAL mapped-and-glued payload, so a non-2xx
    // response means the build does not work — the endpoint refused exactly what
    // a live delivery would send. It returns a plain array (never a WP_Error),
    // so without this gate the run marks it done and walks straight into the
    // plan's enable_webhook, taking a webhook live on the back of a failure.
    if ((string) $step['ability'] === 'test_dispatch' && is_array($result)) {
      $dispatch = DispatchInterpreter::interpret($result);
      if ($dispatch !== null) {
        // Count identical rejections across the whole run, not just this step:
        // each retry re-proposes test_dispatch under a fresh step id, so a
        // per-step counter would always read 1.
        $dispatch = DispatchInterpreter::escalate($dispatch, $this->countDispatchFailure($execution, $dispatch));

        $step['status']     = 'blocked_dispatch';
        $step['dispatch']   = $dispatch;
        $step['result']     = $result;
        $steps[$cursor]     = $step;
        $execution['steps'] = $steps;
        StepFeedback::record($execution, $step, $result, false, $dispatch['message']);
        return $this->persistExecution($conversationId, $execution, $step, false, false);
      }
    }
    unset($step['dispatch']);

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
    StepFeedback::record($execution, $step, is_array($result) ? $result : null, true);
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

    $seeded = [
      'mode'    => $mode ?: $this->execMode(),
      'cursor'  => 0,
      'refs'    => $refs === [] ? (object) [] : $refs,
      'steps'   => $steps,
      'ledger'  => $ledger,
      // The run being replaced becomes history. Without this a re-plan erases
      // every step the earlier plans applied, and everything downstream — the
      // published build's "Abilities used", the resolver that decides which
      // webhooks a share contains — sees only the last plan's work.
      'applied' => AppliedSteps::carryForward($prior),
    ];

    // Rejection tally survives re-planning, like the ledger. Every fix arrives
    // as a NEW plan with a new execution, so a counter that reset here would
    // read 1 forever and the "you have tried this already" escalation could
    // never fire — which is precisely the loop it exists to break.
    $failures = $prior['dispatch_failures'] ?? null;
    if (is_array($failures) && $failures !== []) {
      $seeded['dispatch_failures'] = $failures;
    }

    return $seeded;
  }

  /**
   * Normalize a plan into a stable, validated shape with per-step ids and
   * resolved confirmation flags (so the UI can render confirm controls).
   *
   * @param mixed $plan
   * @return array<int, array<string, mixed>>
   */
  public function normalizePlan($plan): array {
    $this->lastDroppedAbilities = [];
    if (!is_array($plan)) {
      return [];
    }

    $definitions = $this->registry->definitions();
    $normalized  = [];
    $i           = 0;

    foreach ($plan as $step) {
      if (!is_array($step) || empty($step['ability']) || !isset($definitions[$step['ability']])) {
        // Remember named-but-unavailable abilities so the orchestrator can warn
        // the user — a silently thinned plan looks complete but isn't (e.g. Pro
        // snippet steps vanishing while the prompt still advertised Code Glue).
        if (is_array($step) && !empty($step['ability'])) {
          $this->lastDroppedAbilities[] = (string) $step['ability'];
        }
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

    return $this->withCaptureStep($normalized);
  }

  /**
   * Append a "capture the payload" step when the plan builds a webhook on a
   * trigger that has never captured one.
   *
   * The agent handles this correctly now — it stops and asks in prose — but
   * prose scrolls away and the run still finishes green, so the build silently
   * ends half-done. Making it the plan's last STEP puts the ask where the user
   * is already looking: get_trigger_schema on an uncaptured trigger returns null,
   * which the run turns into the amber `blocked_prereq` pause that already says
   * "submit a test entry, then retry" and auto-resumes when they come back.
   *
   * @param array<int, array<string, mixed>> $plan
   * @return array<int, array<string, mixed>>
   */
  private function withCaptureStep(array $plan): array {
    $resolver = new ExampleResolver();

    // Trigger => the webhook reference to read it against. Two sources, because
    // a build does not have to create the webhook: the agent may be finishing
    // one that already exists (assign_credential on #206 and nothing else), and
    // scanning only create_webhook missed that entirely.
    $candidates    = [];
    $existingIds   = [];

    foreach ($plan as $step) {
      $ability = (string) ($step['ability'] ?? '');

      // Something downstream already depends on the capture (and is gated on it),
      // or the agent asked for the schema itself — no synthetic step needed.
      if (in_array($ability, ['set_mapping', 'set_conditions', 'get_trigger_schema'], true)) {
        return $plan;
      }

      // 1) A webhook this plan creates: its triggers are in the step's own input,
      //    and downstream steps address it by {{step_N.id}}.
      if ($ability === 'create_webhook' || $ability === 'update_webhook') {
        foreach ((array) ($step['input']['triggers'] ?? []) as $trigger) {
          $trigger = (string) $trigger;
          if ($trigger !== '') {
            $candidates[$trigger] ??= '{{' . (string) ($step['id'] ?? '') . '.id}}';
          }
        }
      }

      // 2) A webhook that already exists: the plan names it by numeric id, so its
      //    triggers have to be read off the record.
      $webhookId = (int) ($step['input']['webhook_id'] ?? 0);
      if ($webhookId <= 0 && in_array($ability, ['update_webhook', 'enable_webhook', 'delete_webhook'], true)) {
        $webhookId = (int) ($step['input']['id'] ?? 0);
      }
      if ($webhookId > 0) {
        $existingIds[$webhookId] = $webhookId;
      }
    }

    if ($existingIds !== []) {
      $webhooks = new WebhookRepository();
      foreach ($existingIds as $webhookId) {
        $webhook = $webhooks->find($webhookId);
        foreach ((array) ($webhook['triggers'] ?? []) as $trigger) {
          $trigger = (string) $trigger;
          if ($trigger !== '') {
            $candidates[$trigger] ??= $webhookId;
          }
        }
      }
    }

    $pending = null;
    foreach ($candidates as $trigger => $webhookRef) {
      // Chain links fire from another webhook's response, never from a
      // do_action the user can go and trigger by hand.
      if (str_starts_with((string) $trigger, 'fswa_chain_link:')) {
        continue;
      }
      if ($resolver->resolve(is_int($webhookRef) ? $webhookRef : 0, (string) $trigger)['example'] === null) {
        $pending = ['trigger' => (string) $trigger, 'webhook_ref' => $webhookRef];
        break;
      }
    }

    if ($pending === null) {
      return $plan;
    }

    $capture = [
      'id'               => 'step_capture_payload',
      'ability'          => 'get_trigger_schema',
      'summary'          => sprintf(
        /* translators: %s: trigger (do_action hook) name. */
        __('Capture an example payload for "%s" — fire the event once (e.g. publish a test post)', 'flowsystems-webhook-actions'),
        $pending['trigger']
      ),
      'input'            => [
        'trigger'    => $pending['trigger'],
        'webhook_id' => $pending['webhook_ref'],
      ],
      'requires_confirm' => false,
    ];

    // Slot it in front of going live: a webhook with no captured payload has
    // nothing mapped, so enabling first would put an unshaped delivery on the
    // wire. Otherwise it lands at the end.
    foreach ($plan as $index => $step) {
      if ((string) ($step['ability'] ?? '') === 'enable_webhook') {
        array_splice($plan, $index, 0, [$capture]);
        return $plan;
      }
    }

    $plan[] = $capture;
    return $plan;
  }

  // ===================================================================
  // Internals
  // ===================================================================

  /**
   * Record this rejection against the run and return how many times the
   * endpoint has now refused in exactly this way (1 on the first).
   *
   * Mutates $execution, so the caller must persist it afterwards — which the
   * blocked_dispatch branch does on its way out.
   *
   * @param array<string, mixed>                                            $execution
   * @param array{kind:string, status:int, message:string, response:string} $dispatch
   */
  private function countDispatchFailure(array &$execution, array $dispatch): int {
    $signature = DispatchInterpreter::signature($dispatch);
    $tally     = is_array($execution['dispatch_failures'] ?? null) ? $execution['dispatch_failures'] : [];
    $count     = (int) ($tally[$signature] ?? 0) + 1;

    $tally[$signature]                = $count;
    $execution['dispatch_failures']   = $tally;

    return $count;
  }

  /**
   * Persist execution state and shape the step response for the frontend loop.
   *
   * @param array<string, mixed>      $execution
   * @param array<string, mixed>|null $acted     The step processed this call.
   * @return array<string, mixed>
   */
  private function persistExecution(int $conversationId, array $execution, ?array $acted, bool $continue, bool $finished): array {
    // The run has stopped moving (paused for the user, or finished) — hand every
    // outcome it collected to the model as ONE entry. Mid-run we keep
    // accumulating, so a long plan can't crowd out the read results it sits
    // beside in the replay window.
    $entry      = $continue ? null : StepFeedback::flush($execution);
    $transcript = $entry === null ? null : $this->appendTranscript($conversationId, $entry);

    $update = ['execution' => $execution];
    if ($transcript !== null) {
      $update['transcript'] = $transcript;
    }
    $this->conversations->update($conversationId, $update);

    $out = [
      'execution' => $execution,
      'acted'     => $acted,
      'continue'  => $continue,
      'finished'  => $finished,
    ];
    if ($transcript !== null) {
      $out['transcript'] = $transcript;
    }
    if ($finished) {
      $followUp = $this->continuationPrompt($execution);
      if ($followUp !== null) {
        $out['continuation'] = $followUp;
      }
    }
    return $out;
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
   * The message to send the agent when a finished run has unblocked work it
   * could not plan before, or null when the run is genuinely done.
   *
   * Only the synthetic capture step qualifies. Its whole purpose is to wait for
   * a payload the agent needed in order to plan the mapping — so the moment it
   * succeeds the build is knowingly half-finished (a webhook with no mapping),
   * and leaving it there is what made the run look like it "died" after the
   * user published their test post and hit retry. Keying on the synthetic id
   * also makes this loop-proof: the follow-up plan carries set_mapping, so
   * withCaptureStep() never appends another capture step to it.
   *
   * @param array<string, mixed> $execution
   */
  private function continuationPrompt(array $execution): ?string {
    foreach ((array) ($execution['steps'] ?? []) as $step) {
      if ((string) ($step['id'] ?? '') !== 'step_capture_payload'
        || (string) ($step['status'] ?? '') !== 'done') {
        continue;
      }

      $trigger = (string) ($step['input']['trigger'] ?? '');
      return sprintf(
        /* translators: %s: trigger (do_action hook) name. */
        __('The example payload for "%s" has now been captured, so the field paths are readable. Read it with get_trigger_schema and finish the build: set the field mapping from the REAL paths, add a pre-dispatch Code Glue snippet if the destination needs a shape mapping alone cannot produce, then test_dispatch before enabling.', 'flowsystems-webhook-actions'),
        $trigger
      );
    }

    return null;
  }

  /**
   * A `capture_payload` prereq when this step addresses a captured payload that
   * does not exist yet, or null when the step is fine to run.
   *
   * Only set_mapping and set_conditions take dot-paths into the captured shape.
   * set_conditions is exempt when it evaluates on "transformed": those paths are
   * the mapping's own target names plus snippet-injected keys, which the plan
   * defines itself and no capture can confirm.
   *
   * @param array<string, mixed> $step
   * @param array<string, mixed> $input Ref-resolved input for this step.
   * @return array{kind:string, webhook_id:int, trigger:string}|null
   */
  private function missingCapture(array $step, array $input): ?array {
    $ability = (string) ($step['ability'] ?? '');
    if (!in_array($ability, ['set_mapping', 'set_conditions'], true)) {
      return null;
    }
    if ($ability === 'set_conditions' && (string) ($input['conditions_evaluate_on'] ?? 'original') === 'transformed') {
      return null;
    }

    $trigger = (string) ($input['trigger'] ?? '');
    if ($trigger === '') {
      return null;
    }

    $webhookId = (int) ($input['webhook_id'] ?? 0);
    $resolved  = (new ExampleResolver())->resolve($webhookId, $trigger);

    if ($resolved['example'] === null) {
      return ['kind' => 'capture_payload', 'webhook_id' => $webhookId, 'trigger' => $trigger];
    }

    // The example exists, but when it came from the hosted library the paths
    // below a site-defined container (meta_data, ACF, a form's fields) are OUR
    // fixture's keys, not this site's. Mapping one silently ships a null — the
    // build looks applied and is quietly broken, which is the same failure this
    // method already refuses for guessed paths. The prompt forbids it too;
    // weaker models still do it once their read budget runs out, so gate it here
    // where it cannot be talked around.
    $offending = $this->siteDefinedSourcePaths($resolved, $input);

    if ($offending !== []) {
      return [
        'kind'       => 'site_defined_paths',
        'webhook_id' => $webhookId,
        'trigger'    => $trigger,
        'paths'      => $offending,
      ];
    }

    return null;
  }

  /**
   * Source paths in this step that reach into a container whose keys belong to
   * the customer's site rather than to the reference payload we served.
   *
   * @param array<string, mixed> $resolved An ExampleResolver::resolve() result.
   * @param array<string, mixed> $input
   * @return array<int, string>
   */
  private function siteDefinedSourcePaths(array $resolved, array $input): array {
    if (($resolved['source'] ?? null) !== 'library') {
      return [];
    }

    $paths = [];

    foreach ((array) ($input['mapping'] ?? []) as $row) {
      $source = is_array($row) ? (string) ($row['source'] ?? '') : '';
      if ($source !== '' && ExampleResolver::pathIsUnsafe($resolved, $source)) {
        $paths[$source] = true;
      }
    }

    foreach ($this->conditionFields((array) ($input['conditions'] ?? [])) as $field) {
      if (ExampleResolver::pathIsUnsafe($resolved, $field)) {
        $paths[$field] = true;
      }
    }

    return array_keys($paths);
  }

  /**
   * Every `field` in a conditions tree, however deeply the groups nest.
   *
   * @param array<string, mixed> $conditions
   * @return array<int, string>
   */
  private function conditionFields(array $conditions): array {
    $fields = [];

    array_walk_recursive(
      $conditions,
      static function ($value, $key) use (&$fields): void {
        if ($key === 'field' && is_string($value) && $value !== '') {
          $fields[] = $value;
        }
      }
    );

    return $fields;
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
   *
   * Pass the ref-RESOLVED input when available: the raw step input may still
   * hold a {{step_N.id}} placeholder, which a live-state check would misread
   * as webhook 0. Falls back to the step's own input (pre-run UI metadata).
   */
  private function stepNeedsConfirm(array $step, ?array $input = null): bool {
    return $this->registry->requiresConfirmation(
      (string) ($step['ability'] ?? ''),
      $input ?? (array) ($step['input'] ?? [])
    );
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

  /**
   * @param array<int, array<string, mixed>> $outcomes Executed-step outcomes to feed back to the model.
   */
  private function persistRecipe(int $conversationId, array $applied, array $remainingPlan, array $outcomes = []): void {
    $update = [
      'last_recipe' => $applied,
      'plan'        => $remainingPlan,
    ];

    $entry = StepFeedback::entry($outcomes);
    if ($entry !== null) {
      $update['transcript'] = $this->appendTranscript($conversationId, $entry);
    }

    $this->conversations->update($conversationId, $update);
  }

  /**
   * The conversation transcript with one entry appended. Read fresh: a run
   * spans several requests, so an in-memory copy from the top of the call is
   * already stale by the time a later step reports.
   *
   * @param array<string, mixed> $entry
   * @return array<int, array<string, mixed>>
   */
  private function appendTranscript(int $conversationId, array $entry): array {
    $conversation = $this->conversations->find($conversationId);
    $transcript   = is_array($conversation['transcript_json'] ?? null) ? $conversation['transcript_json'] : [];
    $transcript[] = $entry;
    return $transcript;
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
