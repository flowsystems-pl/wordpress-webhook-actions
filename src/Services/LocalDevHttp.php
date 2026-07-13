<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

/**
 * Local-development convenience: skip TLS verification for the plugin's own
 * loopback HTTP calls (a webhook — or an AI Builder probe/test — targeting THIS
 * site's own REST API) when the site is a local environment whose self-signed
 * certificate the PHP process doesn't trust. Without this, internal automations
 * (e.g. form submission → POST wp-json/wp/v2/users) fail with cURL 60 on a
 * typical local box.
 *
 * SAFE TO SHIP — off by default, gated by two independent guards that must BOTH
 * hold, so it can never relax TLS in production or for third-party endpoints:
 *   1. wp_get_environment_type() must be 'local'. It defaults to 'production' and
 *      is set by the developer on their own machine (WP_ENVIRONMENT_TYPE) — the
 *      canonical WordPress signal, never present on a real server.
 *   2. The target host must equal the site's OWN host. External endpoints
 *      (Stripe, HubSpot, n8n, …) ALWAYS keep full TLS verification, even locally.
 *
 * Runs through the same `fswa_http_args` filter as real deliveries and probes, so
 * one guard covers every outbound call. Overridable via `fswa_local_skip_sslverify`.
 */
class LocalDevHttp {

  public function register(): void {
    add_filter('fswa_http_args', [$this, 'maybeSkipSslVerify'], 10, 2);
  }

  /**
   * @param array<string, mixed> $args
   * @return array<string, mixed>
   */
  public function maybeSkipSslVerify(array $args, string $url): array {
    $skip = self::shouldSkip(
      wp_get_environment_type(),
      (string) wp_parse_url(home_url(), PHP_URL_HOST),
      (string) wp_parse_url($url, PHP_URL_HOST)
    );

    /**
     * Filter the local self-signed TLS-skip decision. Default: true only when the
     * environment is 'local' AND the request targets the site's own host.
     *
     * @param bool                 $skip Whether to skip TLS verification.
     * @param string               $url  The outbound request URL.
     * @param array<string, mixed> $args The HTTP args about to be used.
     */
    if (apply_filters('fswa_local_skip_sslverify', $skip, $url, $args)) {
      $args['sslverify'] = false;
    }

    return $args;
  }

  /**
   * Pure decision: skip TLS verification only for a LOCAL environment calling its
   * OWN host (self-signed loopback). Everything else keeps full verification.
   */
  public static function shouldSkip(string $envType, string $ownHost, string $targetHost): bool {
    if ($envType !== 'local') {
      return false;
    }
    return $ownHost !== '' && $targetHost !== '' && strcasecmp($ownHost, $targetHost) === 0;
  }
}
