<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\App;
use WP_Error;

/**
 * HTTP Transport Layer
 * Handles HTTP requests using WordPress HTTP API
 */
class WPHttpTransport {
  /**
   * Send HTTP request with JSON payload
   *
   * @param string $url The destination URL
   * @param array<string, mixed> $payload Data to send as JSON
   * @param array<string, string> $headers HTTP headers
   * @param string $method HTTP method
   * @return array<string, mixed>|WP_Error WordPress HTTP response or WP_Error
   */
  public function send(string $url, array $payload, array $headers = [], string $method = 'POST') {
    $jsonPayload = json_encode($payload);
    if ($jsonPayload === false) {
      return new WP_Error('json_encode_failed', 'Failed to encode payload as JSON');
    }

    /**
     * Filter the HTTP request timeout in seconds.
     *
     * @param int $timeout Request timeout in seconds (default 5)
     */
    $timeout = (int) apply_filters('fswa_http_timeout', 5);

    /**
     * Filter the HTTP connection timeout in seconds.
     *
     * @param int $connect_timeout Connection timeout in seconds (default 2)
     */
    $connectTimeout = (int) apply_filters('fswa_http_connect_timeout', 2);

    $method = strtoupper($method);
    $bodyMethods = ['POST', 'PUT', 'PATCH'];

    $args = [
      'method'          => $method,
      'headers'         => $headers,
      'timeout'         => $timeout,
      'connect_timeout' => $connectTimeout,
      'user-agent'      => 'WordPress/FlowSystemsWebhookActions/' . App::VERSION,
    ];

    if (in_array($method, $bodyMethods, true)) {
      $args['body'] = $jsonPayload;
    } else {
      unset($args['headers']['Content-Type']);
    }

    /**
     * Filter the HTTP request arguments before sending.
     *
     * @param array  $args    The wp_remote_request arguments
     * @param string $url     The destination URL
     * @param array  $payload The payload data being sent
     */
    $args = apply_filters('fswa_http_args', $args, $url, $payload);

    return wp_remote_request($url, $args);
  }
}
