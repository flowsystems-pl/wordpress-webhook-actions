<?php

namespace FlowSystems\WebhookActions\Services\Notifications;

defined('ABSPATH') || exit;

/**
 * Does rule R apply to delivery context C? Pure, no I/O.
 *
 * Filters a rule may carry (all optional, all AND-ed):
 *   attempts        int[]                 which attempt numbers (1-based); empty = any
 *   http_codes      string[]              exact codes, classes ("4xx","5xx") or "transport" (no HTTP response)
 *   reason          exhausted|non_retryable|any   permanently_failed only
 *   triggers        string[]              exact names or wildcards ("woocommerce_*"); empty = any
 *   only_after_retry bool                 success only: skip first-try successes
 *   include_tests   bool                  fire for test dispatches too (default false)
 */
class RuleMatcher {
  /**
   * @param array<string, mixed> $rule Cast rule row (filters decoded)
   * @param array<string, mixed> $ctx  Normalized DeliveryEvents context
   */
  public function matches(array $rule, array $ctx): bool {
    if (($rule['event'] ?? '') !== ($ctx['event'] ?? '')) {
      return false;
    }

    $filters = is_array($rule['filters'] ?? null) ? $rule['filters'] : [];

    if (!empty($ctx['is_test']) && empty($filters['include_tests'])) {
      return false;
    }

    if (!$this->attemptMatches($filters['attempts'] ?? null, (int) ($ctx['attempt'] ?? 1))) {
      return false;
    }

    if (!$this->httpCodeMatches($filters['http_codes'] ?? null, $ctx['http_code'] ?? null, (string) ($ctx['event'] ?? ''))) {
      return false;
    }

    if (($ctx['event'] ?? '') === DeliveryEvents::PERMANENTLY_FAILED) {
      $want = (string) ($filters['reason'] ?? 'any');
      if ($want !== '' && $want !== 'any' && $want !== (string) ($ctx['reason'] ?? '')) {
        return false;
      }
    }

    if (($ctx['event'] ?? '') === DeliveryEvents::SUCCESS && !empty($filters['only_after_retry']) && empty($ctx['had_failures'])) {
      return false;
    }

    if (!$this->triggerMatches($filters['triggers'] ?? null, (string) ($ctx['trigger'] ?? ''))) {
      return false;
    }

    return true;
  }

  /**
   * @param mixed $wanted int[] | "any" | null
   */
  private function attemptMatches(mixed $wanted, int $attempt): bool {
    if (!is_array($wanted) || empty($wanted)) {
      return true;
    }
    $list = array_map('intval', $wanted);

    return in_array($attempt, $list, true);
  }

  /**
   * @param mixed $wanted string[] | null
   */
  private function httpCodeMatches(mixed $wanted, mixed $code, string $event): bool {
    if (!is_array($wanted) || empty($wanted)) {
      return true;
    }
    // Success-type events carry a 2xx; the filter is meant for failures but
    // is honoured literally if someone sets it.
    foreach ($wanted as $entry) {
      $entry = strtolower(trim((string) $entry));
      if ($entry === '' || $entry === 'any') {
        return true;
      }
      if ($entry === 'transport') {
        if ($code === null && $event !== DeliveryEvents::SKIPPED) {
          return true;
        }
        continue;
      }
      if ($code === null) {
        continue;
      }
      $code = (int) $code;
      if (preg_match('/^([1-5])xx$/', $entry, $m)) {
        if (intdiv($code, 100) === (int) $m[1]) {
          return true;
        }
        continue;
      }
      if (preg_match('/^(\d{3})-(\d{3})$/', $entry, $m)) {
        if ($code >= (int) $m[1] && $code <= (int) $m[2]) {
          return true;
        }
        continue;
      }
      if (ctype_digit($entry) && (int) $entry === $code) {
        return true;
      }
    }

    return false;
  }

  /**
   * @param mixed $wanted string[] | null
   */
  private function triggerMatches(mixed $wanted, string $trigger): bool {
    if (!is_array($wanted) || empty($wanted)) {
      return true;
    }
    foreach ($wanted as $pattern) {
      $pattern = trim((string) $pattern);
      if ($pattern === '' || $pattern === '*') {
        return true;
      }
      if (strpos($pattern, '*') === false) {
        if ($pattern === $trigger) {
          return true;
        }
        continue;
      }
      $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';
      if (preg_match($regex, $trigger)) {
        return true;
      }
    }

    return false;
  }
}
