<?php

namespace FlowSystems\WebhookActions\Services\Ai;

defined('ABSPATH') || exit;

/**
 * Turns a probe_endpoint result into an actionable pause for the run, or null
 * when the probe reached the endpoint fine and the run should continue. We only
 * stop for the cases the user can act on: authentication needed (401/403), a
 * wrong/absent endpoint (404/405/410), and a hard transport failure (unreachable).
 */
final class ProbeInterpreter {
  /**
   * @param array<string, mixed> $result
   * @return array{kind:string, status:int, message:string}|null
   */
  public static function interpret(array $result): ?array {
    // Transport failure — DNS, TLS, timeout, SSRF block surfaced as ok=false.
    if (($result['ok'] ?? null) === false) {
      $detail = trim((string) ($result['error'] ?? ''));
      return [
        'kind'    => 'unreachable',
        'status'  => 0,
        'message' => $detail !== ''
          ? sprintf(
            /* translators: %s: underlying HTTP error message. */
            __('The endpoint could not be reached (%s). Check the URL is correct and publicly reachable, then retry.', 'flowsystems-webhook-actions'),
            $detail
          )
          : __('The endpoint could not be reached. Check the URL is correct and publicly reachable, then retry.', 'flowsystems-webhook-actions'),
      ];
    }

    $status = (int) ($result['status'] ?? 0);

    if (in_array($status, [401, 403], true)) {
      return [
        'kind'    => 'auth',
        'status'  => $status,
        'message' => sprintf(
          /* translators: %d: HTTP status code (401 or 403). */
          __('The endpoint responded %d — it needs authentication. Add a credential to the webhook, then retry.', 'flowsystems-webhook-actions'),
          $status
        ),
      ];
    }

    if (in_array($status, [404, 405, 410], true)) {
      return [
        'kind'    => 'endpoint',
        'status'  => $status,
        'message' => sprintf(
          /* translators: %d: HTTP status code (404, 405 or 410). */
          __('The endpoint responded %d — the URL may be wrong or not accept this request. Double-check the endpoint URL on the webhook, then retry.', 'flowsystems-webhook-actions'),
          $status
        ),
      ];
    }

    return null;
  }
}
