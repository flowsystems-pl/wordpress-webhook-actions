<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

/**
 * Service to manage archived webhook statistics
 * These are stats from logs that have been deleted during retention cleanup
 */
class StatsService {
  private const OPTION_KEY = 'fswa_archived_stats';

  /**
   * Get archived statistics (from deleted logs)
   *
   * @return array{total_sent: int, total_success: int, total_error: int}
   */
  public function getArchivedStats(): array {
    $defaults = [
      'total_sent' => 0,
      'total_success' => 0,
      'total_error' => 0,
    ];

    $stats = get_option(self::OPTION_KEY, $defaults);

    return array_merge($defaults, (array) $stats);
  }

  /**
   * Add stats from logs that are about to be deleted
   * Called by LogArchiver before log retention cleanup
   *
   * @param int $success Success count to archive
   * @param int $error Error count to archive
   */
  public function archiveStats(int $success, int $error): void {
    if ($success === 0 && $error === 0) {
      return;
    }

    $stats = $this->getArchivedStats();
    $stats['total_sent'] += ($success + $error);
    $stats['total_success'] += $success;
    $stats['total_error'] += $error;
    update_option(self::OPTION_KEY, $stats, false);
  }

  /**
   * Check if there are any archived stats
   *
   * @return bool
   */
  public function hasArchivedData(): bool {
    $stats = $this->getArchivedStats();
    return $stats['total_sent'] > 0;
  }

  /**
   * Reset all archived statistics (used on uninstall)
   */
  public static function reset(): void {
    delete_option(self::OPTION_KEY);
  }
}
