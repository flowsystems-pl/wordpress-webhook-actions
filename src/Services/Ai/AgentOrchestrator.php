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
  /** Max model round-trips spent on "reads" before the reply must be final. */
  private const READ_ITERATIONS_MAX = 3;

  /** Byte budget for one read result handed back to the model. */
  private const READ_RESULT_BYTES = 8192;

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
    $options  = ['temperature' => 0.2, 'json' => true];
    $activity = [];

    // A turn can be several model round-trips: while the envelope asks for
    // "reads" (read-only abilities), execute them locally, feed the results
    // back, and ask again. Bounded, so the whole turn stays one HTTP request.
    if (function_exists('set_time_limit')) {
      @set_time_limit(180);
    }

    for ($iteration = 0; ; $iteration++) {
      $sent    = $this->modelMessages($transcript);
      $started = microtime(true);
      $raw     = $transport->generateText($system, $sent, $options);
      $latency = (int) round((microtime(true) - $started) * 1000);

      if (is_wp_error($raw)) {
        $this->trace->record($this->traceBase($conversationId, $transport, $system, $sent, $options, $latency) + [
          'iteration'  => $iteration,
          'error'      => $raw->get_error_message(),
          'error_code' => $raw->get_error_code(),
        ]);
        return $raw;
      }

      $envelope = $this->parseEnvelope($raw);
      $reads    = $this->lastParseSucceeded ? $this->normalizeReads($envelope['reads'] ?? []) : [];
      $plan     = $this->executor->normalizePlan($envelope['plan'] ?? []);

      $this->trace->record($this->traceBase($conversationId, $transport, $system, $sent, $options, $latency) + [
        'response_raw'     => $raw,
        'parsed_ok'        => $this->lastParseSucceeded,
        'iteration'        => $iteration,
        'reads'            => array_column($reads, 'ability'),
        'plan_steps'       => count($plan),
        'clarifying_count' => count((array) ($envelope['clarifying_questions'] ?? [])),
      ]);

      // Final reply: no reads requested, or the read budget is spent (then any
      // leftover reads are dropped and the envelope is treated as final).
      if ($reads === [] || $iteration >= self::READ_ITERATIONS_MAX) {
        break;
      }

      // Replay material: the envelope that asked for the reads, then the results.
      $transcript[] = $this->assistantEntry($envelope, (string) ($envelope['assistant_message'] ?? ''));
      $transcript[] = $this->executeReads($reads, $activity, $iteration >= self::READ_ITERATIONS_MAX - 1);
    }

    // Keep the human-readable reply — WITH any clarifying questions folded in — in
    // the transcript for the UI, and the raw envelope alongside it: the model gets
    // the envelope replayed next turn (a prose history teaches it to answer in
    // prose and breaks the JSON contract — see the 2026-07-06 trace).
    $assistantText = (string) ($envelope['assistant_message'] ?? '');
    $clarifying    = array_values((array) ($envelope['clarifying_questions'] ?? []));
    $transcript[]  = $this->assistantEntry($envelope, $this->foldReply($assistantText, $clarifying));

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
      'activity'             => $activity,
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
   * The message list actually handed to the model.
   *
   * The stored transcript is the UI's view (folded prose). The model instead
   * gets, for each assistant turn, the raw JSON envelope it produced — keeping
   * its own few-shot history in the required format — and each `tool` entry
   * (read results) rendered as a user-role block.
   *
   * @param array<int, array<string, mixed>> $transcript
   * @return array<int, array{role:string,content:string}>
   */
  private function modelMessages(array $transcript): array {
    $out = [];
    foreach ($transcript as $entry) {
      $role    = (string) ($entry['role'] ?? 'user');
      $content = (string) ($entry['content'] ?? '');

      if ($role === 'assistant') {
        $out[] = ['role' => 'assistant', 'content' => (string) ($entry['envelope'] ?? '') ?: $content];
        continue;
      }
      if ($role === 'tool') {
        $out[] = ['role' => 'user', 'content' => $content];
        continue;
      }
      $out[] = ['role' => 'user', 'content' => $content];
    }
    return $out;
  }

  /**
   * Build an assistant transcript entry: folded prose for the UI plus (when the
   * reply parsed) the raw envelope for model replay.
   *
   * @param array<string, mixed> $envelope
   * @return array<string, mixed>
   */
  private function assistantEntry(array $envelope, string $content): array {
    $entry = ['role' => 'assistant', 'content' => $content];
    if ($this->lastParseSucceeded) {
      $json = wp_json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
      if ($json !== false) {
        $entry['envelope'] = $json;
      }
    }
    return $entry;
  }

  /**
   * Validate the envelope's `reads` list down to [{ability, input}] items.
   *
   * @param mixed $reads
   * @return array<int, array{ability:string,input:array<string,mixed>}>
   */
  private function normalizeReads($reads): array {
    if (!is_array($reads)) {
      return [];
    }
    $out = [];
    foreach ($reads as $read) {
      $ability = is_array($read) ? trim((string) ($read['ability'] ?? '')) : '';
      if ($ability === '') {
        continue;
      }
      $input = is_array($read['input'] ?? null) ? $read['input'] : [];
      $out[] = ['ability' => $ability, 'input' => $input];
    }
    return $out;
  }

  /**
   * Execute a batch of requested reads and package the results as the `tool`
   * transcript entry fed back to the model. Only read-scoped abilities run —
   * anything else comes back as an inline error steering the model to propose
   * a plan step instead. Results are secret-redacted and size-capped.
   *
   * @param array<int, array{ability:string,input:array<string,mixed>}> $reads
   * @param array<int, array<string, mixed>>                            $activity Accumulates what ran (for the UI).
   * @param bool                                                        $budgetSpent True when this was the last allowed read round.
   * @return array<string, mixed> Transcript entry.
   */
  private function executeReads(array $reads, array &$activity, bool $budgetSpent): array {
    $allowed = $this->registry->readAbilityNames();
    $results = [];

    foreach ($reads as $read) {
      $ability = $read['ability'];
      $input   = $read['input'];

      if (!in_array($ability, $allowed, true)) {
        $results[] = [
          'ability' => $ability,
          'input'   => $input,
          'error'   => sprintf('"%s" is not a read ability — propose it as a plan step instead.', $ability),
        ];
        continue;
      }

      $result = $this->registry->execute($ability, $input);
      if (is_wp_error($result)) {
        $results[] = ['ability' => $ability, 'input' => $input, 'error' => $result->get_error_message()];
      } else {
        $results[] = ['ability' => $ability, 'input' => $input, 'result' => PayloadRedactor::redact($result)];
      }
      $activity[] = ['ability' => $ability, 'input' => $input];
    }

    $content = "READ RESULTS (read-only abilities executed locally, no user review needed):\n"
      . PayloadRedactor::encodeCapped($results, self::READ_RESULT_BYTES * max(1, count($results)))
      . "\n\nReply with your next JSON envelope."
      . ($budgetSpent ? ' Your read budget for this turn is spent — no further "reads"; give your final assistant_message/plan now.' : '');

    return [
      'role'    => 'tool',
      'content' => $content,
      'reads'   => array_map(static fn($r) => ['ability' => $r['ability'], 'input' => $r['input']], $reads),
    ];
  }

  /**
   * Extract a JSON envelope from the model's raw text, tolerating code fences
   * and surrounding prose.
   *
   * @return array<string, mixed>
   */
  private function parseEnvelope(string $raw): array {
    $text = trim($raw);

    // 1) The response is usually already clean JSON. Decode it as-is FIRST, so a
    //    ```code``` block embedded inside a string value (e.g. an Apps Script we
    //    tell the user to paste) isn't mistaken for the envelope's own fence.
    $decoded = json_decode($text, true);
    if (self::isEnvelope($decoded)) {
      $this->lastParseSucceeded = true;
      return $decoded;
    }

    // 2) Some models (notably Gemini) write a prose answer first and append the
    //    real envelope as a ```json … ``` block — sometimes leaving the closing
    //    fence off. Take the outermost { … } after the ```json marker; if there
    //    is prose before it, keep the prose as the visible message so the user
    //    doesn't lose the explanation.
    $jsonPos = stripos($text, '```json');
    if ($jsonPos !== false) {
      $after = substr($text, $jsonPos + strlen('```json'));
      $after = preg_replace('/```\s*$/', '', $after); // drop a closing fence if present
      $s = strpos($after, '{');
      $e = strrpos($after, '}');
      $env = ($s !== false && $e !== false && $e > $s)
        ? json_decode(substr($after, $s, $e - $s + 1), true)
        : null;
      if (self::isEnvelope($env)) {
        $prefix = trim(substr($text, 0, $jsonPos));
        if ($prefix !== '') {
          $msg = isset($env['assistant_message']) ? trim((string) $env['assistant_message']) : '';
          $env['assistant_message'] = $prefix . ($msg !== '' ? "\n\n" . $msg : '');
        }
        $this->lastParseSucceeded = true;
        return $env;
      }
    }

    // 3) The whole reply may be wrapped in a ```json … ``` fence. Unwrap greedily
    //    to the LAST fence so an inner code fence can't truncate the capture.
    if (preg_match('/```(?:json)?\s*(.*)```/s', $text, $m)) {
      $inner   = trim($m[1]);
      $decoded = json_decode($inner, true);
      if (self::isEnvelope($decoded)) {
        $this->lastParseSucceeded = true;
        return $decoded;
      }
      $text = $inner;
    }

    // 4) Fall back to the outermost { … } span, validated by a decode.
    $start = strpos($text, '{');
    $end   = strrpos($text, '}');
    if ($start !== false && $end !== false && $end > $start) {
      $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
      if (self::isEnvelope($decoded)) {
        $this->lastParseSucceeded = true;
        return $decoded;
      }
    }

    // The model didn't return valid JSON — treat the whole thing as a plain reply.
    $this->lastParseSucceeded = false;
    return ['assistant_message' => trim($raw), 'plan' => []];
  }

  /**
   * Does a decoded value look like our agent envelope? We accept any object that
   * carries at least one of the envelope's own keys, so a stray JSON object
   * embedded elsewhere in the prose isn't mistaken for the reply.
   *
   * @param mixed $value
   */
  private static function isEnvelope($value): bool {
    return is_array($value) && (
      array_key_exists('assistant_message', $value)
      || array_key_exists('plan', $value)
      || array_key_exists('clarifying_questions', $value)
      || array_key_exists('reads', $value)
    );
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
