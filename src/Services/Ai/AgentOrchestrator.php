<?php

namespace FlowSystems\WebhookActions\Services\Ai;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Abilities\AbilityRegistry;
use FlowSystems\WebhookActions\Repositories\AgentConversationRepository;
use FlowSystems\WebhookActions\Services\ActivityLogService;
use WP_Error;

/**
 * The plan-first agent loop ("Lovable for WordPress integrations").
 *
 * Public entry point for the AI Builder. It owns the conversation turn —
 * converse() asks the model for a strict JSON envelope (assistant message,
 * optional clarifying questions, optional ordered PLAN of typed ability steps) —
 * and delegates the rest:
 *   - {@see SystemPromptBuilder} builds the instruction the model is given.
 *   - {@see PlanExecutor} runs plans step-by-step (advanceStep), and handles the
 *     revert/undo stack and legacy all-at-once execute().
 *
 * The toolset is the AbilityRegistry. The model never calls tools natively (the
 * WP AI Client has no tool-calling) — it proposes them as plan steps and the
 * plugin executes them locally, keeping the site in control.
 */
class AgentOrchestrator {
  private AbilityRegistry             $registry;
  private AgentConversationRepository $conversations;
  private AgentTraceLog               $trace;
  private SystemPromptBuilder         $prompts;
  private PlanExecutor                $executor;

  /** Whether the last parseEnvelope() call decoded a real JSON envelope. */
  private bool $lastParseSucceeded = false;

  public function __construct() {
    $this->registry      = new AbilityRegistry();
    $this->conversations = new AgentConversationRepository();
    $activity            = new ActivityLogService();
    $this->trace         = new AgentTraceLog();
    $this->prompts       = new SystemPromptBuilder($this->registry);
    $this->executor      = new PlanExecutor($this->conversations, $this->registry, $activity);
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

    $system   = $this->prompts->build($conversation);
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

    $plan = $this->executor->normalizePlan($envelope['plan'] ?? []);

    $this->trace->record($this->traceBase($conversationId, $transport, $system, $sent, $options, $latency) + [
      'response_raw'     => $raw,
      'parsed_ok'        => $this->lastParseSucceeded,
      'plan_steps'       => count($plan),
      'clarifying_count' => count($clarifying),
    ]);

    // A fresh plan seeds a runnable execution state machine (cursor + per-step
    // status). A clarifying-only reply (no plan) leaves any prior run untouched.
    $execution = $plan !== [] ? $this->executor->seedExecution($plan) : null;

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

  // ===================================================================
  // Plan execution + revert — delegated to PlanExecutor.
  // ===================================================================

  /**
   * @param array<int, array<string, mixed>>|null $planOverride
   * @param array<int, string>                    $confirmed
   * @return array<string, mixed>|WP_Error
   */
  public function execute(int $conversationId, ?array $planOverride = null, array $confirmed = []): array|WP_Error {
    return $this->executor->execute($conversationId, $planOverride, $confirmed);
  }

  /**
   * @param array{patch?:array<string,mixed>, confirm?:bool, skip?:bool} $opts
   * @return array<string, mixed>|WP_Error
   */
  public function advanceStep(int $conversationId, array $opts = []): array|WP_Error {
    return $this->executor->advanceStep($conversationId, $opts);
  }

  /**
   * @return array<string, mixed>|WP_Error
   */
  public function revertLast(int $conversationId): array|WP_Error {
    return $this->executor->revertLast($conversationId);
  }

  /**
   * @return array<string, mixed>|WP_Error
   */
  public function undoLast(int $conversationId): array|WP_Error {
    return $this->executor->undoLast($conversationId);
  }

  public function execMode(): string {
    return $this->executor->execMode();
  }

  public function saveExecMode(string $mode): string {
    return $this->executor->saveExecMode($mode);
  }

  // ===================================================================
  // Conversation-turn internals.
  // ===================================================================

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
      'request'         => $transport->lastRequest(),
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
}
