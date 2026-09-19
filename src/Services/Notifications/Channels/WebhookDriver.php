<?php

namespace FlowSystems\WebhookActions\Services\Notifications\Channels;

defined('ABSPATH') || exit;

use WP_Error;
use FlowSystems\WebhookActions\Repositories\CredentialRepository;
use FlowSystems\WebhookActions\Services\CredentialCipher;

/**
 * Generic JSON POST: the whole message object to any URL. The escape hatch
 * for Zapier, Make, n8n, an incident tool, or your own endpoint. Auth comes
 * from a vault credential so the secret lives in one place.
 */
class WebhookDriver extends AbstractHttpDriver {
  public function type(): string {
    return 'webhook';
  }

  public function label(): string {
    return __('Generic webhook', 'flowsystems-webhook-actions');
  }

  public function description(): string {
    return __('POSTs the notification as JSON to any URL — Zapier, Make, n8n, your own service.', 'flowsystems-webhook-actions');
  }

  public function fields(): array {
    return [
      ['key' => 'url', 'label' => __('URL', 'flowsystems-webhook-actions'), 'type' => 'url', 'required' => true],
      ['key' => 'auth_credential_id', 'label' => __('Authorization (vault credential)', 'flowsystems-webhook-actions'), 'type' => 'credential', 'required' => false, 'help' => __('Optional. Pick a Credentials Vault entry; its header is added to every request.', 'flowsystems-webhook-actions')],
      ['key' => 'headers', 'label' => __('Extra headers', 'flowsystems-webhook-actions'), 'type' => 'textarea', 'required' => false, 'placeholder' => "X-Source: webhook-actions", 'help' => __('One "Name: value" per line.', 'flowsystems-webhook-actions')],
      ['key' => 'secret', 'label' => __('Signing secret', 'flowsystems-webhook-actions'), 'type' => 'secret', 'required' => false, 'help' => __('Optional. When set, the body is signed as HMAC-SHA256 in the X-FSWA-Signature header.', 'flowsystems-webhook-actions')],
    ];
  }

  public function send(array $message, array $config, array $secrets): true|WP_Error {
    $url = $this->requireConfig($config, 'url', __('URL', 'flowsystems-webhook-actions'));
    if (is_wp_error($url)) {
      return $url;
    }
    if (!wp_http_validate_url($url)) {
      return new WP_Error('fswa_notification_config', __('The webhook URL is not valid.', 'flowsystems-webhook-actions'));
    }

    $headers = ['Content-Type' => 'application/json', 'X-FSWA-Event' => (string) $message['event']];
    foreach (preg_split('/\r?\n/', (string) $this->cfg($config, 'headers')) ?: [] as $line) {
      if (preg_match('/^\s*([A-Za-z0-9\-]+)\s*:\s*(.+?)\s*$/', $line, $m)) {
        $headers[$m[1]] = $m[2];
      }
    }

    $credentialId = (int) $this->cfg($config, 'auth_credential_id', 0);
    if ($credentialId > 0) {
      $credential = (new CredentialRepository())->findWithSecret($credentialId);
      if ($credential) {
        $plain = (new CredentialCipher())->decrypt((string) $credential['secret_ciphertext']);
        if ($plain === null) {
          return new WP_Error('fswa_notification_config', __('The vault credential could not be decrypted.', 'flowsystems-webhook-actions'));
        }
        $headers[(string) $credential['header_name']] = match ($credential['type']) {
          'bearer' => 'Bearer ' . $plain,
          'basic'  => 'Basic ' . (str_contains($plain, ':') ? base64_encode($plain) : $plain),
          default  => $plain,
        };
      }
    }

    $body = wp_json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $secret = trim((string) ($secrets['secret'] ?? ''));
    if ($secret !== '') {
      $headers['X-FSWA-Signature'] = 'sha256=' . hash_hmac('sha256', (string) $body, $secret);
    }

    return $this->outcome($this->post($url, (string) $body, $headers));
  }
}
