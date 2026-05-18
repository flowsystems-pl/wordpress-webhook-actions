<?php

namespace FlowSystems\WebhookActions\Repositories;

defined('ABSPATH') || exit;

class ChainLinkRepository {
  private string $table;
  private string $triggersTable;

  public function __construct() {
    global $wpdb;
    $this->table         = $wpdb->prefix . 'fswa_chain_links';
    $this->triggersTable = $wpdb->prefix . 'fswa_webhook_triggers';
  }

  public function getAll(): array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    return $wpdb->get_results(
      "SELECT id, chain_id, source_webhook_id, target_webhook_id, created_at FROM {$this->table} ORDER BY id ASC",
      ARRAY_A
    ) ?: [];
  }

  public function findByChain(int $chainId): array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    return $wpdb->get_results(
      $wpdb->prepare(
        "SELECT id, chain_id, source_webhook_id, target_webhook_id, created_at FROM {$this->table} WHERE chain_id = %d ORDER BY id ASC",
        $chainId
      ),
      ARRAY_A
    ) ?: [];
  }

  public function findBySource(int $sourceWebhookId): array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    return $wpdb->get_results(
      $wpdb->prepare(
        "SELECT id, chain_id, source_webhook_id, target_webhook_id, created_at FROM {$this->table} WHERE source_webhook_id = %d",
        $sourceWebhookId
      ),
      ARRAY_A
    ) ?: [];
  }

  public function findByTarget(int $targetWebhookId): array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    return $wpdb->get_results(
      $wpdb->prepare(
        "SELECT id, chain_id, source_webhook_id, target_webhook_id, created_at FROM {$this->table} WHERE target_webhook_id = %d",
        $targetWebhookId
      ),
      ARRAY_A
    ) ?: [];
  }

  public function findInvolvingWebhook(int $webhookId): array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    return $wpdb->get_results(
      $wpdb->prepare(
        "SELECT id, chain_id, source_webhook_id, target_webhook_id, created_at FROM {$this->table} WHERE source_webhook_id = %d OR target_webhook_id = %d",
        $webhookId,
        $webhookId
      ),
      ARRAY_A
    ) ?: [];
  }

  public function find(int $id): ?array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $row = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT id, chain_id, source_webhook_id, target_webhook_id, created_at FROM {$this->table} WHERE id = %d",
        $id
      ),
      ARRAY_A
    );
    return $row ?: null;
  }

  /**
   * Insert a chain link AND its synthetic trigger row in fswa_webhook_triggers.
   *
   * @return int|false Link ID on success
   */
  public function create(int $chainId, int $sourceWebhookId, int $targetWebhookId): int|false {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $result = $wpdb->insert(
      $this->table,
      [
        'chain_id'          => $chainId,
        'source_webhook_id' => $sourceWebhookId,
        'target_webhook_id' => $targetWebhookId,
      ],
      ['%d', '%d', '%d']
    );

    if ($result === false) {
      return false;
    }

    $linkId = (int) $wpdb->insert_id;

    // Insert the synthetic trigger row so Dispatcher::dispatch finds the target
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $wpdb->insert(
      $this->triggersTable,
      [
        'webhook_id'   => $targetWebhookId,
        'trigger_name' => 'fswa_chain_link:' . $linkId,
      ],
      ['%d', '%s']
    );

    return $linkId;
  }

  /**
   * Delete a chain link AND its matching synthetic trigger row.
   */
  public function delete(int $id): bool {
    global $wpdb;

    $triggerName = 'fswa_chain_link:' . $id;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $wpdb->delete(
      $this->triggersTable,
      ['trigger_name' => $triggerName],
      ['%s']
    );

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $result = $wpdb->delete($this->table, ['id' => $id], ['%d']);
    return $result !== false && $result > 0;
  }

  /**
   * Cascade delete every link belonging to a chain, and their synthetic triggers.
   *
   * @return int Number of links removed
   */
  public function deleteByChain(int $chainId): int {
    $links = $this->findByChain($chainId);
    $count = 0;
    foreach ($links as $link) {
      if ($this->delete((int) $link['id'])) {
        $count++;
      }
    }
    return $count;
  }

  /**
   * Cascade delete every link involving a webhook (as source OR target).
   *
   * @return int Number of links removed
   */
  public function deleteByWebhook(int $webhookId): int {
    $links = $this->findInvolvingWebhook($webhookId);
    $count = 0;
    foreach ($links as $link) {
      if ($this->delete((int) $link['id'])) {
        $count++;
      }
    }
    return $count;
  }

  /**
   * Delete any of the given chains that have zero links remaining.
   * Use after individual link deletes (deleteLink, deleteByWebhook) to
   * auto-cleanup chains that have been emptied as a side effect.
   *
   * Never call after deleteByChain — the chain is already being deleted.
   *
   * @param int[] $chainIds Chain IDs to check.
   * @return int Number of chains deleted.
   */
  public function cleanupEmptyChains(array $chainIds): int {
    if (empty($chainIds)) {
      return 0;
    }
    $chainRepo = new ChainRepository();
    $deleted = 0;
    foreach (array_unique(array_map('intval', $chainIds)) as $cid) {
      if ($cid > 0 && count($this->findByChain($cid)) === 0) {
        if ($chainRepo->delete($cid)) {
          $deleted++;
        }
      }
    }
    return $deleted;
  }

  /**
   * Detect whether adding edge (source -> target) would create a cycle in the
   * combined chain-links directed graph (across all chains).
   *
   * Returns true if a cycle would be created, false otherwise.
   */
  public function wouldCreateCycle(int $sourceWebhookId, int $targetWebhookId): bool {
    if ($sourceWebhookId === $targetWebhookId) {
      return true; // self-loop
    }

    // Build adjacency from existing links (any chain)
    $links = $this->getAll();
    $adj = [];
    foreach ($links as $l) {
      $s = (int) $l['source_webhook_id'];
      $t = (int) $l['target_webhook_id'];
      $adj[$s]   = $adj[$s] ?? [];
      $adj[$s][] = $t;
    }

    // Add the proposed edge in-memory
    $adj[$sourceWebhookId]   = $adj[$sourceWebhookId] ?? [];
    $adj[$sourceWebhookId][] = $targetWebhookId;

    // DFS from target; if we reach source, there's a cycle
    $visited = [];
    $stack   = [$targetWebhookId];
    while (!empty($stack)) {
      $node = array_pop($stack);
      if ($node === $sourceWebhookId) {
        return true;
      }
      if (isset($visited[$node])) {
        continue;
      }
      $visited[$node] = true;
      foreach ($adj[$node] ?? [] as $next) {
        if (!isset($visited[$next])) {
          $stack[] = $next;
        }
      }
    }
    return false;
  }
}
