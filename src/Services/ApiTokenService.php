<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

use WP_REST_Request;
use FlowSystems\WebhookActions\Repositories\ApiTokenRepository;

class ApiTokenService {
  private ApiTokenRepository $repository;

  public function __construct() {
    $this->repository = new ApiTokenRepository();
  }

  /**
   * Generate a new plaintext token: 'fswa_' + 40 hex chars = 45 chars total
   */
  public function generateToken(): string {
    return 'fswa_' . bin2hex(random_bytes(20));
  }

  /**
   * Hash a plaintext token using SHA-256
   */
  public function hashToken(string $plain): string {
    return hash('sha256', $plain);
  }

  /**
   * Extract the first 13 characters of the plaintext token as a hint
   */
  public function extractHint(string $plain): string {
    return substr($plain, 0, 13);
  }

  /**
   * Validate a token from a REST request.
   * Tries: X-FSWA-Token header → Authorization: Bearer → ?api_token= query param.
   *
   * @return array|false Token row on success, false on failure
   */
  public function validateFromRequest(WP_REST_Request $request): array|false {
    $plain = null;

    // 1. X-FSWA-Token header (most reliable — never stripped by server software)
    $headerToken = $request->get_header('X-FSWA-Token');
    if (!empty($headerToken)) {
      $plain = trim($headerToken);
    }

    // 2. Authorization: Bearer <token>
    if ($plain === null) {
      $authHeader = $request->get_header('Authorization');
      if (!empty($authHeader) && str_starts_with($authHeader, 'Bearer ')) {
        $plain = trim(substr($authHeader, 7));
      }
    }

    // 3. ?api_token= query param (last resort)
    if ($plain === null) {
      $queryToken = $request->get_param('api_token');
      if (!empty($queryToken)) {
        $plain = trim($queryToken);
      }
    }

    if ($plain === null || $plain === '') {
      return false;
    }

    $hash = $this->hashToken($plain);
    $token = $this->repository->findByHash($hash);

    if (!$token) {
      return false;
    }

    // Check expiry
    if ($token['expires_at'] !== null && strtotime($token['expires_at']) <= time()) {
      return false;
    }

    $this->repository->touchLastUsed((int) $token['id']);

    return $token;
  }

  /**
   * Check if a token row has the required scope.
   * Hierarchy: full > operational > read
   */
  public function tokenHasScope(array $token, string $required): bool {
    $hierarchy = ['read' => 1, 'operational' => 2, 'full' => 3];

    $tokenLevel    = $hierarchy[$token['scope']] ?? 0;
    $requiredLevel = $hierarchy[$required] ?? 999;

    return $tokenLevel >= $requiredLevel;
  }
}
