<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Repositories\ActivityLogRepository;
use FlowSystems\WebhookActions\Services\ApiTokenService;

class ActivityLogService {
  private ActivityLogRepository $repository;

  // Field keys that should be redacted in context old/new values
  private const SENSITIVE_PATTERNS = ['auth_header', 'token', 'secret', 'password', 'key'];

  public function __construct() {
    $this->repository = new ActivityLogRepository();
  }

  /**
   * Log an admin action. Silently swallows all exceptions so a logging failure
   * never breaks the main request.
   *
   * @param string      $action      Dot-notation action: 'webhook.created', 'token.deleted', ...
   * @param string|null $objectType  Object category: 'webhook', 'token', 'settings', 'log', 'queue', 'cron'
   * @param int|null    $objectId    Primary-key ID of the affected object
   * @param string|null $objectName  Human-readable name of the object at time of action
   * @param array       $context     ['old' => [...], 'new' => [...]] for updates; ['meta' => [...]] otherwise
   */
  public function log(
    string $action,
    ?string $objectType = null,
    ?int $objectId = null,
    ?string $objectName = null,
    array $context = []
  ): void {
    try {
      $userId    = get_current_user_id() ?: null;
      $tokenData = $this->resolveToken();
      $tokenId   = $tokenData ? (int) $tokenData['id']  : null;
      $tokenHint = $tokenData ? ($tokenData['name'] ?? $tokenData['token_hint'] ?? null) : null;

      if (!empty($context)) {
        $context = $this->redactSensitive($context);
      }

      $this->repository->create([
        'user_id'     => $userId,
        'token_id'    => $tokenId,
        'token_hint'  => $tokenHint,
        'action'      => $action,
        'object_type' => $objectType,
        'object_id'   => $objectId,
        'object_name' => $objectName,
        'context'     => !empty($context) ? $context : null,
        'ip_address'  => $this->resolveIp(),
        'user_agent'  => isset($_SERVER['HTTP_USER_AGENT'])
          ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 512)
          : null,
      ]);
    } catch (\Throwable $e) {
      // Never let a logging failure break the caller
      error_log('[FSWA Activity Log] Failed to log action "' . $action . '": ' . $e->getMessage());
    }
  }

  /**
   * Delete entries older than the given number of days.
   */
  public function pruneOlderThan(int $days): int {
    $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));
    return $this->repository->deleteOlderThan($cutoff);
  }

  /**
   * Resolve the API token used in the current request, if any.
   */
  private function resolveToken(): ?array {
    // REST context only — global $wp_rest_server may not be set in CLI/cron
    if (!defined('REST_REQUEST') || !REST_REQUEST) {
      return null;
    }

    try {
      $request = \WP_REST_Server::get_raw_data() !== false ? new \WP_REST_Request() : null;

      // Build a minimal request from PHP superglobals so ApiTokenService can extract headers
      $request = new \WP_REST_Request(
        $_SERVER['REQUEST_METHOD'] ?? 'GET',
        ''
      );

      foreach (getallheaders() ?: [] as $name => $value) {
        $request->add_header($name, $value);
      }

      if (!empty($_GET['api_token'])) {
        $request->set_query_params($_GET);
      }

      $token = (new ApiTokenService())->validateFromRequest($request);
      return $token ?: null;
    } catch (\Throwable $e) {
      return null;
    }
  }

  /**
   * Resolve the caller's IP address.
   */
  private function resolveIp(): ?string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
      if (!empty($_SERVER[$key])) {
        $ip = trim(explode(',', sanitize_text_field(wp_unslash($_SERVER[$key])))[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
          return $ip;
        }
      }
    }
    return null;
  }

  /**
   * Recursively redact values whose keys match a sensitive pattern.
   */
  private function redactSensitive(array $data): array {
    array_walk_recursive($data, static function (&$value, $key) {
      foreach (self::SENSITIVE_PATTERNS as $pattern) {
        if (stripos((string) $key, $pattern) !== false) {
          $value = '[redacted]';
          return;
        }
      }
    });

    return $data;
  }
}
