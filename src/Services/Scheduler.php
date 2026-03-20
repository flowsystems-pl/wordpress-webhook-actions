<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

class Scheduler {

  const GROUP = 'flowsystems-webhook-actions';

  public static function hasActionScheduler(): bool {
    return function_exists('as_schedule_recurring_action');
  }

  /**
   * Schedule a recurring action. No-op if already scheduled.
   */
  public static function scheduleRecurring(
    string $hook,
    int $intervalSeconds,
    string $wpCronSchedule,
    int $timestamp = 0
  ): void {
    if (self::hasActionScheduler()) {
      if (!as_has_scheduled_action($hook, [], self::GROUP)) {
        as_schedule_recurring_action(time(), $intervalSeconds, $hook, [], self::GROUP);
      }
    } else {
      if (!wp_next_scheduled($hook)) {
        wp_schedule_event($timestamp ?: time(), $wpCronSchedule, $hook);
      }
    }
  }

  /**
   * Unschedule all instances of a hook.
   * Always clears both schedulers to handle transitions (e.g. AS added/removed after install).
   */
  public static function unschedule(string $hook): void {
    if (self::hasActionScheduler()) {
      as_unschedule_all_actions($hook, [], self::GROUP);
    }
    wp_unschedule_hook($hook);
  }

  /**
   * Return next scheduled timestamp, or false if not scheduled.
   */
  public static function nextScheduled(string $hook): int|false {
    if (self::hasActionScheduler()) {
      $next = as_next_scheduled_action($hook, [], self::GROUP);
      return $next !== false ? (int) $next : false;
    }
    return wp_next_scheduled($hook);
  }
}
