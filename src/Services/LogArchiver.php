<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Repositories\LogRepository;
use FlowSystems\WebhookActions\Support\PrivateStorage;
use ZipArchive;

class LogArchiver {
  /** Folder inside the plugin's private storage. See PrivateStorage. */
  public const STORAGE_DIR = 'log-archive';

  /** Where a download ZIP is built before it is streamed and deleted. */
  private const TEMP_DIR = 'tmp';

  private LogRepository $repository;
  private StatsService $statsService;

  public function __construct() {
    $this->repository = new LogRepository();
    $this->statsService = new StatsService();
  }

  /**
   * Get the archive base directory
   *
   * @return string
   */
  public function getArchiveDir(): string {
    return PrivateStorage::path(self::STORAGE_DIR);
  }

  /**
   * Archive logs older than specified days
   *
   * @param int $days
   * @return int Number of logs archived
   */
  public function archiveLogs(int $days): int {
    $date = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));
    $totalArchived = 0;

    // Archives written before 3.4.3 sat at a fixed, guessable path. The upgrade
    // moves them; this repeats the move for a site where it could not finish.
    LegacyStorage::migrate();

    // Aggregate stats from logs that will be deleted BEFORE deleting them
    $this->aggregateStatsBeforeDeletion($date);

    // Process logs in batches
    while (true) {
      $logs = $this->repository->getOlderThan($date, 500);

      if (empty($logs)) {
        break;
      }

      // Group logs by date
      $logsByDate = [];
      foreach ($logs as $log) {
        $logDate = gmdate('Y-m-d', strtotime($log['created_at']));
        if (!isset($logsByDate[$logDate])) {
          $logsByDate[$logDate] = [];
        }
        $logsByDate[$logDate][] = $log;
      }

      // Write to archive files. Only rows that made it into a file are deleted:
      // until 3.4.3 the write was never checked, so a folder that could not be
      // written to lost the logs instead of keeping them.
      $logIds = [];
      foreach ($logsByDate as $logDate => $dateLogs) {
        if ($this->writeToArchive($logDate, $dateLogs)) {
          $logIds = array_merge($logIds, array_column($dateLogs, 'id'));
        }
      }

      // Nothing could be written. The same rows would come back next time
      // round, so stop and leave them for the next run.
      if (empty($logIds)) {
        break;
      }

      $this->deleteLogs($logIds);
      $totalArchived += count($logIds);

      if (count($logIds) < count($logs)) {
        break;
      }
    }

    return $totalArchived;
  }

  /**
   * Delete logs older than specified days WITHOUT archiving
   * Aggregates stats before deletion to preserve cumulative counts
   *
   * @param int $days
   * @return int Number of logs deleted
   */
  public function deleteLogsWithoutArchive(int $days): int {
    $date = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

    // Aggregate stats from logs that will be deleted BEFORE deleting them
    $this->aggregateStatsBeforeDeletion($date);

    return $this->repository->deleteOlderThan($date);
  }

  /**
   * Aggregate stats from logs older than the given date before they are deleted
   *
   * @param string $date MySQL datetime format
   */
  private function aggregateStatsBeforeDeletion(string $date): void {
    global $wpdb;

    $logsTable = $wpdb->prefix . 'fswa_logs';

    // Count success/error logs that are about to be deleted
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $stats = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT status, COUNT(*) as count
         FROM {$logsTable}
         WHERE created_at < %s AND status IN ('success', 'error', 'permanently_failed')
           AND stats_recorded = 0
         GROUP BY status",
        $date
      ),
      ARRAY_A
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

    $success = 0;
    $error = 0;

    foreach ($stats as $stat) {
      if ($stat['status'] === 'success') {
        $success = (int) $stat['count'];
      } elseif ($stat['status'] === 'error' || $stat['status'] === 'permanently_failed') {
        $error += (int) $stat['count'];
      }
    }

    // Archive these stats before the logs are deleted
    $this->statsService->archiveStats($success, $error);
  }

  /**
   * Write logs to archive file (JSON Lines format)
   *
   * @param string $date Y-m-d format
   * @param array $logs
   * @return bool Whether the rows are in the file.
   */
  private function writeToArchive(string $date, array $logs): bool {
    $yearMonth  = gmdate('Y-m', strtotime($date));
    $archiveDir = PrivateStorage::ensure(self::STORAGE_DIR . '/' . $yearMonth);

    if ($archiveDir === null) {
      return false;
    }

    $newContent = '';
    foreach ($logs as $log) {
      // A row written before 3.4.3 may not have been reached by the stored-data
      // cleanup yet. Nothing goes into a file with a password in it.
      $log = array_merge($log, StoredCredentialCleanup::scrubLogRow($log));
      $newContent .= wp_json_encode($log) . "\n";
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Appending: WP_Filesystem can only rewrite a whole file, which meant reading a full day of logs into memory for every batch.
    $file    = $archiveDir . '/' . $date . '.json';
    $written = @file_put_contents($file, $newContent, FILE_APPEND | LOCK_EX);
    PrivateStorage::adopt($file);

    return $written !== false && $written === strlen($newContent);
  }

  /**
   * Delete logs by IDs
   *
   * @param array $ids
   */
  private function deleteLogs(array $ids): void {
    global $wpdb;

    if (empty($ids)) {
      return;
    }

    $ids = array_map('absint', $ids);
    $idsList = implode(',', $ids);

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe, IDs are sanitized with absint
    $wpdb->query("DELETE FROM {$wpdb->prefix}fswa_logs WHERE id IN ({$idsList})");
  }

  /**
   * Get archive information
   *
   * @return array
   */
  public function getArchiveInfo(): array {
    $archiveDir = $this->getArchiveDir();

    if (!file_exists($archiveDir)) {
      return [
        'exists' => false,
        'size' => 0,
        'size_human' => '0 B',
        'files_count' => 0,
        'oldest_date' => null,
        'newest_date' => null,
        'months' => [],
      ];
    }

    $size = 0;
    $filesCount = 0;
    $dates = [];
    $months = [];

    // Iterate through month directories
    $monthDirs = glob($archiveDir . '/????-??', GLOB_ONLYDIR);

    foreach ($monthDirs as $monthDir) {
      $month = basename($monthDir);
      $monthFiles = glob($monthDir . '/*.json');
      $monthSize = 0;

      foreach ($monthFiles as $file) {
        $fileSize = filesize($file);
        $size += $fileSize;
        $monthSize += $fileSize;
        $filesCount++;

        // Extract date from filename
        $date = basename($file, '.json');
        $dates[] = $date;
      }

      $months[$month] = [
        'files_count' => count($monthFiles),
        'size' => $monthSize,
        'size_human' => $this->humanFilesize($monthSize),
      ];
    }

    sort($dates);

    return [
      'exists' => true,
      'size' => $size,
      'size_human' => $this->humanFilesize($size),
      'files_count' => $filesCount,
      'oldest_date' => !empty($dates) ? reset($dates) : null,
      'newest_date' => !empty($dates) ? end($dates) : null,
      'months' => $months,
    ];
  }

  /**
   * Build a ZIP of all archive files for a download.
   *
   * The file is written inside the private storage folder under a random name
   * and is the caller's to delete once it has been sent. It is never given a
   * URL: until 3.4.3 the ZIP was left in uploads and handed out as a link.
   *
   * @return string|null Path to ZIP file or null on failure
   */
  public function createArchiveZip(): ?string {
    $archiveDir = $this->getArchiveDir();

    if (!file_exists($archiveDir) || !class_exists('ZipArchive')) {
      return null;
    }

    $this->cleanupTempFiles();

    $tempDir = PrivateStorage::ensure(self::TEMP_DIR);
    if ($tempDir === null) {
      return null;
    }

    $zipPath = $tempDir . '/' . wp_generate_password(32, false) . '.zip';
    $zip     = new ZipArchive();

    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
      return null;
    }

    $added     = 0;
    $monthDirs = glob($archiveDir . '/????-??', GLOB_ONLYDIR) ?: [];

    foreach ($monthDirs as $monthDir) {
      $month = basename($monthDir);

      foreach (glob($monthDir . '/*.json') ?: [] as $file) {
        if ($zip->addFile($file, $month . '/' . basename($file))) {
          $added++;
        }
      }
    }

    // An empty ZipArchive writes no file at all.
    if ($added === 0) {
      $zip->close();
      wp_delete_file($zipPath);
      return null;
    }

    $zip->close();

    return file_exists($zipPath) ? $zipPath : null;
  }

  /**
   * Remove download ZIPs a request left behind (a dropped connection, a
   * fatal error mid-stream). Anything older than an hour is not in use.
   */
  public function cleanupTempFiles(): void {
    $tempDir = PrivateStorage::path(self::TEMP_DIR);

    foreach (glob($tempDir . '/*.zip') ?: [] as $file) {
      if ((int) @filemtime($file) < time() - HOUR_IN_SECONDS) {
        wp_delete_file($file);
      }
    }
  }

  /**
   * Delete all archive files
   */
  public function clearArchive(): void {
    PrivateStorage::deleteTree($this->getArchiveDir());
    PrivateStorage::deleteTree(PrivateStorage::path(self::TEMP_DIR));
  }

  /**
   * Convert bytes to human readable format
   *
   * @param int $bytes
   * @return string
   */
  private function humanFilesize(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];

    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
      $bytes /= 1024;
    }

    return round($bytes, 2) . ' ' . $units[$i];
  }
}
