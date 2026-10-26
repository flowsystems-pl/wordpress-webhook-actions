<?php

namespace FlowSystems\WebhookActions\Support;

defined('ABSPATH') || exit;

/**
 * Endpoint URLs may carry {{ dot.path }} placeholders that the dispatcher
 * expands from the payload (PayloadGlueHooks::expandUrlTemplates). Two
 * helpers keep that feature usable from the AI write abilities:
 *
 * - sanitize(): esc_url_raw() strips "{" and "}", so a templated URL saved
 *   through an ability came out as ".../repos/github_repo/issues" (staging
 *   webhook #42, 2026-09-11). The tokens are parked, the rest is escaped as
 *   before, and the tokens are put back in canonical "{{ path }}" form.
 * - stripInternalKeys(): a value that exists only to fill a URL or header
 *   placeholder should not reach the vendor's body. Top-level keys starting
 *   with "__" are that convention; they are removed right before sending,
 *   after every placeholder has been resolved.
 */
class UrlTemplate {

  private const PATTERN = '/\{\{\s*([\w][\w.]*)\s*\}\}/';

  /**
   * esc_url_raw() with {{ dot.path }} placeholders preserved.
   */
  public static function sanitize(string $url): string {
    $tokens    = [];
    $protected = (string) preg_replace_callback(self::PATTERN, static function (array $m) use (&$tokens): string {
      $i          = count($tokens);
      $tokens[$i] = '{{ ' . $m[1] . ' }}';
      return '__fswatpl' . $i . '__';
    }, $url);

    $clean = esc_url_raw($protected);

    foreach ($tokens as $i => $token) {
      $clean = str_replace('__fswatpl' . $i . '__', $token, $clean);
    }

    return $clean;
  }

  /**
   * Why an endpoint URL could never be delivered, or null when it can be.
   *
   * The same rule the dispatcher applies at send time (deliveryUrlError()),
   * checked on save. Before this a plain http:// URL saved fine and then failed
   * every delivery with a generic "Invalid URL format" (found by WPVibe's
   * verification run on 3.3.4). Placeholders count as "0"; one inside the host
   * skips the host check, since the real host is only known at dispatch.
   */
  public static function endpointError(string $url): ?string {
    $skeleton = (string) preg_replace('/\{\{[^}]+\}\}/', '0', $url);
    if (!filter_var($skeleton, FILTER_VALIDATE_URL)) {
      return __('Invalid endpoint URL.', 'flowsystems-webhook-actions');
    }

    if (self::requiresHttps() && !str_starts_with(strtolower($skeleton), 'https://')) {
      return __('The endpoint URL must start with https://. Webhooks are only delivered over HTTPS.', 'flowsystems-webhook-actions');
    }

    $templatedHost = (bool) preg_match('#^[a-z][a-z0-9+.-]*://[^/?\#]*\{\{#i', $url);
    if (!$templatedHost && !wp_http_validate_url($skeleton)) {
      return __('This endpoint URL cannot receive webhooks: it points to a private or local address, uses a port other than 80, 443 or 8080, or its host does not resolve.', 'flowsystems-webhook-actions');
    }

    return null;
  }

  /**
   * Whether endpoint URLs must use HTTPS. Shared by save-time validation and
   * the dispatcher so the two can never disagree.
   */
  public static function requiresHttps(): bool {
    /**
     * Filter whether HTTPS is required for webhook URLs.
     *
     * @param bool $require_https Whether to require HTTPS (default true)
     */
    return (bool) apply_filters('fswa_require_https', true);
  }

  /**
   * Does the URL carry at least one placeholder?
   */
  public static function hasPlaceholders(string $url): bool {
    return (bool) preg_match(self::PATTERN, $url);
  }

  /**
   * Drop top-level "__"-prefixed keys — placeholder feed-stock, not body.
   *
   * @param array<string, mixed> $payload
   * @return array<string, mixed>
   */
  public static function stripInternalKeys(array $payload): array {
    foreach (array_keys($payload) as $key) {
      if (is_string($key) && str_starts_with($key, '__')) {
        unset($payload[$key]);
      }
    }

    return $payload;
  }

  /**
   * custom_headers / url_params as the dispatcher reads them: a list of
   * {key, value} pairs. Accepts that shape, or a plain {name: value} map
   * (what a model tends to write), and drops anything else.
   *
   * @param mixed $value
   * @return array<int, array{key: string, value: string}>|null null when nothing usable
   */
  public static function normalizePairs(mixed $value): ?array {
    if (!is_array($value) || $value === []) {
      return null;
    }

    $pairs = [];

    foreach ($value as $key => $item) {
      if (is_array($item) && isset($item['key'])) {
        $pairs[] = ['key' => sanitize_text_field((string) $item['key']), 'value' => sanitize_text_field((string) ($item['value'] ?? ''))];
      } elseif (is_string($key) && (is_scalar($item) || $item === null)) {
        $pairs[] = ['key' => sanitize_text_field($key), 'value' => sanitize_text_field((string) $item)];
      }
    }

    $pairs = array_values(array_filter($pairs, static fn (array $p): bool => $p['key'] !== ''));

    return $pairs === [] ? null : $pairs;
  }
}
