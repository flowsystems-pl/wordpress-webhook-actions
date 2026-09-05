<?php

namespace FlowSystems\WebhookActions\Services\Ai;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Services\HookDiscoveryService;

/**
 * Asks our API what a trigger's payload looks like, for sites with no capture
 * of their own.
 *
 * Without this, a build against an uncaptured trigger cannot proceed: the agent
 * spends a whole turn discovering that nothing can be done and the user is sent
 * away to fire the event by hand. On triggers that are awkward to fire on demand
 * — a refunded order, a cancelled subscription, a deleted user — a meaningful
 * share never come back.
 *
 * Three rules, all of them load-bearing:
 *
 * 1. **Hosted only.** The lookup is part of what our AI credits pay for. A site
 *    running its own provider key is paying its own bills and keeps the existing
 *    behaviour.
 *
 * 2. **Fail open, always.** This sits on `get_trigger_schema`, the most-called
 *    read, inside a loop capped at three iterations behind a 120s provider
 *    timeout. Any failure — unreachable, slow, malformed, rate-limited — must
 *    land back on exactly what the plugin did before this existed. A payload
 *    library that can break a build is worse than no payload library.
 *
 * 3. **Cache the miss too.** A hook we cannot answer is the common case on a
 *    site running plugins our fixtures do not, and without a negative cache
 *    every read round pays the round trip again.
 */
class PayloadLibrary {
  /** Site option; set to '0' to stop consulting the library entirely. */
  public const OPTION_ENABLED = 'fswa_payload_library_enabled';

  private const TRANSIENT_PREFIX = 'fswa_paylib_';

  /**
   * Set when a request fails at the transport level, to stop the next one being
   * attempted for a while.
   *
   * Without it, an unreachable API costs the FULL timeout per uncaptured
   * trigger — and SchemasController resolves every trigger on a webhook when the
   * admin screen loads, so a down API would turn a page load into 5s times the
   * number of triggers. A miss is not a failure and never trips this; only a
   * transport error does.
   */
  private const TRANSIENT_DOWN = 'fswa_paylib_down';

  private const TTL_DOWN = 5 * MINUTE_IN_SECONDS;

  /** Memoized per request: isAvailable() resolves the transport, which is not free. */
  private static ?bool $available = null;

  /** Pro's licence key option, read directly so free does not depend on Pro. */
  private const OPT_PRO_LICENSE_KEY = 'fswa_pro_license_key';

  /**
   * A card only changes when we re-harvest, which happens in batches. A week is
   * long enough that the library costs a site almost no requests.
   */
  private const TTL_HIT = WEEK_IN_SECONDS;

  /**
   * Shorter than a hit because a miss is the answer that we expect to change:
   * misses drive the harvest queue, so the hook a site asked for today is
   * exactly the one likeliest to exist next week.
   */
  private const TTL_MISS = DAY_IN_SECONDS;

  /**
   * Deliberately far below the plugin's default HTTP timeout. This call sits in
   * front of an agent read; a lookup that takes longer than this has already
   * cost more than the stall it exists to prevent.
   */
  private const TIMEOUT = 5;

  private const JSON_HEADERS = [
    'content-type' => 'application/json',
    'accept'       => 'application/json',
  ];

  /**
   * The library's example payload for a hook, or null when we have none — which
   * includes every failure mode, by design.
   *
   * @return array{payload: array, unsafe: array, caveat: string, captured_from: array, confidence: string}|null
   */
  public function lookup(string $hook): ?array {
    if ($hook === '' || !$this->isAvailable()) {
      return null;
    }

    $cacheKey = self::TRANSIENT_PREFIX . md5($hook);
    $cached   = get_transient($cacheKey);

    if (is_array($cached)) {
      return $cached['hit'] ?? null;
    }

    $result = $this->fetch($hook);

    set_transient(
      $cacheKey,
      ['hit' => $result],
      $result === null ? self::TTL_MISS : self::TTL_HIT
    );

    return $result;
  }

  /**
   * Whether this site may consult the library at all: switched on, and running
   * the agent on our hosted credits rather than its own provider key.
   *
   * Checked here as well as server-side. The server's check is the one that
   * counts; this one keeps a BYOK site from making a request that can only be
   * refused, on the hot path of every schema read.
   */
  public function isAvailable(): bool {
    if (self::$available !== null) {
      return self::$available;
    }

    return self::$available = $this->computeAvailability();
  }

  private function computeAvailability(): bool {
    if (get_option(self::OPTION_ENABLED, '1') !== '1') {
      return false;
    }

    if (get_transient(self::TRANSIENT_DOWN)) {
      return false;
    }

    if ($this->licenseKey() === '') {
      return false;
    }

    $transport = (new LlmTransport())->resolve();

    return $transport !== null
      && in_array($transport->id(), ['hosted', 'hosted_trial'], true);
  }

  /**
   * @return array<string, mixed>|null
   */
  private function fetch(string $hook): ?array {
    $response = wp_remote_post(TrialClient::apiBase() . '/api/knowledge/trigger-payload', [
      'timeout' => self::TIMEOUT,
      'headers' => self::JSON_HEADERS,
      'body'    => wp_json_encode([
        'license_key' => $this->licenseKey(),
        'site_url'    => home_url(),
        'hook'        => $hook,
        'context'     => $this->context($hook),
      ]),
    ]);

    if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
      // Transport-level failure, not a miss: back off so the next uncaptured
      // trigger on this page load does not pay the timeout again.
      $this->markUnreachable();

      return null;
    }

    $data = json_decode((string) wp_remote_retrieve_body($response), true);

    if (!is_array($data)) {
      $this->markUnreachable();

      return null;
    }

    // A well-formed "we don't have this hook" is a MISS, and must not back off:
    // it is the expected answer for a site running plugins our fixtures do not.
    if (($data['source'] ?? null) !== 'library' || !is_array($data['payload'] ?? null)) {
      return null;
    }

    return [
      'payload'       => $data['payload'],
      'unsafe'        => is_array($data['unsafe'] ?? null) ? $data['unsafe'] : [],
      'caveat'        => (string) ($data['caveat'] ?? ''),
      'verify'        => (string) ($data['verify'] ?? ''),
      'captured_from' => is_array($data['captured_from'] ?? null) ? $data['captured_from'] : [],
      'confidence'    => (string) ($data['confidence'] ?? 'low'),
    ];
  }

  /**
   * What the API needs to judge how well its capture matches this site: the
   * WordPress version, and the version of the plugin that OWNS this hook.
   *
   * Only that one plugin. Sending the site's whole plugin list would tell the
   * API more about the customer's site than the question requires, and the
   * owning plugin's version is the only one that bears on whether a hook's
   * payload shape matches.
   *
   * @return array<string, string>
   */
  private function context(string $hook): array {
    $context = ['wp_version' => get_bloginfo('version')];

    $slug = (new HookDiscoveryService())->discoverWithRuntimeHooks()[$hook] ?? null;

    if (is_string($slug) && $slug !== '' && $slug !== 'wordpress') {
      $version = $this->pluginVersion($slug);

      if ($version !== '') {
        $context['source_version'] = $version;
      }
    }

    return $context;
  }

  private function pluginVersion(string $slug): string {
    if (!function_exists('get_plugins')) {
      require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    foreach (get_plugins() as $file => $data) {
      if (dirname($file) === $slug) {
        return (string) ($data['Version'] ?? '');
      }
    }

    return '';
  }

  /**
   * The licence this site is known by: a Pro key when one is installed, else the
   * anonymous trial key. Mirrors PublishBuildClient::licenseKey().
   */
  private function licenseKey(): string {
    $pro = trim((string) get_option(self::OPT_PRO_LICENSE_KEY, ''));

    if ($pro !== '') {
      return $pro;
    }

    return (new TrialClient())->key();
  }

  private function markUnreachable(): void {
    set_transient(self::TRANSIENT_DOWN, 1, self::TTL_DOWN);
    self::$available = false;
  }

  /** Drop every cached lookup — used when a site switches AI source or licence. */
  public static function flush(): void {
    global $wpdb;

    self::$available = null;
    delete_transient(self::TRANSIENT_DOWN);

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query(
      $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like('_transient_' . self::TRANSIENT_PREFIX) . '%',
        $wpdb->esc_like('_transient_timeout_' . self::TRANSIENT_PREFIX) . '%'
      )
    );
  }
}
