<?php

namespace FlowSystems\WebhookActions\Services\Ai;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * Transport backed by the WordPress 7.0 AI Client (`wp_ai_client_prompt()`).
 *
 * Provider credentials are managed entirely by WordPress (Settings → Connectors),
 * so we store and handle no API keys on this path. The AI Client has no native
 * tool-calling, so we fold the transcript into a single prompt and rely on the
 * orchestrator's structured-JSON protocol for tool/plan exchange.
 *
 * The user picks a preferred model in the builder UI; we additionally carry the
 * other configured providers' models as a fallback chain so a runtime failure on
 * one provider (e.g. a free-tier quota 429) is retried on another automatically.
 */
class WpAiClientTransport implements LlmTransportInterface {
  use HandlesRetryableErrors;

  /** Default models tried when the site has no explicit preference. */
  private const DEFAULT_PREFERENCE = ['claude-sonnet-4-6', 'gemini-3.1-pro-preview', 'gpt-5.4'];

  /**
   * Ordered candidate model ids (preferred first, then cross-provider fallbacks).
   *
   * @var array<int, string>
   */
  private array $candidates;

  /** The model that actually produced the last successful generation. */
  private string $usedModel = '';

  /** @var array<string, mixed>|null */
  private ?array $lastRequest = null;

  /**
   * @param array<int, string> $candidates Ordered model ids to try, preferred first.
   */
  public function __construct(array $candidates = []) {
    $this->candidates = array_values(array_filter($candidates)) ?: self::DEFAULT_PREFERENCE;
  }

  /**
   * Whether the AI Client exists and can currently generate text (a provider is
   * configured). Deterministic and free — makes no API call.
   *
   * The builder exposes its fluent API through `__call()`, so its methods are
   * invisible to `method_exists()`; we call `is_supported_for_text_generation()`
   * directly and let the try/catch absorb any builder error.
   */
  public static function isAvailable(): bool {
    if (!function_exists('wp_ai_client_prompt')) {
      return false;
    }

    if (function_exists('wp_supports_ai') && !wp_supports_ai()) {
      return false;
    }

    try {
      return wp_ai_client_prompt('ping')->is_supported_for_text_generation() === true;
    } catch (\Throwable $e) {
      return false;
    }
  }

  public function generateText(string $system, array $messages, array $options = []): string|WP_Error {
    if (!function_exists('wp_ai_client_prompt')) {
      return new WP_Error('fswa_ai_client_missing', __('The WordPress AI Client is not available.', 'flowsystems-webhook-actions'));
    }

    // Honour an explicit per-call model, otherwise walk the candidate chain.
    $candidates = !empty($options['model'])
      ? array_values(array_unique(array_merge([(string) $options['model']], $this->candidates)))
      : $this->candidates;

    $lastError = null;

    foreach ($candidates as $modelId) {
      $result = $this->attempt($system, $messages, $options, $modelId);

      if (!is_wp_error($result)) {
        $this->usedModel = $modelId;
        return $result;
      }

      $lastError = $result;

      // Auth / bad-request style failures won't be fixed by another provider.
      if (!$this->isRetryable($result)) {
        break;
      }
    }

    return $lastError ?? new WP_Error(
      'fswa_ai_client_no_provider',
      __('No configured AI provider could complete the request.', 'flowsystems-webhook-actions')
    );
  }

  /**
   * One generation attempt pinned to a specific model id.
   *
   * @param array<int, array{role:string,content:string}> $messages
   * @param array<string, mixed>                           $options
   */
  private function attempt(string $system, array $messages, array $options, string $modelId): string|WP_Error {
    $prompt = $this->flattenMessages($messages);

    // The AI Client owns the HTTP call, so the true wire payload is invisible
    // here; record what we hand the builder instead (the last attempt wins).
    $this->lastRequest = [
      'endpoint' => 'wp_ai_client_prompt()',
      'body'     => [
        'prompt'             => $prompt,
        'system_instruction' => $system,
        'temperature'        => (float) ($options['temperature'] ?? 0.2),
        'model_preference'   => $modelId,
      ],
    ];

    try {
      // The builder's fluent methods dispatch through __call(), so they are not
      // visible to method_exists(); call them directly. The builder records any
      // failure internally and surfaces it from generate_text() as a WP_Error.
      $builder = wp_ai_client_prompt($prompt);

      if ($system !== '') {
        $builder = $builder->using_system_instruction($system);
      }

      $builder = $builder->using_temperature((float) ($options['temperature'] ?? 0.2));

      // A single-entry preference pins this exact model (and thus its provider).
      $builder = $builder->using_model_preference($modelId);

      $text = $builder->generate_text();

      if (is_wp_error($text)) {
        return $text;
      }

      return is_string($text) && $text !== ''
        ? $text
        : new WP_Error('fswa_ai_client_empty', __('The AI Client returned no text.', 'flowsystems-webhook-actions'));
    } catch (\Throwable $e) {
      return new WP_Error('fswa_ai_client_error', $e->getMessage());
    }
  }

  public function id(): string {
    return 'wp_ai_client';
  }

  public function model(): string {
    return $this->usedModel !== '' ? $this->usedModel : ($this->candidates[0] ?? self::DEFAULT_PREFERENCE[0]);
  }

  /**
   * The model that produced the last successful generation, if any.
   */
  public function usedModel(): string {
    return $this->usedModel;
  }

  public function lastRequest(): ?array {
    return $this->lastRequest;
  }

  public function lastResponseMeta(): array {
    // The AI Client owns the HTTP call and does not expose the provider's
    // finish reason, so there is nothing truthful to report here.
    return [];
  }

  /**
   * Fold a multi-turn transcript into one prompt string, since the builder takes
   * a single user prompt. The system instruction is passed separately.
   *
   * @param array<int, array{role:string,content:string}> $messages
   */
  private function flattenMessages(array $messages): string {
    $lines = [];
    foreach ($messages as $message) {
      $role    = ($message['role'] ?? 'user') === 'assistant' ? 'Assistant' : 'User';
      $lines[] = $role . ': ' . (string) ($message['content'] ?? '');
    }
    $lines[] = 'Assistant:';

    return implode("\n\n", $lines);
  }
}
