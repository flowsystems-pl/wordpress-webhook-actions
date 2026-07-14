<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

use WP_Error;
use WP_REST_Request;

/**
 * Describes THIS site's own REST API routes from their self-declared schema, so
 * the AI Builder can read an internal endpoint's real contract (which arguments
 * exist, their types, and which are REQUIRED) instead of recalling it from
 * training data. Backs the `get_rest_route_schema` read ability — the same
 * read-before-plan discipline get_trigger_schema enforces for payload shapes,
 * applied to endpoint requirements (e.g. POST /wp/v2/users requires username,
 * email AND password).
 *
 * Implementation: an internal OPTIONS dispatch via rest_do_request() — no HTTP,
 * no auth or TLS concerns, and it works for any registered route (core, this
 * plugin, WooCommerce, anything). External APIs are out of scope by design.
 */
class RestRouteInspector {

  /** Max args returned per route (some plugin routes declare dozens). */
  private const ARGS_LIMIT = 60;

  /** Max characters of a single argument description. */
  private const DESC_LIMIT = 200;

  /**
   * Describe a route+method: its arguments with type/required/description.
   *
   * @return array<string, mixed>|WP_Error
   */
  public function describe(string $route, string $method = 'POST'): array|WP_Error {
    $route  = $this->normalizeRoute($route);
    $method = strtoupper(trim($method)) ?: 'POST';
    if ($route === '') {
      return new WP_Error('fswa_invalid_route', __('route is required, e.g. "/wp/v2/users".', 'flowsystems-webhook-actions'), ['status' => 400]);
    }

    $response  = rest_do_request(new WP_REST_Request('OPTIONS', $route));
    $data      = (array) $response->get_data();
    $endpoints = (array) ($data['endpoints'] ?? []);
    // The REST server answers OPTIONS on an unmatched route with an empty 200
    // (not an error), so absence of endpoint descriptions IS the 404 signal.
    if ($response->is_error() || $endpoints === []) {
      return new WP_Error(
        'fswa_route_not_found',
        sprintf(
          /* translators: %s: route path */
          __('No REST route matches "%s" on this site. Route paths are relative to /wp-json, e.g. "/wp/v2/users". This only describes THIS site\'s own REST API — not external endpoints.', 'flowsystems-webhook-actions'),
          $route
        ),
        ['status' => 404]
      );
    }
    $available = [];
    $matched   = null;
    foreach ($endpoints as $endpoint) {
      $methods   = array_map('strtoupper', (array) ($endpoint['methods'] ?? []));
      $available = array_values(array_unique(array_merge($available, $methods)));
      if ($matched === null && in_array($method, $methods, true)) {
        $matched = $endpoint;
      }
    }

    if ($matched === null) {
      return new WP_Error(
        'fswa_method_not_allowed',
        sprintf(
          /* translators: 1: HTTP method, 2: route path, 3: comma-separated allowed methods */
          __('%1$s is not supported on %2$s. Supported methods: %3$s.', 'flowsystems-webhook-actions'),
          $method,
          $route,
          implode(', ', $available)
        ),
        ['status' => 405]
      );
    }

    $args = $this->trimArgs((array) ($matched['args'] ?? []));

    return [
      'route'           => $route,
      'method'          => $method,
      'methods_allowed' => $available,
      // Quick-scan list: every one of these MUST be satisfied in the outgoing body.
      'required'        => array_keys(array_filter($args, static fn(array $a): bool => !empty($a['required']))),
      'args'            => $args,
    ];
  }

  /**
   * Accept "/wp/v2/users", "wp/v2/users", or a full URL to this site's REST API
   * ("https://site/wp-json/wp/v2/users") and reduce to the internal route path.
   */
  private function normalizeRoute(string $route): string {
    $route = trim($route);
    if ($route === '') {
      return '';
    }
    if (preg_match('#^https?://#i', $route)) {
      $path  = (string) wp_parse_url($route, PHP_URL_PATH);
      $route = (string) preg_replace('#^.*?/wp-json#', '', $path);
    }
    // Tolerate the index prefix ("/wp-json/wp/v2/users") pasted as a path too.
    $route = (string) preg_replace('#^/?wp-json/#', '/', $route);
    return '/' . ltrim($route, '/');
  }

  /**
   * Reduce each self-declared arg to what the agent needs (type, required,
   * short description, enum/default), required args first, capped in count.
   *
   * @param array<string, mixed> $args
   * @return array<string, array<string, mixed>>
   */
  private function trimArgs(array $args): array {
    $trimmed = [];
    foreach ($args as $name => $spec) {
      if (!is_array($spec)) {
        continue;
      }
      $entry = ['required' => !empty($spec['required'])];
      if (isset($spec['type'])) {
        $entry['type'] = is_array($spec['type']) ? implode('|', $spec['type']) : (string) $spec['type'];
      }
      $description = (string) ($spec['description'] ?? '');
      if ($description !== '') {
        $entry['description'] = mb_substr($description, 0, self::DESC_LIMIT);
      }
      if (!empty($spec['enum']) && is_array($spec['enum'])) {
        $entry['enum'] = array_values($spec['enum']);
      }
      if (array_key_exists('default', $spec) && $spec['default'] !== null) {
        $entry['default'] = $spec['default'];
      }
      $trimmed[(string) $name] = $entry;
    }

    // Required args first so they survive the cap and lead the listing.
    uasort($trimmed, static fn(array $a, array $b): int => (int) $b['required'] <=> (int) $a['required']);

    return array_slice($trimmed, 0, self::ARGS_LIMIT, true);
  }
}
