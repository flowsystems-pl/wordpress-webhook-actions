<?php

namespace FlowSystems\WebhookActions\Services\Ai;

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
    $out = [];
    foreach ($data as $key => $value) {
      if (is_array($value)) {
        $out[$key] = self::redact($value);
        continue;
      }
      $out[$key] = (is_string($key) && preg_match(self::SECRET_LEAF, $key)) ? self::PLACEHOLDER : $value;
    }
    return $out;
  }

  /**
   * True when a leaf key name looks secret (shared with the prompt builder's
   * flattened-path rendering, where only the LAST path segment is checked).
   */
  public static function isSecretLeaf(string $key): bool {
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
    $isList  = array_is_list($data);
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
