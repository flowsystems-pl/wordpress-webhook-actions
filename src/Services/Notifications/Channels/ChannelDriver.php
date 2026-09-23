<?php

namespace FlowSystems\WebhookActions\Services\Notifications\Channels;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * One notification destination type. A driver declares the fields its
 * settings form needs (the UI is generated from them), and turns the neutral
 * message into whatever its target expects.
 *
 * Fields: ['key', 'label', 'type', 'required', 'help', 'options', 'default',
 * 'placeholder']. Types: text | url | secret | textarea | emails | select |
 * number | toggle. Every `secret` field is encrypted at rest and never returned
 * by the REST API.
 */
interface ChannelDriver {
  public function type(): string;

  public function label(): string;

  /** One-line description for the channel picker. */
  public function description(): string;

  /** @return array<int, array<string, mixed>> */
  public function fields(): array;

  /**
   * Deliver one message.
   *
   * @param array<string, mixed> $message  From MessageBuilder
   * @param array<string, mixed> $config   Non-secret channel config
   * @param array<string, string> $secrets Decrypted secret fields
   * @return true|WP_Error
   */
  public function send(array $message, array $config, array $secrets): true|WP_Error;
}
