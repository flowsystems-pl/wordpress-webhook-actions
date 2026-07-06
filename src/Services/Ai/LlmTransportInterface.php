<?php

namespace FlowSystems\WebhookActions\Services\Ai;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * A text-generation transport for the AI Builder.
 *
 * Implementations wrap a concrete brain — the WordPress 7.0 AI Client, a
 * bring-your-own-key provider call, or (in Pro) our hosted backend — behind a
 * single uniform method. The orchestrator never cares which one is active.
 */
interface LlmTransportInterface {
  /**
   * Generate an assistant response.
   *
   * @param string                                  $system   System instruction.
   * @param array<int, array{role:string,content:string}> $messages Ordered chat turns.
   * @param array<string, mixed>                    $options  e.g. ['model' => …, 'max_tokens' => …, 'temperature' => …].
   * @return string|WP_Error The assistant's raw text (a JSON envelope, per protocol) or an error.
   */
  public function generateText(string $system, array $messages, array $options = []): string|WP_Error;

  /**
   * Short machine id of this transport, e.g. 'wp_ai_client' or 'anthropic_byok'.
   */
  public function id(): string;

  /**
   * The model this transport will use (for display / logging).
   */
  public function model(): string;

  /**
   * The exact wire request of the most recent generateText() call — endpoint,
   * headers (secret values redacted), and the decoded JSON body — or null when
   * nothing has been sent yet. Transports that don't own the HTTP call (the WP
   * AI Client) return their best-effort equivalent: what they handed the client.
   *
   * @return array<string, mixed>|null
   */
  public function lastRequest(): ?array;
}
