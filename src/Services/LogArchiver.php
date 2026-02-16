<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Repositories\LogRepository;
use ZipArchive;

class LogArchiver {
  private const ARCHIVE_DIR = 'fswa-logs/archive';
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
    $uploadDir = wp_upload_dir();
    return $uploadDir['basedir'] . '/' . self::ARCHIVE_DIR;
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

      // Write to archive files
      foreach ($logsByDate as $logDate => $dateLogs) {
        $this->writeToArchive($logDate, $dateLogs);
      }

      // Delete archived logs
      $logIds = array_column($logs, 'id');
      $this->deleteLogs($logIds);

      $totalArchived += count($logs);
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
         WHERE created_at < %s AND status IN ('success', 'error')
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
      } elseif ($stat['status'] === 'error') {
        $error = (int) $stat['count'];
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
   */
  private function writeToArchive(string $date, array $logs): void {
    $yearMonth = gmdate('Y-m', strtotime($date));
    $archiveDir = $this->getArchiveDir() . '/' . $yearMonth;

    // Create directory if not exists
    if (!file_exists($archiveDir)) {
      wp_mkdir_p($archiveDir);
      $this->createHtaccess();
    }

    $filePath = $archiveDir . '/' . $date . '.json';

    // Append to existing file using WP_Filesystem
    global $wp_filesystem;
    $this->initFilesystem();

    $existingContent = '';
    if ($wp_filesystem->exists($filePath)) {
      $existingContent = $wp_filesystem->get_contents($filePath);
    }

    $newContent = '';
    foreach ($logs as $log) {
      $newContent .= wp_json_encode($log) . "\n";
    }

    $wp_filesystem->put_contents($filePath, $existingContent . $newContent, FS_CHMOD_FILE);
  }

  /**
   * Initialize WP_Filesystem
   */
  private function initFilesystem(): void {
    global $wp_filesystem;

    if (!$wp_filesystem) {
      require_once ABSPATH . 'wp-admin/includes/file.php';
      WP_Filesystem();
    }
  }

  /**
   * Create .htaccess to block direct access
   */
  private function createHtaccess(): void {
    global $wp_filesystem;
    $this->initFilesystem();

    $htaccessPath = $this->getArchiveDir() . '/.htaccess';

    if (!$wp_filesystem->exists($htaccessPath)) {
      $baseDir = dirname($htaccessPath);
      if (!$wp_filesystem->exists($baseDir)) {
        wp_mkdir_p($baseDir);
      }

      $wp_filesystem->put_contents($htaccessPath, "Deny from all\n", FS_CHMOD_FILE);
    }
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
   * Create a ZIP archive of all archive files
   *
   * @return string|null Path to ZIP file or null on failure
   */
  public function createArchiveZip(): ?string {
    $archiveDir = $this->getArchiveDir();

    if (!file_exists($archiveDir)) {
      return null;
    }

    $uploadDir = wp_upload_dir();
    $zipFilename = 'fswa-logs-archive-' . gmdate('Y-m-d-His') . '.zip';
    $zipPath = $uploadDir['basedir'] . '/fswa-logs/' . $zipFilename;

    // Ensure directory exists
    $zipDir = dirname($zipPath);
    if (!file_exists($zipDir)) {
      wp_mkdir_p($zipDir);
    }

    if (!class_exists('ZipArchive')) {
      return null;
    }

    $zip = new ZipArchive();

    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
      return null;
    }

    // Add all archive files
    $monthDirs = glob($archiveDir . '/????-??', GLOB_ONLYDIR);

    foreach ($monthDirs as $monthDir) {
      $month = basename($monthDir);
      $files = glob($monthDir . '/*.json');

      foreach ($files as $file) {
        $zip->addFile($file, $month . '/' . basename($file));
      }
    }

    $zip->close();

    // Schedule cleanup of old ZIP files
    $this->cleanupOldZips();

    return $zipPath;
  }

  /**
   * Delete old ZIP files (keep only last 5)
   */
  private function cleanupOldZips(): void {
    $uploadDir = wp_upload_dir();
    $logsDir = $uploadDir['basedir'] . '/fswa-logs';

    $zips = glob($logsDir . '/fswa-logs-archive-*.zip');

    if (count($zips) <= 5) {
      return;
    }

    // Sort by modification time
    usort($zips, function ($a, $b) {
      return filemtime($a) - filemtime($b);
    });

    // Delete oldest files
    $toDelete = array_slice($zips, 0, count($zips) - 5);

    foreach ($toDelete as $file) {
      wp_delete_file($file);
    }
  }

  /**
   * Delete all archive files
   */
  public function clearArchive(): void {
    $archiveDir = $this->getArchiveDir();

    if (!file_exists($archiveDir)) {
      return;
    }

    $this->recursiveDelete($archiveDir);
  }

  /**
   * Recursively delete a directory
   *
   * @param string $dir
   */
  private function recursiveDelete(string $dir): void {
    global $wp_filesystem;
    $this->initFilesystem();

    if (!$wp_filesystem->exists($dir)) {
      return;
    }

    $files = $wp_filesystem->dirlist($dir);

    if (is_array($files)) {
      foreach ($files as $filename => $fileinfo) {
        $path = $dir . '/' . $filename;
        if ('d' === $fileinfo['type']) {
          $this->recursiveDelete($path);
        } else {
          wp_delete_file($path);
        }
      }
    }

    $wp_filesystem->rmdir($dir);
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
