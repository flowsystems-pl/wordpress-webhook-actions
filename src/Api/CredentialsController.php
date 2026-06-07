<?php

namespace FlowSystems\WebhookActions\Api;

defined('ABSPATH') || exit;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use FlowSystems\WebhookActions\Repositories\CredentialRepository;
use FlowSystems\WebhookActions\Services\CredentialCipher;
use FlowSystems\WebhookActions\Services\ActivityLogService;

/**
 * Credentials Vault REST controller.
 *
 * The vault is write-only over REST: secrets are accepted on create/update but
 * NEVER returned to any caller (not even admins or `full` tokens). Responses
 * carry only metadata plus a masked hint. The plaintext secret is decrypted
 * solely at dispatch time by the Dispatcher.
 */
class CredentialsController extends WP_REST_Controller {
  protected $namespace = 'fswa/v1';
  protected $rest_base = 'credentials';

  private const TYPES = ['bearer', 'basic', 'api_key', 'custom'];

  private CredentialRepository $repository;
  private CredentialCipher     $cipher;
  private ActivityLogService   $activityLog;

  public function __construct() {
    $this->repository  = new CredentialRepository();
    $this->cipher      = new CredentialCipher();
    $this->activityLog = new ActivityLogService();
  }

  public function registerRoutes(): void {
    register_rest_route($this->namespace, '/' . $this->rest_base, [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [$this, 'listCredentials'],
        'permission_callback' => [$this, 'permissionsCheck'],
      ],
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [$this, 'createCredential'],
        'permission_callback' => [$this, 'permissionsCheck'],
        'args'                => $this->writeArgs(),
      ],
    ]);

    register_rest_route($this->namespace, '/' . $this->rest_base . '/key-status', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [$this, 'keyStatus'],
        'permission_callback' => [$this, 'permissionsCheck'],
      ],
    ]);

    register_rest_route($this->namespace, '/' . $this->rest_base . '/reencrypt', [
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [$this, 'reencrypt'],
        'permission_callback' => [$this, 'permissionsCheck'],
      ],
    ]);

    register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [$this, 'getCredential'],
        'permission_callback' => [$this, 'permissionsCheck'],
        'args'                => ['id' => ['type' => 'integer', 'required' => true]],
      ],
      [
        'methods'             => WP_REST_Server::EDITABLE,
        'callback'            => [$this, 'updateCredential'],
        'permission_callback' => [$this, 'permissionsCheck'],
        'args'                => array_merge(['id' => ['type' => 'integer', 'required' => true]], $this->writeArgs(false)),
      ],
      [
        'methods'             => WP_REST_Server::DELETABLE,
        'callback'            => [$this, 'deleteCredential'],
        'permission_callback' => [$this, 'permissionsCheck'],
        'args'                => [
          'id'    => ['type' => 'integer', 'required' => true],
          'force' => ['type' => 'boolean', 'required' => false, 'default' => false],
        ],
      ],
    ]);
  }

  /**
   * Full scope (or WP admin). Agent tokens rank as full, so they can manage the
   * vault — but they can never read a secret back, because no read path exists.
   */
  public function permissionsCheck(WP_REST_Request $request): bool|WP_Error {
    return AuthHelper::dualAuth($request, AuthHelper::SCOPE_FULL);
  }

  private function writeArgs(bool $requireForCreate = true): array {
    return [
      'name'        => [
        'required'          => $requireForCreate,
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
      ],
      'type'        => [
        'required' => $requireForCreate,
        'type'     => 'string',
        'enum'     => self::TYPES,
      ],
      'header_name' => [
        'required'          => false,
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
      ],
      // Secret material — accepted, never returned.
      'secret'      => ['required' => false, 'type' => 'string'],
      'username'    => ['required' => false, 'type' => 'string'],
      'password'    => ['required' => false, 'type' => 'string'],
    ];
  }

  /**
   * GET /credentials
   */
  public function listCredentials(WP_REST_Request $request): WP_REST_Response {
    return rest_ensure_response($this->repository->getAll());
  }

  /**
   * GET /credentials/{id}
   */
  public function getCredential(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $credential = $this->repository->find((int) $request->get_param('id'));

    if (!$credential) {
      return $this->notFound();
    }

    return rest_ensure_response($credential);
  }

  /**
   * GET /credentials/key-status
   * Reports the encryption-key posture so the UI can guide the FSWA_SECRET_KEY
   * migration.
   */
  public function keyStatus(WP_REST_Request $request): WP_REST_Response {
    $usingConstant = $this->cipher->usingConstant();
    $dbKeyPresent  = $this->cipher->dbKeyPresent();

    $total         = 0;
    $undecryptable = 0;
    foreach ($this->repository->allWithSecrets() as $row) {
      $total++;
      if ($this->cipher->decrypt((string) ($row['secret_ciphertext'] ?? '')) === null) {
        $undecryptable++;
      }
    }

    return rest_ensure_response([
      'key_source'      => $this->cipher->keySource(),
      'using_constant'  => $usingConstant,
      'db_key_present'  => $dbKeyPresent,
      // Fully hardened only when the constant is the sole key.
      'fully_protected' => $usingConstant && !$dbKeyPresent,
      // Constant set but DB key still around → re-wrap to finish.
      'needs_migration' => $usingConstant && $dbKeyPresent && $total > 0,
      'total'           => $total,
      'undecryptable'   => $undecryptable,
    ]);
  }

  /**
   * POST /credentials/reencrypt
   * Re-wraps every credential with the current primary key. When the constant
   * is in use and all secrets re-wrap cleanly, the DB key is removed so only
   * wp-config can decrypt going forward.
   */
  public function reencrypt(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $migrated  = 0;
    $failed    = 0;
    $failedIds = [];

    foreach ($this->repository->allWithSecrets() as $row) {
      $id      = (int) $row['id'];
      $rewrapped = $this->cipher->reencrypt((string) ($row['secret_ciphertext'] ?? ''));
      if ($rewrapped === null) {
        $failed++;
        $failedIds[] = $id;
        continue;
      }
      $this->repository->updateCiphertext($id, $rewrapped);
      $migrated++;
    }

    // Only drop the DB key once everything is sealed with the constant.
    $dbKeyRemoved = false;
    if ($this->cipher->usingConstant() && $failed === 0 && $this->cipher->dbKeyPresent()) {
      $this->cipher->forgetDbKey();
      $dbKeyRemoved = true;
    }

    $this->activityLog->log('credential.reencrypted', 'credential', null, null, [
      'meta' => ['migrated' => $migrated, 'failed' => $failed, 'db_key_removed' => $dbKeyRemoved],
    ]);

    return rest_ensure_response([
      'migrated'       => $migrated,
      'failed'         => $failed,
      'failed_ids'     => $failedIds,
      'db_key_removed' => $dbKeyRemoved,
    ]);
  }

  /**
   * POST /credentials
   */
  public function createCredential(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $name = sanitize_text_field((string) $request->get_param('name'));
    $type = (string) $request->get_param('type');

    if ($name === '') {
      return new WP_Error('rest_missing_name', __('Credential name is required.', 'flowsystems-webhook-actions'), ['status' => 400]);
    }

    if (!in_array($type, self::TYPES, true)) {
      return new WP_Error('rest_invalid_type', __('Invalid credential type.', 'flowsystems-webhook-actions'), ['status' => 400]);
    }

    if ($this->repository->nameExists($name)) {
      return new WP_Error('rest_duplicate_name', __('A credential with this name already exists.', 'flowsystems-webhook-actions'), ['status' => 409]);
    }

    $resolved = $this->resolveSecret($type, $request);
    if (is_wp_error($resolved)) {
      return $resolved;
    }

    $headerName = $this->resolveHeaderName($type, $request);

    $id = $this->repository->create([
      'name'              => $name,
      'type'              => $type,
      'header_name'       => $headerName,
      'secret_ciphertext' => $this->cipher->encrypt($resolved['secret']),
      'hint'              => $resolved['hint'],
    ]);

    if (!$id) {
      return new WP_Error('rest_create_failed', __('Failed to create credential.', 'flowsystems-webhook-actions'), ['status' => 500]);
    }

    $this->activityLog->log('credential.created', 'credential', $id, $name, [
      'new' => ['name' => $name, 'type' => $type, 'header_name' => $headerName, 'hint' => $resolved['hint']],
    ]);

    return rest_ensure_response($this->repository->find($id));
  }

  /**
   * PUT/PATCH /credentials/{id}
   */
  public function updateCredential(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $id      = (int) $request->get_param('id');
    $current = $this->repository->find($id);

    if (!$current) {
      return $this->notFound();
    }

    $params  = $request->get_params();
    $updates = [];

    if (array_key_exists('name', $params)) {
      $name = sanitize_text_field((string) $request->get_param('name'));
      if ($name === '') {
        return new WP_Error('rest_missing_name', __('Credential name is required.', 'flowsystems-webhook-actions'), ['status' => 400]);
      }
      if ($this->repository->nameExists($name, $id)) {
        return new WP_Error('rest_duplicate_name', __('A credential with this name already exists.', 'flowsystems-webhook-actions'), ['status' => 409]);
      }
      $updates['name'] = $name;
    }

    $type = array_key_exists('type', $params) ? (string) $request->get_param('type') : $current['type'];
    if (array_key_exists('type', $params)) {
      if (!in_array($type, self::TYPES, true)) {
        return new WP_Error('rest_invalid_type', __('Invalid credential type.', 'flowsystems-webhook-actions'), ['status' => 400]);
      }
      $updates['type'] = $type;
    }

    if (array_key_exists('header_name', $params) || array_key_exists('type', $params)) {
      $updates['header_name'] = $this->resolveHeaderName($type, $request, $current['header_name']);
    }

    // Re-encrypt only when fresh secret material is supplied.
    $hasSecretMaterial = array_key_exists('secret', $params)
      || array_key_exists('username', $params)
      || array_key_exists('password', $params);

    if ($hasSecretMaterial) {
      $resolved = $this->resolveSecret($type, $request);
      if (is_wp_error($resolved)) {
        return $resolved;
      }
      $updates['secret_ciphertext'] = $this->cipher->encrypt($resolved['secret']);
      $updates['hint']              = $resolved['hint'];
    }

    $this->repository->update($id, $updates);

    $this->activityLog->log('credential.updated', 'credential', $id, $updates['name'] ?? $current['name'], [
      'old' => ['name' => $current['name'], 'type' => $current['type'], 'header_name' => $current['header_name'], 'hint' => $current['hint']],
      'new' => [
        'name'        => $updates['name'] ?? $current['name'],
        'type'        => $updates['type'] ?? $current['type'],
        'header_name' => $updates['header_name'] ?? $current['header_name'],
        'hint'        => $updates['hint'] ?? $current['hint'],
      ],
    ]);

    return rest_ensure_response($this->repository->find($id));
  }

  /**
   * DELETE /credentials/{id}[?force=true]
   */
  public function deleteCredential(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $id          = (int) $request->get_param('id');
    $credential  = $this->repository->find($id);

    if (!$credential) {
      return $this->notFound();
    }

    $inUse = $this->repository->countWebhooksUsing($id);
    $force = (bool) $request->get_param('force');

    if ($inUse > 0 && !$force) {
      return new WP_Error(
        'rest_credential_in_use',
        sprintf(
          /* translators: %d: number of webhooks using this credential */
          _n(
            'This credential is used by %d webhook. Detach it or delete with force.',
            'This credential is used by %d webhooks. Detach them or delete with force.',
            $inUse,
            'flowsystems-webhook-actions'
          ),
          $inUse
        ),
        ['status' => 409, 'in_use' => $inUse]
      );
    }

    if ($inUse > 0 && $force) {
      $this->repository->detachFromWebhooks($id);
    }

    if (!$this->repository->delete($id)) {
      return new WP_Error('rest_delete_failed', __('Failed to delete credential.', 'flowsystems-webhook-actions'), ['status' => 500]);
    }

    $this->activityLog->log('credential.deleted', 'credential', $id, $credential['name'], [
      'old' => ['name' => $credential['name'], 'type' => $credential['type'], 'header_name' => $credential['header_name'], 'hint' => $credential['hint']],
    ]);

    return rest_ensure_response(['deleted' => true, 'id' => $id, 'detached' => $inUse]);
  }

  /**
   * Resolve the plaintext secret + masked hint from request params for a type.
   *
   * @return array{secret:string, hint:string}|WP_Error
   */
  private function resolveSecret(string $type, WP_REST_Request $request): array|WP_Error {
    if ($type === 'basic') {
      $username = (string) $request->get_param('username');
      $password = (string) $request->get_param('password');

      if ($username === '' || $password === '') {
        return new WP_Error('rest_missing_secret', __('Basic auth requires a username and password.', 'flowsystems-webhook-actions'), ['status' => 400]);
      }

      return [
        'secret' => $username . ':' . $password,
        'hint'   => $this->truncate('Basic ' . $username) . ' ****',
      ];
    }

    $secret = (string) $request->get_param('secret');
    if ($secret === '') {
      return new WP_Error('rest_missing_secret', __('A secret value is required.', 'flowsystems-webhook-actions'), ['status' => 400]);
    }

    $last4 = strlen($secret) > 4 ? substr($secret, -4) : '';

    $hint = match ($type) {
      'bearer' => 'Bearer ****' . $last4,
      default  => '****' . $last4,
    };

    return ['secret' => $secret, 'hint' => $this->truncate($hint)];
  }

  /**
   * Header name only matters for api_key/custom; bearer/basic always use Authorization.
   */
  private function resolveHeaderName(string $type, WP_REST_Request $request, string $fallback = 'Authorization'): string {
    if (in_array($type, ['bearer', 'basic'], true)) {
      return 'Authorization';
    }

    $name = sanitize_text_field((string) $request->get_param('header_name'));

    return $name !== '' ? $name : ($fallback ?: 'Authorization');
  }

  private function truncate(string $value): string {
    return strlen($value) > 64 ? substr($value, 0, 64) : $value;
  }

  private function notFound(): WP_Error {
    return new WP_Error('rest_credential_not_found', __('Credential not found.', 'flowsystems-webhook-actions'), ['status' => 404]);
  }
}
