<?php

namespace FlowSystems\WebhookActions\Services\Notifications;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * Validates and normalises a rule as submitted over REST or by an ability,
 * so both paths store exactly the same shape.
 */
class RuleInput {
  public const DIGESTS = ['hourly', 'daily'];

  /** Default throttle for a site-wide success rule (spam guard). */
  public const GLOBAL_SUCCESS_THROTTLE = HOUR_IN_SECONDS;

  /**
   * @param array<string, mixed> $params
   * @return array<string, mixed>|WP_Error Keys: name, event, webhook_id, is_enabled, filters, channel_ids, template, throttle_seconds, digest, sort_order (only those present, all when $create)
   */
  public static function fromRequest(array $params, bool $create): array|WP_Error {
    $data = [];

    if ($create || array_key_exists('event', $params)) {
      $event = sanitize_key((string) ($params['event'] ?? ''));
      if (!in_array($event, DeliveryEvents::ALL, true)) {
        return new WP_Error('rest_invalid_event', sprintf(
          /* translators: %s: comma-separated list of valid events */
          __('event must be one of: %s', 'flowsystems-webhook-actions'),
          implode(', ', DeliveryEvents::ALL)
        ), ['status' => 400]);
      }
      $data['event'] = $event;
    }

    if ($create || array_key_exists('webhook_id', $params)) {
      $data['webhook_id'] = (int) ($params['webhook_id'] ?? 0) ?: null;
    }

    if ($create || array_key_exists('name', $params)) {
      $data['name'] = sanitize_text_field((string) ($params['name'] ?? ''));
    }

    if (array_key_exists('is_enabled', $params)) {
      $data['is_enabled'] = (bool) $params['is_enabled'];
    } elseif ($create) {
      $data['is_enabled'] = true;
    }

    if ($create || array_key_exists('channel_ids', $params)) {
      $ids = $params['channel_ids'] ?? [];
      if (!is_array($ids)) {
        return new WP_Error('rest_invalid_param', __('channel_ids must be a list of channel IDs.', 'flowsystems-webhook-actions'), ['status' => 400]);
      }
      $data['channel_ids'] = array_values(array_unique(array_filter(array_map('intval', $ids))));
      if ($create && empty($data['channel_ids'])) {
        return new WP_Error('rest_missing_channels', __('Pick at least one channel.', 'flowsystems-webhook-actions'), ['status' => 400]);
      }
    }

    if ($create || array_key_exists('filters', $params)) {
      $filters = self::filters(is_array($params['filters'] ?? null) ? $params['filters'] : []);
      if (is_wp_error($filters)) {
        return $filters;
      }
      $data['filters'] = $filters;
    }

    if (array_key_exists('template', $params)) {
      $template = self::template($params['template']);
      if (is_wp_error($template)) {
        return $template;
      }
      $data['template'] = $template;
    } elseif ($create) {
      $data['template'] = null;
    }

    if (array_key_exists('throttle_seconds', $params)) {
      $raw = $params['throttle_seconds'];
      $seconds                  = ($raw === null || $raw === '') ? 0 : max(0, min(7 * DAY_IN_SECONDS, (int) $raw));
      $data['throttle_seconds'] = $seconds > 0 ? $seconds : null;
    } elseif ($create) {
      // A site-wide "every success" rule gets an hour of quiet by default.
      $data['throttle_seconds'] = ($data['event'] === DeliveryEvents::SUCCESS && $data['webhook_id'] === null) ? self::GLOBAL_SUCCESS_THROTTLE : null;
    }

    if (array_key_exists('digest', $params)) {
      $digest         = sanitize_key((string) ($params['digest'] ?? ''));
      $data['digest'] = in_array($digest, self::DIGESTS, true) ? $digest : null;
    } elseif ($create) {
      $data['digest'] = null;
    }

    if (array_key_exists('sort_order', $params)) {
      $data['sort_order'] = (int) $params['sort_order'];
    }

    return $data;
  }

  /**
   * @param array<string, mixed> $raw
   * @return array<string, mixed>|WP_Error
   */
  public static function filters(array $raw): array|WP_Error {
    $out = [];

    if (isset($raw['attempts'])) {
      $attempts = $raw['attempts'];
      if (is_string($attempts)) {
        $attempts = $attempts === 'any' || $attempts === '' ? [] : preg_split('/[,\s]+/', $attempts);
      }
      if (!is_array($attempts)) {
        return new WP_Error('rest_invalid_param', __('filters.attempts must be a list of attempt numbers.', 'flowsystems-webhook-actions'), ['status' => 400]);
      }
      $list = array_values(array_unique(array_filter(array_map('intval', $attempts), static fn(int $n): bool => $n >= 1 && $n <= 100)));
      sort($list);
      if (!empty($list)) {
        $out['attempts'] = $list;
      }
    }

    if (isset($raw['http_codes'])) {
      $codes = $raw['http_codes'];
      if (is_string($codes)) {
        $codes = preg_split('/[,\s]+/', $codes);
      }
      if (!is_array($codes)) {
        return new WP_Error('rest_invalid_param', __('filters.http_codes must be a list.', 'flowsystems-webhook-actions'), ['status' => 400]);
      }
      $clean = [];
      foreach ($codes as $code) {
        $code = strtolower(trim((string) $code));
        if ($code === '') {
          continue;
        }
        if ($code === 'transport' || preg_match('/^[1-5]xx$/', $code) || preg_match('/^\d{3}$/', $code) || preg_match('/^\d{3}-\d{3}$/', $code)) {
          $clean[] = $code;
          continue;
        }
        return new WP_Error('rest_invalid_param', sprintf(
          /* translators: %s: the rejected value */
          __('filters.http_codes: "%s" is not a code (503), a class (5xx), a range (500-504) or "transport".', 'flowsystems-webhook-actions'),
          $code
        ), ['status' => 400]);
      }
      if (!empty($clean)) {
        $out['http_codes'] = array_values(array_unique($clean));
      }
    }

    if (isset($raw['reason'])) {
      $reason = sanitize_key((string) $raw['reason']);
      if (in_array($reason, [DeliveryEvents::REASON_EXHAUSTED, DeliveryEvents::REASON_NON_RETRYABLE], true)) {
        $out['reason'] = $reason;
      }
    }

    if (isset($raw['triggers'])) {
      $triggers = $raw['triggers'];
      if (is_string($triggers)) {
        $triggers = preg_split('/[,\s]+/', $triggers);
      }
      if (is_array($triggers)) {
        $clean = array_values(array_unique(array_filter(array_map(static fn($t): string => trim(preg_replace('/[^A-Za-z0-9_\-:.*\/]/', '', (string) $t)), $triggers))));
        if (!empty($clean)) {
          $out['triggers'] = $clean;
        }
      }
    }

    if (!empty($raw['only_after_retry'])) {
      $out['only_after_retry'] = true;
    }
    if (!empty($raw['include_tests'])) {
      $out['include_tests'] = true;
    }

    return $out;
  }

  /**
   * @return array<string, mixed>|null|WP_Error
   */
  public static function template(mixed $raw): array|null|WP_Error {
    if ($raw === null || $raw === '' || $raw === []) {
      return null;
    }
    if (!is_array($raw)) {
      return new WP_Error('rest_invalid_param', __('template must be an object with subject, title, body and short.', 'flowsystems-webhook-actions'), ['status' => 400]);
    }
    $out = [];
    foreach (['subject', 'title', 'short'] as $key) {
      if (isset($raw[$key]) && is_string($raw[$key]) && trim($raw[$key]) !== '') {
        $out[$key] = mb_substr(sanitize_text_field(str_replace(["\r", "\n"], ' ', $raw[$key])), 0, 500);
      }
    }
    if (isset($raw['body']) && is_string($raw['body']) && trim($raw['body']) !== '') {
      $out['body'] = mb_substr(self::cleanMultiline($raw['body']), 0, 8000);
    }
    if (array_key_exists('include_fields', $raw)) {
      $out['include_fields'] = (bool) $raw['include_fields'];
    }

    return empty($out) ? null : $out;
  }

  /**
   * Keep newlines, drop tags and control characters.
   */
  private static function cleanMultiline(string $text): string {
    $text = wp_strip_all_tags($text, false);
    $text = preg_replace('/[^\PC\n\t]/u', '', $text) ?? $text;

    return trim(str_replace("\r\n", "\n", $text));
  }
}
