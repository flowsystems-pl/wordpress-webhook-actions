<?php

namespace FlowSystems\WebhookActions\Services\Notifications;

defined('ABSPATH') || exit;

use WP_Error;
use FlowSystems\WebhookActions\Repositories\NotificationChannelRepository;
use FlowSystems\WebhookActions\Repositories\NotificationLogRepository;
use FlowSystems\WebhookActions\Services\CredentialCipher;
use FlowSystems\WebhookActions\Services\Notifications\Channels\ChannelRegistry;

/**
 * Sends what the dispatcher queued: claims pending rows, hands each to its
 * channel driver, records the outcome on the row and on the channel. Also the
 * direct path the "Send test" buttons use.
 */
class NotificationSender {
  private NotificationLogRepository     $log;
  private NotificationChannelRepository $channels;
  private CredentialCipher              $cipher;

  public function __construct(
    ?NotificationLogRepository $log = null,
    ?NotificationChannelRepository $channels = null,
    ?CredentialCipher $cipher = null
  ) {
    $this->log      = $log ?? new NotificationLogRepository();
    $this->channels = $channels ?? new NotificationChannelRepository();
    $this->cipher   = $cipher ?? new CredentialCipher();
  }

  /**
   * Send every pending row (up to $limit). Safe to call from any tick.
   *
   * @return array{sent:int, failed:int}
   */
  public function flushPending(int $limit = 50): array {
    $result = ['sent' => 0, 'failed' => 0];

    $rows = $this->log->claimPending($limit);
    if (empty($rows)) {
      return $result;
    }

    $channelCache = [];
    foreach ($rows as $row) {
      $channelId = (int) ($row['channel_id'] ?? 0);
      if (!array_key_exists($channelId, $channelCache)) {
        $channelCache[$channelId] = $channelId > 0 ? $this->channels->findWithSecret($channelId) : null;
      }
      $channel = $channelCache[$channelId];

      if ($channel === null) {
        $this->log->markFailed((int) $row['id'], __('Channel no longer exists.', 'flowsystems-webhook-actions'), false);
        $result['failed']++;
        continue;
      }
      if (empty($channel['is_enabled'])) {
        $this->log->markFailed((int) $row['id'], __('Channel is disabled.', 'flowsystems-webhook-actions'), false);
        $result['failed']++;
        continue;
      }
      if (!is_array($row['message'])) {
        $this->log->markFailed((int) $row['id'], __('No message stored for this notification.', 'flowsystems-webhook-actions'), false);
        $result['failed']++;
        continue;
      }

      $outcome = $this->deliver($row['message'], $channel);
      if ($outcome === true) {
        $this->log->markSent((int) $row['id']);
        $this->channels->recordSent($channelId);
        $result['sent']++;
      } else {
        $error = $outcome->get_error_message();
        $retry = (int) ($row['attempts'] ?? 1) < 3 && $outcome->get_error_code() !== 'fswa_notification_config';
        $this->log->markFailed((int) $row['id'], $error, $retry);
        $this->channels->recordError($channelId, $error);
        $result['failed']++;
      }
    }

    return $result;
  }

  /**
   * Send one message to one channel right now (test buttons, digests).
   *
   * @param array<string, mixed> $message
   * @param array<string, mixed> $channel Row from findWithSecret()
   */
  public function deliver(array $message, array $channel): true|WP_Error {
    $driver = ChannelRegistry::get((string) ($channel['type'] ?? ''));
    if ($driver === null) {
      return new WP_Error('fswa_notification_config', sprintf(
        /* translators: %s: channel type */
        __('Unknown channel type "%s".', 'flowsystems-webhook-actions'),
        (string) ($channel['type'] ?? '')
      ));
    }

    $secrets = $this->secrets($channel);
    if (is_wp_error($secrets)) {
      return $secrets;
    }

    try {
      return $driver->send($message, is_array($channel['config'] ?? null) ? $channel['config'] : [], $secrets);
    } catch (\Throwable $e) {
      return new WP_Error('fswa_notification_driver', $e->getMessage());
    }
  }

  /**
   * Decrypt a channel's secret map.
   *
   * @return array<string, string>|WP_Error
   */
  public function secrets(array $channel): array|WP_Error {
    $blob = (string) ($channel['secret_ciphertext'] ?? '');
    if ($blob === '') {
      return [];
    }
    $plain = $this->cipher->decrypt($blob);
    if ($plain === null) {
      return new WP_Error('fswa_notification_config', __('The channel secrets could not be decrypted. Re-enter them in Notifications → Channels.', 'flowsystems-webhook-actions'));
    }
    $map = json_decode($plain, true);

    return is_array($map) ? array_map('strval', $map) : [];
  }

  /**
   * Encrypt a secret map for storage.
   *
   * @param array<string, string> $secrets
   */
  public function encryptSecrets(array $secrets): ?string {
    $secrets = array_filter(array_map('strval', $secrets), static fn(string $v): bool => $v !== '');
    if (empty($secrets)) {
      return null;
    }

    return $this->cipher->encrypt((string) wp_json_encode($secrets));
  }
}
