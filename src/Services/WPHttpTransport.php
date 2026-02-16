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
   * Send HTTP POST request with JSON payload
   *
   * @param string $url The destination URL
   * @param array<string, mixed> $payload Data to send as JSON
   * @param array<string, string> $headers HTTP headers
   * @return array<string, mixed>|WP_Error WordPress HTTP response or WP_Error
   */
  public function send(string $url, array $payload, array $headers = []) {
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

    $args = [
      'method' => 'POST',
      'headers' => $headers,
      'body' => $jsonPayload,
      'timeout' => $timeout,
      'connect_timeout' => $connectTimeout,
      'user-agent' => 'WordPress/FlowSystemsWebhookActions/' . App::VERSION,
    ];

    /**
     * Filter the HTTP request arguments before sending.
     *
     * @param array  $args    The wp_remote_post arguments
     * @param string $url     The destination URL
     * @param array  $payload The payload data being sent
     */
    $args = apply_filters('fswa_http_args', $args, $url, $payload);

    return wp_remote_post($url, $args);
  }
}
