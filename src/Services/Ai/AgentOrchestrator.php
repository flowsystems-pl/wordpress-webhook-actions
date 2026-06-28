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

  public function __construct() {
    $this->registry      = new AbilityRegistry();
    $this->conversations = new AgentConversationRepository();
    $this->activity      = new ActivityLogService();
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

    $raw = $transport->generateText($this->systemPrompt(), $transcript, ['temperature' => 0.2]);
    if (is_wp_error($raw)) {
      return $raw;
    }

    $envelope = $this->parseEnvelope($raw);

    // Keep the human-readable reply in the transcript; the plan is stored separately.
    $assistantText = (string) ($envelope['assistant_message'] ?? '');
    $transcript[]  = ['role' => 'assistant', 'content' => $assistantText];

    $plan = $this->normalizePlan($envelope['plan'] ?? []);

    $this->conversations->update($conversationId, [
      'transport'  => $transport->id(),
      'model'      => $transport->model(),
      'transcript' => $transcript,
      'plan'       => $plan,
      'title'      => $conversation['title'] !== '' ? $conversation['title'] : $this->deriveTitle($userMessage),
    ]);

    return [
      'conversation_id'      => $conversationId,
      'assistant_message'    => $assistantText,
      'clarifying_questions' => array_values((array) ($envelope['clarifying_questions'] ?? [])),
      'plan'                 => $plan,
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
      return $decoded;
    }

    // The model didn't return valid JSON — treat the whole thing as a plain reply.
    return ['assistant_message' => trim($raw), 'plan' => []];
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
