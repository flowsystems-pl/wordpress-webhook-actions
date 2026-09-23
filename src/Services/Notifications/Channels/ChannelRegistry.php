<?php

namespace FlowSystems\WebhookActions\Services\Notifications\Channels;

defined('ABSPATH') || exit;

/**
 * Type → driver. Third parties add a driver with the
 * `fswa_notification_channel_drivers` filter.
 */
class ChannelRegistry {
  /** @var array<string, ChannelDriver>|null */
  private static ?array $drivers = null;

  /**
   * @return array<string, ChannelDriver>
   */
  public static function all(): array {
    if (self::$drivers !== null) {
      return self::$drivers;
    }

    $drivers = [
      new EmailDriver(),
      new SlackDriver(),
      new DiscordDriver(),
      new TelegramDriver(),
      new TeamsDriver(),
      new GoogleChatDriver(),
      new MattermostDriver(),
      new PushoverDriver(),
      new NtfyDriver(),
      new TwilioSmsDriver(),
      new TwilioWhatsappDriver(),
      new PagerDutyDriver(),
      new WebhookDriver(),
    ];

    /**
     * Filter the notification channel drivers. Append a ChannelDriver to add
     * a destination type.
     *
     * @param ChannelDriver[] $drivers
     */
    $drivers = (array) apply_filters('fswa_notification_channel_drivers', $drivers);

    self::$drivers = [];
    foreach ($drivers as $driver) {
      if ($driver instanceof ChannelDriver) {
        self::$drivers[$driver->type()] = $driver;
      }
    }

    return self::$drivers;
  }

  public static function get(string $type): ?ChannelDriver {
    return self::all()[$type] ?? null;
  }

  public static function has(string $type): bool {
    return isset(self::all()[$type]);
  }

  /**
   * The catalog the settings UI renders: type, label, description, fields.
   *
   * @return array<int, array<string, mixed>>
   */
  public static function catalog(): array {
    $out = [];
    foreach (self::all() as $driver) {
      $out[] = [
        'type'        => $driver->type(),
        'label'       => $driver->label(),
        'description' => $driver->description(),
        'fields'      => $driver->fields(),
        'secret_keys' => self::secretKeys($driver),
      ];
    }

    return $out;
  }

  /**
   * @return array<int, string>
   */
  public static function secretKeys(ChannelDriver $driver): array {
    $keys = [];
    foreach ($driver->fields() as $field) {
      if (($field['type'] ?? '') === 'secret') {
        $keys[] = (string) $field['key'];
      }
    }

    return $keys;
  }
}
