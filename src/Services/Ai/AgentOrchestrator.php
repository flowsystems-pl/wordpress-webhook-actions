<?php

namespace FlowSystems\WebhookActions\Services\Ai;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Abilities\AbilityRegistry;
use FlowSystems\WebhookActions\Repositories\AgentConversationRepository;
use FlowSystems\WebhookActions\Repositories\WebhookRepository;
use FlowSystems\WebhookActions\Services\ActivityLogService;
use WP_Error;

/**
 * The plan-first agent loop ("Lovable for WordPress integrations").
 *
 * Flow:
 *   1. converse() — append the user's message, ask the model for a response.
 *      The model replies with a strict JSON envelope: an assistant message,
 *      optional clarifying questions, and an optional ordered PLAN of typed
 *      ability steps. Nothing is applied yet. The plan is editable in the UI.
 *   2. execute() — run an (optionally user-edited) plan. Low-risk steps apply
 *      silently; steps that require confirmation pause until the user confirms.
 *      Applied steps are recorded as a recipe for one-click undo.
 *
 * The toolset is the AbilityRegistry. The model never calls tools natively
 * (the WP AI Client has no tool-calling) — it proposes them as plan steps and
 * the plugin executes them locally, keeping the site in control.
 */
class AgentOrchestrator {
  private AbilityRegistry             $registry;
  private AgentConversationRepository $conversations;
  private ActivityLogService          $activity;
  private AgentTraceLog               $trace;

  /** Whether the last parseEnvelope() call decoded a real JSON envelope. */
  private bool $lastParseSucceeded = false;

  public function __construct() {
    $this->registry      = new AbilityRegistry();
    $this->conversations = new AgentConversationRepository();
    $this->activity      = new ActivityLogService();
    $this->trace         = new AgentTraceLog();
  }

  /**
   * Add a user message to a conversation and get the agent's response (assistant
   * message + optional editable plan). Persists transcript + plan.
   *
   * @return array<string, mixed>|WP_Error
   */
  public function converse(int $conversationId, string $userMessage): array|WP_Error {
    $transport = (new LlmTransport())->resolve();
    if ($transport === null) {
      return new WP_Error('fswa_ai_unconfigured', __('No AI provider is configured yet. Set one up to use the AI Builder.', 'flowsystems-webhook-actions'), ['status' => 409]);
    }

    $conversation = $this->conversations->find($conversationId);
    if (!$conversation) {
      return new WP_Error('fswa_conversation_not_found', __('Conversation not found.', 'flowsystems-webhook-actions'), ['status' => 404]);
    }

    $transcript   = is_array($conversation['transcript_json'] ?? null) ? $conversation['transcript_json'] : [];
    $transcript[] = ['role' => 'user', 'content' => $userMessage];

    $system   = $this->systemPrompt();
    $options  = ['temperature' => 0.2];
    $sent     = $transcript; // Exactly what we hand to the model (before its reply).
    $started  = microtime(true);
    $raw      = $transport->generateText($system, $transcript, $options);
    $latency  = (int) round((microtime(true) - $started) * 1000);

    if (is_wp_error($raw)) {
      $this->trace->record($this->traceBase($conversationId, $transport, $system, $sent, $options, $latency) + [
        'error'      => $raw->get_error_message(),
        'error_code' => $raw->get_error_code(),
      ]);
      return $raw;
    }

    $envelope = $this->parseEnvelope($raw);

    // Keep the human-readable reply — WITH any clarifying questions folded in — in
    // the transcript. This is what the UI shows and what we replay to the model on
    // the next turn, so the agent remembers what it already asked (no re-asking loop).
    $assistantText = (string) ($envelope['assistant_message'] ?? '');
    $clarifying    = array_values((array) ($envelope['clarifying_questions'] ?? []));
    $transcript[]  = ['role' => 'assistant', 'content' => $this->foldReply($assistantText, $clarifying)];

    $plan = $this->normalizePlan($envelope['plan'] ?? []);

    $this->trace->record($this->traceBase($conversationId, $transport, $system, $sent, $options, $latency) + [
      'response_raw'     => $raw,
      'parsed_ok'        => $this->lastParseSucceeded,
      'plan_steps'       => count($plan),
      'clarifying_count' => count($clarifying),
    ]);

    // A fresh plan seeds a runnable execution state machine (cursor + per-step
    // status). A clarifying-only reply (no plan) leaves any prior run untouched.
    $execution = $plan !== [] ? $this->seedExecution($plan) : null;

    $update = [
      'transport'  => $transport->id(),
      'model'      => $transport->model(),
      'transcript' => $transcript,
      'plan'       => $plan,
      'title'      => $conversation['title'] !== '' ? $conversation['title'] : $this->deriveTitle($userMessage),
    ];
    if ($execution !== null) {
      $update['execution'] = $execution;
    }
    $this->conversations->update($conversationId, $update);

    return [
      'conversation_id'      => $conversationId,
      'assistant_message'    => $assistantText,
      'clarifying_questions' => $clarifying,
      'plan'                 => $plan,
      'execution'            => $execution,
      'transport'            => $transport->id(),
      'model'                => $transport->model(),
    ];
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

      $this->activity->log('agent.' . $ability, $this->objectTypeFor($ability), $this->resultObjectId($result), $step['summary'] ?? null, [
        'new'     => $result,
        '_reason' => $step['summary'] ?? '',
      ]);

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

    // 3) Run the ability.
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

    // 3c) Success → record result, expose its id to downstream steps, advance.
    $step['status'] = 'done';
    $step['result'] = $result;
    $steps[$cursor] = $step;

    $objectId = $this->resultObjectId((array) $result);
    if ($objectId !== null) {
      $refs[(string) $step['id']] = $objectId;
    }

    $this->activity->log('agent.' . $step['ability'], $this->objectTypeFor((string) $step['ability']), $objectId, $step['summary'] ?? null, [
      'new'     => $result,
      '_reason' => $step['summary'] ?? '',
    ]);

    $execution['steps']  = $steps;
    $execution['refs']   = $refs;
    $execution['cursor'] = $cursor + 1;
    $finished            = ($cursor + 1) >= count($steps);
    return $this->persistExecution($conversationId, $execution, $step, !$finished, $finished);
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

  // ===================================================================
  // Internals
  // ===================================================================

  /**
   * Build a fresh execution state machine from a normalized plan.
   *
   * @param array<int, array<string, mixed>> $plan
   * @return array<string, mixed>
   */
  private function seedExecution(array $plan, ?string $mode = null): array {
    $steps = [];
    foreach ($plan as $step) {
      $steps[] = [
        'id'               => (string) ($step['id'] ?? ''),
        'ability'          => (string) ($step['ability'] ?? ''),
        'summary'          => (string) ($step['summary'] ?? ''),
        'input'            => (array) ($step['input'] ?? []),
        'requires_confirm' => (bool) ($step['requires_confirm'] ?? false),
        'status'           => 'pending',
      ];
    }
    return [
      'mode'   => $mode ?: $this->execMode(),
      'cursor' => 0,
      'refs'   => (object) [],
      'steps'  => $steps,
    ];
  }

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
   * Normalize a plan into a stable, validated shape with per-step ids and
   * resolved confirmation flags (so the UI can render confirm controls).
   *
   * @param mixed $plan
   * @return array<int, array<string, mixed>>
   */
  private function normalizePlan($plan): array {
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

  private function persistRecipe(int $conversationId, array $applied, array $remainingPlan): void {
    $this->conversations->update($conversationId, [
      'last_recipe' => $applied,
      'plan'        => $remainingPlan,
    ]);
  }

  /**
   * Map an ability name to an activity-log object type.
   */
  private function objectTypeFor(string $ability): string {
    return match (true) {
      str_contains($ability, 'chain')      => 'chain',
      str_contains($ability, 'credential') => 'credential',
      default                              => 'webhook',
    };
  }

  /**
   * Best-effort extraction of the affected object id from an ability result.
   */
  private function resultObjectId(array $result): ?int {
    foreach (['webhook', 'chain', 'link'] as $key) {
      if (isset($result[$key]['id'])) {
        return (int) $result[$key]['id'];
      }
    }
    foreach (['id', 'webhook_id', 'log_id', 'schema_id'] as $key) {
      if (isset($result[$key])) {
        return (int) $result[$key];
      }
    }
    return null;
  }

  /**
   * Extract a JSON envelope from the model's raw text, tolerating code fences
   * and surrounding prose.
   *
   * @return array<string, mixed>
   */
  private function parseEnvelope(string $raw): array {
    $text = trim($raw);

    // Strip ```json … ``` fences if present.
    if (preg_match('/```(?:json)?\s*(.+?)```/s', $text, $m)) {
      $text = trim($m[1]);
    }

    // Fall back to the outermost { … } span.
    $start = strpos($text, '{');
    $end   = strrpos($text, '}');
    if ($start !== false && $end !== false && $end > $start) {
      $text = substr($text, $start, $end - $start + 1);
    }

    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
      $this->lastParseSucceeded = true;
      return $decoded;
    }

    // The model didn't return valid JSON — treat the whole thing as a plain reply.
    $this->lastParseSucceeded = false;
    return ['assistant_message' => trim($raw), 'plan' => []];
  }

  /**
   * Common fields for a trace entry: which model answered, with what input.
   *
   * @param array<int, array{role:string,content:string}> $sent
   * @param array<string, mixed>                          $options
   * @return array<string, mixed>
   */
  private function traceBase(int $conversationId, LlmTransportInterface $transport, string $system, array $sent, array $options, int $latencyMs): array {
    return [
      'conversation_id' => $conversationId,
      'provider'        => $transport->id(),
      'model'           => $transport->model(),
      'latency_ms'      => $latencyMs,
      'temperature'     => $options['temperature'] ?? null,
      'system'          => $system,
      'messages'        => array_values($sent),
    ];
  }

  /**
   * Combine the assistant message with any clarifying questions into one readable
   * block, so the transcript (shown in the UI and replayed to the model next turn)
   * preserves exactly what was asked.
   *
   * @param array<int, string> $questions
   */
  private function foldReply(string $message, array $questions): string {
    if ($questions === []) {
      return $message;
    }
    $lines = $message !== '' ? [$message] : [];
    foreach ($questions as $question) {
      $lines[] = '• ' . (string) $question;
    }
    return implode("\n", $lines);
  }

  private function deriveTitle(string $message): string {
    $title = trim(wp_strip_all_tags($message));
    return mb_substr($title, 0, 60);
  }

  /**
   * The system instruction: role, the plan-first/JSON contract, and the catalog
   * of abilities the model may propose as plan steps.
   */
  private function systemPrompt(): string {
    $abilities = [];
    foreach ($this->registry->definitions() as $name => $def) {
      $required    = $def['input_schema']['required'] ?? [];
      $abilities[] = sprintf('- %s: %s%s', $name, $def['description'] ?? '', $required ? ' (required: ' . implode(', ', $required) . ')' : '');
    }
    $catalog = implode("\n", $abilities);

    return <<<PROMPT
You are the Webhook Actions AI Builder — an expert at building WordPress webhook
integrations and automations ("Lovable for integrations"). You help the user wire
WordPress do_action events to external APIs (n8n, HubSpot, Slack, CRMs, anything HTTP).

You work PLAN-FIRST. You never claim to have changed anything yourself — instead you
PROPOSE an ordered plan of typed steps that the plugin will execute locally after the
user reviews (and may edit) it. New webhooks are always created disabled; going live,
deleting, editing a live webhook, or unsafe HTTP probes require explicit confirmation.

Use the captured payload (get_trigger_schema) and probe_endpoint to work from real data,
not guesses. Keep plans minimal and correct.

Prefer ACTION over interrogation. When the user's goal is clear, propose the FULL plan on
your first reply — choose sensible defaults and state them in assistant_message (e.g. all
matching forms, no auth, JSON body, POST). For a required value you genuinely don't have
yet — typically a destination URL or a credential — still include the step, leave that
field blank (e.g. "endpoint_url": ""), and ask for ONLY those missing values in
clarifying_questions. The user fills blanks directly in the plan, so never withhold a plan
just to ask a question you could pair with it. Do not ask about anything you can reasonably
default.

When a step needs a value produced by an earlier step (e.g. the id of a webhook you create in
step_2), reference it as {{step_2.id}}. The plugin substitutes the real id at run time.

You MUST reply with a single JSON object and nothing else, matching:
{
  "assistant_message": "short, friendly explanation of what you'll do or what you need",
  "clarifying_questions": ["..."],            // optional; ask only when truly blocked
  "plan": [                                    // optional; omit or [] when just talking
    {
      "id": "step_1",
      "ability": "<one of the ability names below>",
      "summary": "human-readable description of this step",
      "input": { /* arguments matching that ability's schema */ }
    }
  ]
}

Available abilities (propose these as plan steps; do not invent others):
{$catalog}
PROMPT;
  }
}
