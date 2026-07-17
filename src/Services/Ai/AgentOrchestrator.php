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

  /**
   * How many trailing `tool` (read-result) entries replay in full. One turn
   * produces at most READ_ITERATIONS_MAX of them, so this keeps the CURRENT
   * turn's results intact while read dumps from earlier turns get capped —
   * they are stale, and replaying them whole grows every later prompt (a 16KB
   * list_triggers dump re-sent on each round pushed a provider past timeout).
   */
  private const REPLAY_TOOL_FULL_COUNT = self::READ_ITERATIONS_MAX + 1;

  /** Byte cap for a stale (earlier-turn) read-result entry in model replay. */
  private const REPLAY_TOOL_STALE_BYTES = 1024;

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

    $transcript = is_array($conversation['transcript_json'] ?? null) ? $conversation['transcript_json'] : [];

    // A retry after a transport failure re-sends the same message: keep the
    // persisted turn (its completed read rounds replay for free) instead of
    // appending a duplicate user entry.
    if (!$this->isRetryAfterFailure($transcript, $userMessage)) {
      $transcript[] = ['role' => 'user', 'content' => $userMessage];
    }

    $system   = $this->prompts->build($conversation);
    $options  = ['temperature' => 0.2, 'json' => true];
    $activity = [];

    // A turn can be several model round-trips: while the envelope asks for
    // "reads" (read-only abilities), execute them locally, feed the results
    // back, and ask again. Bounded, so the whole turn stays one HTTP request.
    for ($iteration = 0; ; $iteration++) {
      // Reset per round-trip: each provider call may take up to the transport
      // timeout, so one shared budget would starve the later rounds.
      if (function_exists('set_time_limit')) {
        @set_time_limit(180);
      }

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
        // Persist what the turn accumulated (user message + completed read
        // rounds) so a retry continues from here instead of re-paying the
        // reads — before this, a mid-turn failure silently discarded it all.
        $this->persistFailedTurn($conversationId, $conversation, $transport, $transcript, $userMessage, $raw);
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

      // Interim persistence: the chat UI polls the conversation while the turn
      // runs, so each completed read round becomes visible progress — and a
      // hard crash mid-turn keeps the rounds already paid for.
      $this->conversations->update($conversationId, ['transcript' => $transcript]);
    }

    // Keep the human-readable reply — WITH any clarifying questions folded in — in
    // the transcript for the UI, and the raw envelope alongside it: the model gets
    // the envelope replayed next turn (a prose history teaches it to answer in
    // prose and breaks the JSON contract — see the 2026-07-06 trace).
    $assistantText = (string) ($envelope['assistant_message'] ?? '');
    $clarifying    = array_values((array) ($envelope['clarifying_questions'] ?? []));

    // If the user's selected provider failed and we answered on a fallback, the
    // user otherwise only learns this from the Dev Trace. Surface it as a notice
    // on the turn so the chat shows which provider actually answered and why.
    $notice = $this->fallbackNotice($transport);

    // Steps the model proposed with abilities this site cannot run were dropped
    // from the plan — the remaining plan can look complete while missing a
    // load-bearing piece (e.g. the Code Glue step injecting a required field),
    // so the user must hear about it before running the build.
    $dropped = array_values(array_unique($this->executor->lastDroppedAbilities()));
    if ($dropped !== []) {
      $droppedNotice = sprintf(
        /* translators: %s: comma-separated ability names. */
        __('Some proposed steps were removed because this site cannot run them: %s. The remaining plan may be incomplete. If these are Code Glue abilities, make sure Webhook Actions Pro is installed, up to date and licensed.', 'flowsystems-webhook-actions'),
        implode(', ', $dropped)
      );
      $notice = $notice === null ? $droppedNotice : $notice . ' ' . $droppedNotice;
    }

    $finalEntry = $this->assistantEntry($envelope, $this->foldReply($assistantText, $clarifying));
    if ($notice !== null) {
      $finalEntry['notice'] = $notice;
    }
    $transcript[] = $finalEntry;

    // A fresh plan seeds a runnable execution state machine (cursor + per-step
    // status). A clarifying-only reply (no plan) leaves any prior run untouched.
    // The prior run is passed in so its applied-object ledger carries forward and
    // a re-proposed create/provision step is reused, not duplicated.
    $priorExecution = is_array($conversation['execution_json'] ?? null) ? $conversation['execution_json'] : [];
    $execution      = $plan !== [] ? $this->executor->seedExecution($plan, null, $priorExecution) : null;

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
      'notice'               => $notice,
    ];
  }

  /**
   * When a bring-your-own-key turn answered on a fallback provider (the user's
   * selected one failed), build a short user-facing notice naming both providers
   * and the reason — otherwise the fallback is invisible outside the Dev Trace.
   * Returns null when no fallback happened.
   */
  private function fallbackNotice(LlmTransportInterface $transport): ?string {
    if (!($transport instanceof FallbackTransport) || !$transport->didFallBack()) {
      return null;
    }
    $reason = $transport->fallbackReason();
    return sprintf(
      /* translators: 1: selected provider/model, 2: fallback provider/model, 3: error reason */
      __('Your selected model %1$s couldn\'t respond, so this reply came from %2$s instead. (%3$s) Pick a different model with Change model.', 'flowsystems-webhook-actions'),
      $this->providerLabel($transport->requestedId(), $transport->requestedModel()),
      $this->providerLabel($transport->id(), $transport->model()),
      $reason !== '' ? $reason : __('provider unavailable', 'flowsystems-webhook-actions')
    );
  }

  /** "Google (gemini-3.5-flash)" style label for a provider id + model. */
  private function providerLabel(string $id, string $model): string {
    $names = ['openai' => 'OpenAI', 'google' => 'Google', 'anthropic' => 'Anthropic'];
    $name  = $names[$id] ?? ucfirst($id);
    return $model !== '' ? sprintf('%s (%s)', $name, $model) : $name;
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
    // Only the last few read-result entries replay in full (see the constant):
    // older ones belong to finished turns and would bloat every prompt.
    $toolIndexes = array_keys(array_filter(
      $transcript,
      static fn($entry) => (($entry['role'] ?? '') === 'tool') && empty($entry['error'])
    ));
    $fullToolsFrom = count($toolIndexes) > self::REPLAY_TOOL_FULL_COUNT
      ? $toolIndexes[count($toolIndexes) - self::REPLAY_TOOL_FULL_COUNT]
      : 0;

    $out = [];
    foreach ($transcript as $index => $entry) {
      // Transport-failure notices are UI-only history — the model never said
      // them, so replaying them would only pollute its few-shot format.
      if (!empty($entry['error'])) {
        continue;
      }

      $role    = (string) ($entry['role'] ?? 'user');
      $content = (string) ($entry['content'] ?? '');

      if ($role === 'assistant') {
        $out[] = ['role' => 'assistant', 'content' => (string) ($entry['envelope'] ?? '') ?: $content];
        continue;
      }
      if ($role === 'tool') {
        if ($index < $fullToolsFrom && strlen($content) > self::REPLAY_TOOL_STALE_BYTES) {
          $content = substr($content, 0, self::REPLAY_TOOL_STALE_BYTES)
            . "\n…[older read results truncated — re-run the read if you need them]";
        }
        $out[] = ['role' => 'user', 'content' => $content];
        continue;
      }
      $out[] = ['role' => 'user', 'content' => $content];
    }

    // Skipping error entries (and tool→user rendering) can leave same-role
    // neighbours; merge them so transports that expect alternation stay happy.
    $merged = [];
    foreach ($out as $message) {
      $last = count($merged) - 1;
      if ($last >= 0 && $merged[$last]['role'] === $message['role']) {
        $merged[$last]['content'] .= "\n\n" . $message['content'];
        continue;
      }
      $merged[] = $message;
    }
    return $merged;
  }

  /**
   * True when the incoming message is a re-send of the message that opened the
   * last (transport-failed) turn: the transcript ends with an error notice and
   * its most recent user entry is the same text. The UI's retry button re-sends
   * verbatim, so this keeps the persisted turn instead of duplicating it.
   *
   * @param array<int, array<string, mixed>> $transcript
   */
  private function isRetryAfterFailure(array $transcript, string $userMessage): bool {
    $last = end($transcript);
    if (!is_array($last) || empty($last['error'])) {
      return false;
    }
    for ($i = count($transcript) - 1; $i >= 0; $i--) {
      if ((string) ($transcript[$i]['role'] ?? '') === 'user') {
        return (string) ($transcript[$i]['content'] ?? '') === $userMessage;
      }
    }
    return false;
  }

  /**
   * Save the transcript of a turn that died on a transport error, closed with
   * a UI-visible notice flagged `error` (excluded from model replay). Whatever
   * read rounds completed are kept, so retrying is cheap.
   *
   * @param array<string, mixed>             $conversation
   * @param array<int, array<string, mixed>> $transcript
   */
  private function persistFailedTurn(int $conversationId, array $conversation, LlmTransportInterface $transport, array $transcript, string $userMessage, WP_Error $error): void {
    $transcript[] = [
      'role'    => 'assistant',
      'content' => sprintf(
        /* translators: %s: the AI provider's error message. */
        __('The AI provider request failed mid-turn (%s). Progress so far is saved — send the message again to retry from here.', 'flowsystems-webhook-actions'),
        $error->get_error_message()
      ),
      'error'   => true,
    ];
    $this->conversations->update($conversationId, [
      'transport'  => $transport->id(),
      'model'      => $transport->model(),
      'transcript' => $transcript,
      'title'      => $conversation['title'] !== '' ? $conversation['title'] : $this->deriveTitle($userMessage),
    ]);
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

    // 4) Last resort: run a conservative JSON repair over the text and decode
    //    that. This folds together the quirks models actually emit — stray
    //    trailing output after the object (e.g. Gemini's extra "}"), a dangling
    //    comma before a "}"/"]", and raw newlines/tabs inside string values —
    //    into one lint pass instead of a per-quirk fallback ladder.
    $repaired = self::repairJsonObject($text);
    if ($repaired !== null) {
      $decoded = json_decode($repaired, true);
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
   * Conservative, lossless JSON repair for a single object embedded in model
   * output. Scans from the first "{" to its matching close brace — tracking
   * string literals and escapes so braces inside strings don't affect depth —
   * and while copying it:
   *   • escapes raw control chars (newline, CR, tab) that appear INSIDE strings,
   *     which models emit unescaped and which json_decode rejects;
   *   • drops a trailing comma immediately before a "}" or "]".
   * Anything after the balanced object (stray braces, prose) is ignored.
   *
   * When the text runs out with structures still open (a truncated reply), the
   * missing closers are appended ONLY for a purely structural cut: never inside
   * a string, and only when the last emitted token is already a complete value
   * ("…", }, ], true/false/null, or a number). Gemini's JSON mode is known to
   * drop the final "}" of an otherwise complete envelope — that case is now
   * recovered losslessly. A cut after "," / ":" / an opener means a value was
   * lost, so those still return null (fail safe) rather than fabricating a
   * smaller object that looks complete.
   */
  private static function repairJsonObject(string $text): ?string {
    $start = strpos($text, '{');
    if ($start === false) {
      return null;
    }

    $out      = '';
    $stack    = []; // expected closers, innermost last
    $inString = false;
    $escaped  = false;
    $len      = strlen($text);

    for ($i = $start; $i < $len; $i++) {
      $ch = $text[$i];

      if ($inString) {
        if ($escaped) {
          $escaped = false;
          $out    .= $ch;
        } elseif ($ch === '\\') {
          $escaped = true;
          $out    .= $ch;
        } elseif ($ch === '"') {
          $inString = false;
          $out     .= $ch;
        } elseif ($ch === "\n") {
          $out .= '\\n';
        } elseif ($ch === "\r") {
          $out .= '\\r';
        } elseif ($ch === "\t") {
          $out .= '\\t';
        } else {
          $out .= $ch;
        }
        continue;
      }

      if ($ch === '"') {
        $inString = true;
        $out     .= $ch;
      } elseif ($ch === '{' || $ch === '[') {
        $stack[] = $ch === '{' ? '}' : ']';
        $out    .= $ch;
      } elseif ($ch === '}' || $ch === ']') {
        // Strip a dangling comma (and any whitespace) before the closer.
        $trimmed = rtrim($out);
        if (substr($trimmed, -1) === ',') {
          $out = substr($trimmed, 0, -1);
        }
        $out .= $ch;
        array_pop($stack);
        if ($stack === []) {
          return $out;
        }
      } else {
        $out .= $ch;
      }
    }

    // Truncated reply: force-close only a structural cut (see docblock).
    if ($inString || $stack === []) {
      return null;
    }
    $tail = rtrim($out);
    $last = substr($tail, -1);
    $endsWithValue = in_array($last, ['"', '}', ']'], true)
      || preg_match('/(?:true|false|null|-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?)$/', $tail) === 1;
    if (!$endsWithValue) {
      return null;
    }
    return $tail . implode('', array_reverse($stack));
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
      'finish_reason'   => $transport->lastResponseMeta()['finish_reason'] ?? null,
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
