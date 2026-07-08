<?php

namespace FlowSystems\WebhookActions\Services\Ai;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * Wraps an ordered list of concrete transports (each already pinned to one
 * provider + model) and tries them in turn, falling back to the next on a
 * retryable failure (rate limit, quota 429, upstream 5xx, network). Used by the
 * bring-your-own-key path so a failure on the user's active provider retries on
 * another provider they have keyed — mirroring the AI Client path's behaviour.
 */
class FallbackTransport implements LlmTransportInterface {
  use HandlesRetryableErrors;

  /** @var array<int, LlmTransportInterface> */
  private array $transports;

  /** The transport that produced the last successful generation. */
  private ?LlmTransportInterface $used = null;

  /** The transport tried most recently (set even when every attempt failed). */
  private ?LlmTransportInterface $lastAttempted = null;

  /** The error from the PREFERRED transport when the last call had to fall back. */
  private ?WP_Error $fallbackError = null;

  /**
   * @param array<int, LlmTransportInterface> $transports Ordered, preferred first.
   */
  public function __construct(array $transports) {
    $this->transports = array_values(array_filter(
      $transports,
      static fn($t) => $t instanceof LlmTransportInterface
    ));
  }

  public function isEmpty(): bool {
    return $this->transports === [];
  }

  public function generateText(string $system, array $messages, array $options = []): string|WP_Error {
    // Reset per call so didFallBack()/fallbackReason() describe THIS generation.
    $this->used          = null;
    $this->fallbackError = null;
    $lastError           = null;

    foreach ($this->transports as $index => $transport) {
      $this->lastAttempted = $transport;
      $result = $transport->generateText($system, $messages, $options);

      if (!is_wp_error($result)) {
        $this->used = $transport;
        return $result;
      }

      // Remember why the user's PREFERRED provider failed, so if a later one
      // succeeds we can tell the user what happened and why.
      if ($index === 0) {
        $this->fallbackError = $result;
      }
      $lastError = $result;
      if (!$this->isRetryable($result)) {
        break;
      }
    }

    return $lastError ?? new WP_Error(
      'fswa_no_provider',
      __('No configured AI provider could complete the request.', 'flowsystems-webhook-actions')
    );
  }

  /** True when the last successful generation came from a non-preferred provider. */
  public function didFallBack(): bool {
    return $this->used !== null
      && isset($this->transports[0])
      && $this->used !== $this->transports[0];
  }

  /** The provider id the user actually selected (preferred, first in the list). */
  public function requestedId(): string {
    return ($this->transports[0] ?? null)?->id() ?? 'byok';
  }

  /** The model the user actually selected. */
  public function requestedModel(): string {
    return ($this->transports[0] ?? null)?->model() ?? '';
  }

  /** Human-readable reason the preferred provider failed on the last fallback. */
  public function fallbackReason(): string {
    return $this->fallbackError?->get_error_message() ?? '';
  }

  public function id(): string {
    return ($this->used ?? $this->transports[0] ?? null)?->id() ?? 'byok';
  }

  public function model(): string {
    return ($this->used ?? $this->transports[0] ?? null)?->model() ?? '';
  }

  public function lastRequest(): ?array {
    return ($this->used ?? $this->lastAttempted)?->lastRequest();
  }
}
