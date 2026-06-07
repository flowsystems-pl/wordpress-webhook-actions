<?php

namespace FlowSystems\WebhookActions\Api;

defined('ABSPATH') || exit;

use WP_REST_Request;
use WP_Error;
use FlowSystems\WebhookActions\Services\ApiTokenService;

class AuthHelper {
  const SCOPE_READ        = 'read';
  const SCOPE_OPERATIONAL = 'operational';
  const SCOPE_FULL        = 'full';
  // Agent: full write power (CRUD webhooks/credentials) but may NEVER reveal
  // secret values. Ranks equal to full for write-gating; excluded from
  // canRevealSecrets(). Intended for the AI assistant token.
  const SCOPE_AGENT       = 'agent';

  /**
   * Dual-auth permission check.
   *
   * - WordPress admins (manage_options) always pass regardless of scope.
   * - Valid API tokens are checked for scope.
   *
   * Returns true on success, WP_Error on failure.
   * Returning WP_Error from a permission_callback sends that error directly
   * instead of a generic 401/403.
   */
  public static function dualAuth(WP_REST_Request $request, string $requiredScope = self::SCOPE_READ): bool|WP_Error {
    // Admin session always passes
    if (current_user_can('manage_options')) {
      return true;
    }

    $service = new ApiTokenService();
    $token   = $service->validateFromRequest($request);

    if ($token === false) {
      return new WP_Error(
        'rest_forbidden',
        __('Authentication required. Provide a valid API token via X-FSWA-Token header, Authorization: Bearer token, or api_token query parameter.', 'flowsystems-webhook-actions'),
        ['status' => 401]
      );
    }

    if (!$service->tokenHasScope($token, $requiredScope)) {
      return new WP_Error(
        'rest_forbidden',
        sprintf(
          /* translators: %s: required scope name */
          __('Insufficient token scope. This endpoint requires "%s" scope or higher.', 'flowsystems-webhook-actions'),
          $requiredScope
        ),
        ['status' => 403]
      );
    }

    return true;
  }

  /**
   * Check if the current request has at least the given scope (or is an admin session).
   * Use this for response shaping — does not send errors.
   */
  public static function requestHasScope(WP_REST_Request $request, string $scope): bool {
    if (current_user_can('manage_options')) {
      return true;
    }

    $service = new ApiTokenService();
    $token   = $service->validateFromRequest($request);

    if ($token === false) {
      return false;
    }

    return $service->tokenHasScope($token, $scope);
  }

  /**
   * Whether the current request may see decrypted secrets / the legacy
   * auth_header in plaintext.
   *
   * True only for WP admin sessions or a token whose stored scope is strictly
   * 'full'. The 'agent' scope is intentionally excluded even though it ranks as
   * full for write-gating — this is the single chokepoint that keeps secrets
   * away from the AI assistant token.
   */
  public static function canRevealSecrets(WP_REST_Request $request): bool {
    if (current_user_can('manage_options')) {
      return true;
    }

    $service = new ApiTokenService();
    $token   = $service->validateFromRequest($request);

    if ($token === false) {
      return false;
    }

    return ($token['scope'] ?? '') === self::SCOPE_FULL;
  }
}
