<?php

namespace FlowSystems\WebhookActions\Services\Ai;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * Shared classification of transport errors worth retrying on a different
 * provider/model: rate limits, quota exhaustion, upstream 5xx, and transient
 * network failures. Auth and malformed-request errors are not retryable.
 */
trait HandlesRetryableErrors {
  protected function isRetryable(WP_Error $error): bool {
    $data   = $error->get_error_data();
    $status = is_array($data) ? (int) ($data['status'] ?? 0) : 0;

    if ($status === 429 || $status === 408 || $status >= 500) {
      return true;
    }

    $code = $error->get_error_code();
    if (in_array($code, ['prompt_network_error', 'prompt_upstream_server_error', 'fswa_ai_client_error'], true)) {
      return true;
    }

    // Some providers report quota/rate problems only in the message text.
    $message = strtolower($error->get_error_message());
    foreach (['rate limit', 'quota', 'too many requests', 'overloaded', 'temporarily'] as $needle) {
      if (strpos($message, $needle) !== false) {
        return true;
      }
    }

    return false;
  }
}
