<?php

namespace FlowSystems\WebhookActions\Api;

defined('ABSPATH') || exit;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use FlowSystems\WebhookActions\Repositories\ApiTokenRepository;
use FlowSystems\WebhookActions\Services\ApiTokenService;

class ApiTokensController extends WP_REST_Controller {
  protected $namespace = 'fswa/v1';
  protected $rest_base = 'tokens';

  private ApiTokenRepository $repository;
  private ApiTokenService    $service;

  public function __construct() {
    $this->repository = new ApiTokenRepository();
    $this->service    = new ApiTokenService();
  }

  /**
   * Register routes
   */
  public function registerRoutes(): void {
    register_rest_route($this->namespace, '/' . $this->rest_base, [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [$this, 'listTokens'],
        'permission_callback' => [$this, 'permissionsCheck'],
      ],
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [$this, 'createToken'],
        'permission_callback' => [$this, 'permissionsCheck'],
        'args'                => [
          'name'       => [
            'required'          => true,
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
          ],
          'scope'      => [
            'required' => true,
            'type'     => 'string',
            'enum'     => ['read', 'operational', 'full'],
            'default'  => 'read',
          ],
          'expires_at' => [
            'required' => false,
            'type'     => 'string',
          ],
        ],
      ],
    ]);

    register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)/rotate', [
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [$this, 'rotateToken'],
        'permission_callback' => [$this, 'permissionsCheck'],
        'args'                => [
          'id' => [
            'type'     => 'integer',
            'required' => true,
          ],
          'expires_at' => [
            'required' => false,
            'type'     => 'string',
          ],
        ],
      ],
    ]);

    register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)', [
      [
        'methods'             => WP_REST_Server::EDITABLE,
        'callback'            => [$this, 'updateToken'],
        'permission_callback' => [$this, 'permissionsCheck'],
        'args'                => [
          'id' => [
            'type'     => 'integer',
            'required' => true,
          ],
          'expires_at' => [
            'required' => false,
            'type'     => ['string', 'null'],
          ],
        ],
      ],
      [
        'methods'             => WP_REST_Server::DELETABLE,
        'callback'            => [$this, 'deleteToken'],
        'permission_callback' => [$this, 'permissionsCheck'],
        'args'                => [
          'id' => [
            'type'     => 'integer',
            'required' => true,
          ],
        ],
      ],
    ]);
  }

  /**
   * Token management is admin-only; API tokens cannot manage other tokens.
   */
  public function permissionsCheck($request): bool {
    return current_user_can('manage_options');
  }

  /**
   * GET /fswa/v1/tokens
   */
  public function listTokens(WP_REST_Request $request): WP_REST_Response {
    return rest_ensure_response($this->repository->getAll());
  }

  /**
   * POST /fswa/v1/tokens
   * Returns the plaintext token once — it is never stored and never logged.
   */
  public function createToken(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $name      = sanitize_text_field($request->get_param('name'));
    $scope     = $request->get_param('scope') ?: 'read';
    $expiresAt = $request->get_param('expires_at') ?: null;

    if (empty($name)) {
      return new WP_Error(
        'rest_missing_name',
        __('Token name is required.', 'flowsystems-webhook-actions'),
        ['status' => 400]
      );
    }

    if (!in_array($scope, ['read', 'operational', 'full'], true)) {
      return new WP_Error(
        'rest_invalid_scope',
        __('Invalid scope. Must be read, operational, or full.', 'flowsystems-webhook-actions'),
        ['status' => 400]
      );
    }

    $plain = $this->service->generateToken();
    $hash  = $this->service->hashToken($plain);
    $hint  = $this->service->extractHint($plain);

    $id = $this->repository->create([
      'name'       => $name,
      'scope'      => $scope,
      'token_hash' => $hash,
      'token_hint' => $hint,
      'expires_at' => $expiresAt,
    ]);

    if (!$id) {
      return new WP_Error(
        'rest_token_create_failed',
        __('Failed to create API token.', 'flowsystems-webhook-actions'),
        ['status' => 500]
      );
    }

    $token = $this->repository->find($id);

    return rest_ensure_response(array_merge($token, [
      'plaintext_token' => $plain,
    ]));
  }

  /**
   * POST /fswa/v1/tokens/{id}/rotate
   * Keeps same id/name/scope/expires_at; issues a new token_hash/token_hint.
   * Returns the new plaintext token once.
   */
  public function rotateToken(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $id = (int) $request->get_param('id');

    $token = $this->repository->find($id);

    if (!$token) {
      return new WP_Error(
        'rest_token_not_found',
        __('API token not found.', 'flowsystems-webhook-actions'),
        ['status' => 404]
      );
    }

    $plain = $this->service->generateToken();
    $hash  = $this->service->hashToken($plain);
    $hint  = $this->service->extractHint($plain);

    $result = $this->repository->rotate($id, $hash, $hint);

    if (!$result) {
      return new WP_Error(
        'rest_token_rotate_failed',
        __('Failed to rotate API token.', 'flowsystems-webhook-actions'),
        ['status' => 500]
      );
    }

    // Optionally update expiry at the same time (pass null to clear, omit to keep current)
    $expiresAtParam = $request->get_param('expires_at');
    if (array_key_exists('expires_at', $request->get_params())) {
      $this->repository->updateExpiry($id, $expiresAtParam ?: null);
    }

    $updated = $this->repository->find($id);

    return rest_ensure_response(array_merge($updated, [
      'plaintext_token' => $plain,
    ]));
  }

  /**
   * PATCH /fswa/v1/tokens/{id}
   * Currently supports updating expires_at only. Pass null to remove expiry.
   */
  public function updateToken(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $id = (int) $request->get_param('id');

    $token = $this->repository->find($id);

    if (!$token) {
      return new WP_Error(
        'rest_token_not_found',
        __('API token not found.', 'flowsystems-webhook-actions'),
        ['status' => 404]
      );
    }

    if (array_key_exists('expires_at', $request->get_params())) {
      $expiresAt = $request->get_param('expires_at');
      $this->repository->updateExpiry($id, $expiresAt ?: null);
    }

    return rest_ensure_response($this->repository->find($id));
  }

  /**
   * DELETE /fswa/v1/tokens/{id}
   */
  public function deleteToken(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $id = (int) $request->get_param('id');

    $token = $this->repository->find($id);

    if (!$token) {
      return new WP_Error(
        'rest_token_not_found',
        __('API token not found.', 'flowsystems-webhook-actions'),
        ['status' => 404]
      );
    }

    $result = $this->repository->delete($id);

    if (!$result) {
      return new WP_Error(
        'rest_token_delete_failed',
        __('Failed to delete API token.', 'flowsystems-webhook-actions'),
        ['status' => 500]
      );
    }

    return rest_ensure_response(['deleted' => true, 'id' => $id]);
  }
}
