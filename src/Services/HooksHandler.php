<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

/**
 * Hooks Handler
 * Listens for WordPress hooks and triggers webhook dispatching
 */
class HooksHandler {

  private Dispatcher $dispatcher;

  /**
   *
   * @param Dispatcher $dispatcher Dispatcher instance
   */
  public function __construct(Dispatcher $dispatcher) {
    $this->dispatcher = $dispatcher;
    $this->setupHooks();
  }

  /**
   * Setup WordPress action hooks for user events
   *
   * @return void
   */
  private function setupHooks(): void {
    $allTriggers = $this->dispatcher->getWebhooksRepository()
      ->getAllTriggers();

    foreach ($allTriggers as $trigger) {
      add_action($trigger, [$this, 'registerTriggerHandler']);
    }

    // Register queue processor for cron
    add_action('fswa_process_queue', [$this, 'processQueue']);
  }

  /**
   * Process the webhook queue (called by WP-Cron)
   *
   * @return void
   */
  public function processQueue(): void {
    /**
     * Filter the number of jobs to process per batch.
     *
     * @param int $batch_size Number of jobs to process (default 10)
     */
    $batchSize = (int) apply_filters('fswa_queue_batch_size', 10);
    $this->dispatcher->process($batchSize);
  }

  /**
   * Generic trigger handler to map WordPress hooks to dispatcher triggers
   *
   * @param $args Variable arguments passed by the WordPress hook
   * @return void
   */
  public function registerTriggerHandler(...$args): void {
    $trigger = current_filter();

    static $triggerLocks = [];

    $uniqueKey = md5($trigger . serialize($args));

    if (isset($triggerLocks[$uniqueKey])) {
      return;
    }

    $triggerLocks[$uniqueKey] = true;

    $transientKey = 'fswa_trigger_' . md5($trigger . serialize($args));
    if (get_transient($transientKey)) {
      return;
    }

    set_transient($transientKey, true, 30);

    $this->dispatcher->dispatch($trigger, $args);
  }
}
