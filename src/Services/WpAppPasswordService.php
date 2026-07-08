<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

use WP_Error;
use WP_Application_Passwords;
use FlowSystems\WebhookActions\Repositories\CredentialRepository;

/**
 * Provisions a WordPress Application Password for the CURRENT user and stores it
 * as a "basic" vault credential, so internal automations that call this site's
 * own REST API get first-class, one-click auth without the user ever copying a
 * secret by hand.
 *
 * Security posture:
 *  - Only ever mints for the current authenticated user — the app password
 *    inherits THAT user's capabilities, which they already hold, so it crosses
 *    no privilege boundary. The caller (REST route / AI ability) never chooses
 *    the user.
 *  - The plaintext app password is written straight into the encrypted vault and
 *    NEVER returned; callers receive only the credential metadata + masked hint.
 */
class WpAppPasswordService {
  private CredentialRepository $repository;
  private CredentialCipher     $cipher;
  private ActivityLogService   $activityLog;

  public function __construct() {
    $this->repository  = new CredentialRepository();
    $this->cipher      = new CredentialCipher();
    $this->activityLog = new ActivityLogService();
  }

  /**
   * Mint an Application Password for the current user and store it as a basic
   * vault credential. Returns the created credential metadata (no secret), or a
   * WP_Error explaining why provisioning is unavailable.
   *
   * @return array<string, mixed>|WP_Error
   */
  public function provisionForCurrentUser(string $label = ''): array|WP_Error {
    if (!class_exists('WP_Application_Passwords')) {
      return new WP_Error(
        'fswa_app_pw_unsupported',
        __('Application Passwords require WordPress 5.6 or newer.', 'flowsystems-webhook-actions'),
        ['status' => 501]
      );
    }

    $user = wp_get_current_user();
    if (!$user || !$user->exists()) {
      return new WP_Error(
        'fswa_app_pw_no_user',
        __('No signed-in user to provision an Application Password for — open the AI Builder while logged in as an administrator.', 'flowsystems-webhook-actions'),
        ['status' => 401]
      );
    }

    if (function_exists('wp_is_application_passwords_available') && !wp_is_application_passwords_available()) {
      return new WP_Error(
        'fswa_app_pw_unavailable',
        __('Application Passwords are not available on this site. They require HTTPS (or a local development environment).', 'flowsystems-webhook-actions'),
        ['status' => 400]
      );
    }

    if (function_exists('wp_is_application_passwords_available_for_user') && !wp_is_application_passwords_available_for_user($user)) {
      return new WP_Error(
        'fswa_app_pw_user_disabled',
        __('Application Passwords are disabled for your user account.', 'flowsystems-webhook-actions'),
        ['status' => 400]
      );
    }

    $appName = $label !== '' ? sanitize_text_field($label) : 'Webhook Actions AI';

    $created = WP_Application_Passwords::create_new_application_password($user->ID, ['name' => $appName]);
    if (is_wp_error($created)) {
      return $created;
    }
    [$plaintext, $item] = $created;

    // Store as a basic credential: WP REST accepts the app password with or
    // without its display spaces; we strip them to keep the secret compact.
    $secret = $user->user_login . ':' . str_replace(' ', '', (string) $plaintext);

    $credName = $this->uniqueCredentialName($user->user_login);

    $id = $this->repository->create([
      'name'              => $credName,
      'type'              => 'basic',
      'header_name'       => 'Authorization',
      'secret_ciphertext' => $this->cipher->encrypt($secret),
      'hint'              => $this->truncate('Basic ' . $user->user_login) . ' ****',
    ]);

    if (!$id) {
      return new WP_Error(
        'fswa_app_pw_store_failed',
        __('Minted the Application Password but failed to store it in the vault.', 'flowsystems-webhook-actions'),
        ['status' => 500]
      );
    }

    $this->activityLog->log('credential.created', 'credential', $id, $credName, [
      'new'  => ['name' => $credName, 'type' => 'basic', 'header_name' => 'Authorization'],
      'meta' => [
        'provisioned_app_password' => true,
        'app_password_uuid'        => (string) ($item['uuid'] ?? ''),
        'user_login'               => $user->user_login,
      ],
    ]);

    return $this->repository->find((int) $id);
  }

  /**
   * A self-describing, unique vault name so both the human and the AI recognise
   * this as the site's own WP REST API auth (e.g. "WP REST API (internal) — admin").
   */
  private function uniqueCredentialName(string $login): string {
    $base = sprintf('WP REST API (internal) — %s', $login);
    $name = $base;
    $n    = 2;
    while ($this->repository->nameExists($name)) {
      $name = $base . ' #' . $n;
      $n++;
    }
    return $name;
  }

  private function truncate(string $value): string {
    return strlen($value) > 64 ? substr($value, 0, 64) : $value;
  }
}
