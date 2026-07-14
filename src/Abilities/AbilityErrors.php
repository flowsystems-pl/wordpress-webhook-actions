<?php

namespace FlowSystems\WebhookActions\Abilities;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * Shared error responses for ability handlers (ReadAbilities, WriteAbilities,
 * TestAbilities) so every handler refuses in the same shape the agent and the
 * plan-review UI already understand.
 */
trait AbilityErrors {

  private function notFound(): WP_Error {
    return new WP_Error('fswa_not_found', __('Not found.', 'flowsystems-webhook-actions'), ['status' => 404]);
  }

  private function invalid(string $message): WP_Error {
    return new WP_Error('fswa_invalid', $message, ['status' => 400]);
  }
}
