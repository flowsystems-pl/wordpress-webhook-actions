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
    $scheme   = strtolower((string) wp_parse_url($skeleton, PHP_URL_SCHEME));
    if (!filter_var($skeleton, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
      return __('Invalid endpoint URL.', 'flowsystems-webhook-actions');
    }

    if (self::requiresHttps() && $scheme !== 'https') {
      return __('Plain http:// URLs only work on a local development site. Use https:// here, or mark this site as local by adding define( \'WP_ENVIRONMENT_TYPE\', \'local\' ); to wp-config.php.', 'flowsystems-webhook-actions');
    }

    $templatedHost = (bool) preg_match('#^[a-z][a-z0-9+.-]*://[^/?\#]*\{\{#i', $url);
    if (!$templatedHost && !self::hostAllowed($skeleton)) {
      return __('This endpoint URL only works on a local development site: it points to a private or local address, uses a port other than 80, 443 or 8080, or its host does not resolve. Use a public https:// URL here, or mark this site as local by adding define( \'WP_ENVIRONMENT_TYPE\', \'local\' ); to wp-config.php.', 'flowsystems-webhook-actions');
    }

    return null;
  }

  /**
   * A local development site may send to http://, to localhost, Docker and LAN
   * addresses, and to any port — a local n8n on :5678, a dev server on :3000.
   * Off everywhere else: on a real server a webhook (or an AI agent creating
   * one) could otherwise reach the server's own internal services.
   *
   * Same gate as LocalDevHttp: wp_get_environment_type() is 'production' unless
   * the developer sets WP_ENVIRONMENT_TYPE on their own machine.
   */
  public static function isLocalEnvironment(): bool {
    /**
     * Filter whether endpoint URLs may use http://, private or local hosts and
     * any port. Default: true only when wp_get_environment_type() is 'local'.
     *
     * @param bool $allow Whether local-only endpoints are allowed.
     */
    return (bool) apply_filters('fswa_allow_local_endpoints', wp_get_environment_type() === 'local');
  }

  /**
   * Whether endpoint URLs must use HTTPS. Shared by save-time validation and
   * the dispatcher so the two can never disagree.
   */
  public static function requiresHttps(): bool {
    /**
     * Filter whether HTTPS is required for webhook URLs.
     *
     * @param bool $require_https Whether to require HTTPS (default true, false on a local site)
     */
    return (bool) apply_filters('fswa_require_https', !self::isLocalEnvironment());
  }

  /**
   * Host and port check for an outbound URL: WordPress's own SSRF guard
   * (public host, port 80/443/8080), skipped on a local development site.
   */
  public static function hostAllowed(string $url): bool {
    return self::isLocalEnvironment() || (bool) wp_http_validate_url($url);
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
