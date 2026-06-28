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
    $lastError = null;

    foreach ($this->transports as $transport) {
      $result = $transport->generateText($system, $messages, $options);

      if (!is_wp_error($result)) {
        $this->used = $transport;
        return $result;
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

  public function id(): string {
    return ($this->used ?? $this->transports[0] ?? null)?->id() ?? 'byok';
  }

  public function model(): string {
    return ($this->used ?? $this->transports[0] ?? null)?->model() ?? '';
  }
}
