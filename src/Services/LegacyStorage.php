<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Services\Ai\AgentTraceLog;
use FlowSystems\WebhookActions\Support\PrivateStorage;

/**
 * Empties the two folders earlier versions wrote to at fixed paths.
 *
 * Before 3.4.3:
 *
 *   uploads/fswa-logs/archive/<YYYY-MM>/<YYYY-MM-DD>.json   archived delivery logs
 *   uploads/fswa-logs/fswa-logs-archive-<stamp>.zip         download ZIPs, kept on disk
 *   uploads/fswa-ai-logs/<YYYY-MM-DD>.jsonl                 AI Builder trace log
 *
 * All three could be fetched by URL on a server that ignores .htaccess, and
 * the ZIPs on Apache too, because they sat one level above the only .htaccess.
 *
 * Archive and trace files are moved into PrivateStorage. The ZIPs are copies
 * of the archive and are deleted. Runs from the 2.6.0 migration and again at
 * the start of every archive run, so a move that failed once (a read-only
 * mount, a permissions problem) is retried until the old folders are gone.
 */
class LegacyStorage {
  private const ARCHIVE_DIR = 'fswa-logs';
  private const TRACE_DIR   = 'fswa-ai-logs';

  /**
   * Is anything still at one of the old paths?
   */
  public static function exists(): bool {
    $base = self::uploadsDir();

    return is_dir($base . '/' . self::ARCHIVE_DIR) || is_dir($base . '/' . self::TRACE_DIR);
  }

  /**
   * Move what can be kept, delete the rest, remove the old folders.
   *
   * @return array{archive_files: int, trace_files: int, zips: int, leftover: bool}
   */
  public static function migrate(): array {
    $result = ['archive_files' => 0, 'trace_files' => 0, 'zips' => 0, 'leftover' => false];

    if (!self::exists()) {
      return $result;
    }

    $base = self::uploadsDir();

    // Archived delivery logs.
    $oldLogs = $base . '/' . self::ARCHIVE_DIR;
    if (is_dir($oldLogs)) {
      foreach (glob($oldLogs . '/archive/????-??/*.json') ?: [] as $file) {
        $month  = basename(dirname($file));
        $target = PrivateStorage::ensure(LogArchiver::STORAGE_DIR . '/' . $month);
        if ($target !== null && self::moveFile($file, $target . '/' . basename($file))) {
          $result['archive_files']++;
        }
      }

      foreach (glob($oldLogs . '/*.zip') ?: [] as $zip) {
        wp_delete_file($zip);
        if (!file_exists($zip)) {
          $result['zips']++;
        }
      }

      self::removeIfEmpty($oldLogs);
    }

    // AI Builder trace files.
    $oldTraces = $base . '/' . self::TRACE_DIR;
    if (is_dir($oldTraces)) {
      $moved = [];
      foreach (glob($oldTraces . '/*.jsonl') ?: [] as $file) {
        $target = PrivateStorage::ensure(AgentTraceLog::STORAGE_DIR);
        if ($target !== null && self::moveFile($file, $target . '/' . basename($file))) {
          $moved[] = $target . '/' . basename($file);
        }
      }

      self::removeIfEmpty($oldTraces);

      // The trace log keeps 30 days. It only prunes when a new trace is
      // written, so a site that stopped using the AI Builder still had every
      // file it ever wrote; do not carry those over.
      (new AgentTraceLog())->prune();

      // Report what was kept, not what passed through.
      $result['trace_files'] = count(array_filter($moved, 'file_exists'));
    }

    // Files that arrive after the stored-data cleanup has finished still need
    // to go through it.
    if ($result['archive_files'] > 0) {
      StoredCredentialCleanup::requeueArchive();
    }

    $result['leftover'] = self::exists();

    return $result;
  }

  /**
   * Delete both old folders outright. Used on uninstall.
   */
  public static function purge(): void {
    $base = self::uploadsDir();

    PrivateStorage::deleteTree($base . '/' . self::ARCHIVE_DIR);
    PrivateStorage::deleteTree($base . '/' . self::TRACE_DIR);
  }

  /**
   * Move one file. When the target already exists (the new code archived the
   * same day before the old folder was emptied) the old lines are appended.
   */
  private static function moveFile(string $from, string $to): bool {
    if (file_exists($to)) {
      // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local files; see PrivateStorage::guard() on WP_Filesystem.
      $ok = @file_put_contents($to, (string) @file_get_contents($from), FILE_APPEND | LOCK_EX) !== false;
    } else {
      // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Same filesystem, atomic; WP_Filesystem::move() copies.
      $ok = @rename($from, $to) || @copy($from, $to);
    }

    if ($ok && file_exists($from)) {
      wp_delete_file($from);
    }
    if ($ok) {
      PrivateStorage::adopt($to);
    }

    return $ok && !file_exists($from);
  }

  /**
   * Delete a legacy folder once only its own guard files and empty folders are
   * left in it. A file that could not be moved keeps the folder, and exists()
   * keeps reporting it.
   */
  private static function removeIfEmpty(string $dir): void {
    if (self::hasDataFiles($dir)) {
      return;
    }

    PrivateStorage::deleteTree($dir);
  }

  private static function hasDataFiles(string $dir): bool {
    foreach ((array) @scandir($dir) as $entry) {
      if (!is_string($entry) || $entry === '.' || $entry === '..') {
        continue;
      }

      $path = $dir . '/' . $entry;
      if (is_dir($path)) {
        if (self::hasDataFiles($path)) {
          return true;
        }
        continue;
      }

      if (!in_array($entry, ['.htaccess', 'index.html', 'index.php', 'web.config'], true)) {
        return true;
      }
    }

    return false;
  }

  private static function uploadsDir(): string {
    $uploads = wp_upload_dir(null, false);

    return rtrim((string) $uploads['basedir'], '/\\');
  }
}
