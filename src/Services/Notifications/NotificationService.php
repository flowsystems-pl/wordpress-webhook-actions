<?php

namespace FlowSystems\WebhookActions\Services\Notifications;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Repositories\NotificationLogRepository;
use FlowSystems\WebhookActions\Services\Scheduler;

/**
 * Wires notifications into the plugin lifecycle: the delivery-event listener,
 * the send flush on every queue tick and on the immediate single event, the
 * hourly digest cron, and log pruning.
 */
class NotificationService {
  private NotificationDispatcher $dispatcher;
  private NotificationSender     $sender;
  private DigestFlusher          $digest;

  public function __construct() {
    $this->dispatcher = new NotificationDispatcher();
    $this->sender     = new NotificationSender();
    $this->digest     = new DigestFlusher();
  }

  public function register(): void {
    add_action('fswa_delivery_event', [$this->dispatcher, 'handle'], 10, 2);

    add_action(NotificationDispatcher::SEND_HOOK, [$this, 'flush']);
    add_action('fswa_queue_processed', [$this, 'flush']);

    add_action(DigestFlusher::HOOK, [$this->digest, 'flush']);
    add_action('init', [$this, 'ensureScheduled'], 2);

    add_action('fswa_cleanup_logs', [$this, 'prune']);
  }

  public function flush(): void {
    try {
      $this->sender->flushPending();
    } catch (\Throwable $e) {
      if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[fswa] notification flush failed: ' . $e->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
      }
    }
  }

  public function ensureScheduled(): void {
    Scheduler::scheduleRecurring(DigestFlusher::HOOK, HOUR_IN_SECONDS, 'hourly');
  }

  public static function unschedule(): void {
    Scheduler::unschedule(DigestFlusher::HOOK);
    wp_unschedule_hook(NotificationDispatcher::SEND_HOOK);
  }

  public function prune(): void {
    $days = (int) get_option('fswa_log_retention_days', 30);
    (new NotificationLogRepository())->pruneOlderThan(max(1, $days));
  }

  public function dispatcher(): NotificationDispatcher {
    return $this->dispatcher;
  }

  public function sender(): NotificationSender {
    return $this->sender;
  }
}
