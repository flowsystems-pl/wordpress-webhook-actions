<?php

namespace FlowSystems\WebhookActions\Services\Ai;

use FlowSystems\WebhookActions\Support\Arr;

defined('ABSPATH') || exit;

/**
 * Scrubs payload data before it is shown to the LLM.
 *
 * Captured payloads and delivery logs can carry secrets (user_pass on
 * user_register, tokens, API keys). Whenever such data is handed to the model —
 * as a read-ability result or inside the system prompt — the field NAME is what
 * the model needs, never the secret VALUE. Also enforces a byte budget so one
 * huge payload (a full form definition, a big log page) can't blow the context.
 */
class PayloadRedactor {
  /** Leaf key names whose values are always replaced with a placeholder. */
  private const SECRET_LEAF = '/(^|_)(pass(word)?|pwd|secret|token|credential|nonce|salt|key|apikey|auth|authorization)($|_)/i';

  private const PLACEHOLDER = '[redacted]';

  /**
   * Recursively replace secret-named leaf values with a placeholder.
   *
   * @param array<mixed> $data
   * @return array<mixed>
   */
  public static function redact(array $data): array {
    if (self::isHeaderPair($data)) {
      return self::redactHeaderPair($data);
    }

    $out = [];
    foreach ($data as $key => $value) {
      if (is_array($value)) {
        $out[$key] = self::redact($value);
        continue;
      }
      // An empty secret field stays empty: "[redacted]" on a null auth_header
      // tells the reader a header exists when there is none.
      if (is_string($key) && self::isSecretLeaf($key) && $value !== null && $value !== '') {
        $out[$key] = self::PLACEHOLDER;
        continue;
      }
      $out[$key] = is_string($value) ? self::redactJsonString($value) : $value;
    }
    return $out;
  }

  /**
   * Scrub secrets inside a value that is itself encoded JSON.
   *
   * Response bodies are stored as strings, and an endpoint that echoes the
   * request (n8n's "respond with input", webhook.site, most test catchers) hands
   * the whole payload back inside one — `user_pass` included, out of reach of a
   * key-based walk. Non-JSON strings and JSON with nothing to scrub come back
   * untouched.
   */
  private static function redactJsonString(string $value): string {
    $trimmed = ltrim($value);
    if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
      return $value;
    }
    $decoded = json_decode($value, true);
    if (!is_array($decoded)) {
      return $value;
    }
    $redacted = self::redact($decoded);
    if ($redacted === $decoded) {
      return $value;
    }
    $json = wp_json_encode($redacted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $json === false ? self::PLACEHOLDER : $json;
  }

  /**
   * Custom headers and URL params are stored as `{key, value}` rows, which the
   * leaf rule gets backwards: it blanks the harmless NAME (the leaf is called
   * "key") and passes an `Authorization` header's VALUE straight through. For
   * those rows, judge the value by what the row's key says it is.
   *
   * @param array<mixed> $data
   */
  private static function isHeaderPair(array $data): bool {
    return count($data) === 2
      && isset($data['key'], $data['value'])
      && is_string($data['key'])
      && !is_array($data['value']);
  }

  /**
   * @param array<string, mixed> $pair
   * @return array<string, mixed>
   */
  private static function redactHeaderPair(array $pair): array {
    // `X-Api-Key` → `x_api_key`, so header spellings hit the same leaf rule.
    $name = strtolower(str_replace('-', '_', $pair['key']));
    if (self::isSecretLeaf($name) || $name === 'cookie') {
      $pair['value'] = self::PLACEHOLDER;
    }
    return $pair;
  }

  /**
   * True when a leaf key name looks secret (shared with the prompt builder's
   * flattened-path rendering, where only the LAST path segment is checked).
   */
  public static function isSecretLeaf(string $key): bool {
    // `auth_credential_id`, `token_id`: a reference to a stored secret, not the
    // secret. Callers need the id to point at the credential again.
    if (preg_match('/_ids?$/i', $key)) {
      return false;
    }
    return (bool) preg_match(self::SECRET_LEAF, $key);
  }

  /**
   * Encode a value as compact JSON, shrinking it to fit a byte budget.
   *
   * Oversized structures are reduced by dropping list tails and truncating long
   * strings level by level until the encoded form fits (or a floor is reached);
   * as a last resort the JSON itself is cut with a marker. Never returns false.
   */
  public static function encodeCapped(mixed $value, int $maxBytes = 8192): string {
    $json = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
      return '"[unencodable]"';
    }
    if (strlen($json) <= $maxBytes) {
      return $json;
    }

    // Progressively tighter shrink passes: fewer list items, shorter strings.
    foreach ([[20, 400], [10, 160], [5, 64]] as [$listCap, $stringCap]) {
      $shrunk = is_array($value) ? self::shrink($value, $listCap, $stringCap) : $value;
      $json   = wp_json_encode($shrunk, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
      if ($json !== false && strlen($json) <= $maxBytes) {
        return $json;
      }
    }

    return substr((string) $json, 0, $maxBytes) . '…[truncated]';
  }

  /**
   * @param array<mixed> $data
   * @return array<mixed>
   */
  private static function shrink(array $data, int $listCap, int $stringCap): array {
    $isList  = Arr::isList($data);
    $slice   = $isList && count($data) > $listCap;
    $entries = $slice ? array_slice($data, 0, $listCap) : $data;

    $out = [];
    foreach ($entries as $key => $value) {
      if (is_array($value)) {
        $out[$key] = self::shrink($value, $listCap, $stringCap);
      } elseif (is_string($value) && mb_strlen($value) > $stringCap) {
        $out[$key] = mb_substr($value, 0, $stringCap) . '…';
      } else {
        $out[$key] = $value;
      }
    }
    if ($slice) {
      $out[] = '…(' . (count($data) - $listCap) . ' more)';
    }
    return $out;
  }
}
