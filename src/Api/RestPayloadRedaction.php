<?php

namespace FlowSystems\WebhookActions\Api;

defined('ABSPATH') || exit;

use WP_REST_Request;
use WP_REST_Response;
use FlowSystems\WebhookActions\Services\Ai\PayloadRedactor;

/**
 * Scrubs payload data out of REST responses for callers that are not the
 * admin screen.
 *
 * The abilities redact every result before an agent sees it, but the REST
 * routes behind the admin SPA handed the same data out raw — and an agent
 * holding an application password is an administrator to WordPress, so it
 * could walk around the abilities and read captured payloads, delivery logs
 * and response bodies in full (WPVibe's verification run got a WooCommerce
 * order key back from the reset-capture route that way).
 *
 * The admin screen keeps raw data: an administrator in the browser is looking
 * at their own site. Everyone else — application passwords, API tokens,
 * internal rest_do_request() calls — gets the payload fields scrubbed with the
 * same rule the abilities use. Only payload-bearing fields are touched, so
 * webhook settings still round-trip through GET → PUT unchanged.
 */
class RestPayloadRedaction {
  private const ROUTE_PREFIX = '/fswa/v1/';

  /** Response keys whose values are captured or delivered site data. */
  private const PAYLOAD_KEYS = [
    'example_payload',
    'example',
    'payload',
    'original_payload',
    'request_payload',
    'request_headers',
    'response_body',
    'attempt_history',
    'execution_json',
    'context',
    'transcript_json',
  ];

  public function register(): void {
    add_filter('rest_post_dispatch', [$this, 'filterResponse'], 10, 3);
  }

  /**
   * @param mixed $result
   * @param mixed $server
   * @param mixed $request
   * @return mixed
   */
  public function filterResponse($result, $server, $request) {
    if (!$result instanceof WP_REST_Response || !$request instanceof WP_REST_Request) {
      return $result;
    }
    if (strpos($request->get_route(), self::ROUTE_PREFIX) !== 0 || self::isAdminScreen($request)) {
      return $result;
    }

    $data = $result->get_data();
    if (is_array($data)) {
      $result->set_data(self::scrub($data));
    }
    return $result;
  }

  /**
   * The admin SPA authenticates with the logged-in cookie plus a wp_rest
   * nonce; nothing else can produce that pair. An application password wins
   * even when a nonce is sent along.
   */
  public static function isAdminScreen(WP_REST_Request $request): bool {
    if (function_exists('rest_get_authenticated_app_password') && rest_get_authenticated_app_password() !== null) {
      return false;
    }
    if (!is_user_logged_in()) {
      return false;
    }

    $nonce = $request->get_header('x_wp_nonce') ?: $request->get_param('_wpnonce');
    return is_string($nonce) && $nonce !== '' && wp_verify_nonce($nonce, 'wp_rest') !== false;
  }

  /**
   * @param array<mixed> $data
   * @return array<mixed>
   */
  private static function scrub(array $data): array {
    foreach ($data as $key => $value) {
      if (is_string($key) && in_array($key, self::PAYLOAD_KEYS, true)) {
        $data[$key] = PayloadRedactor::redactValue($value);
      } elseif (is_array($value)) {
        $data[$key] = self::scrub($value);
      }
    }
    return $data;
  }
}
