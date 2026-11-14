<?php

namespace FlowSystems\WebhookActions\Services\Ai;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Support\PrivateStorage;

/**
 * Developer trace log for the AI Builder.
 *
 * When enabled, records the exact input/output of every LLM call — the system
 * prompt, the conversation turns we sent, and the model's raw response — as one
 * JSON object per line (JSONL), in a per-day file in the plugin's private
 * storage folder (see PrivateStorage).
 * This is the window you need to iterate on prompts: you cannot tune what the
 * model does until you can see what it received and returned.
 *
 * Off by default since 3.4.3. It used to be on, so that a build's history was
 * already there when a user reported a problem. But a trace holds prompts and
 * payload data, and most sites never open a support request: keeping that for
 * everyone, unasked, is not worth one saved round trip. Support now asks for
 * the switch to be turned on and the build repeated. A site that turned
 * logging on itself keeps it on.
 * Toggled via the `fswa_ai_debug` option (set from the trace panel's "Logging"
 * switch) or forced by defining the `FSWA_AI_DEBUG` constant. The panel that
 * surfaces the log is still shown only under the Vite dev server, or in
 * production when the site enables "AI Dev Trace" in Settings (the separate
 * `fswa_ai_trace_enabled` option). The folder name is random and stored only in
 * the database — until 3.4.3 the files sat at `uploads/fswa-ai-logs/<date>.jsonl`
 * behind an .htaccess, which nginx and Caddy do not read. Traces can contain
 * prompt/payload data — a site can opt out from the panel's "Logging" switch,
 * and old day files are pruned by {@see self::prune()}.
 */
class AgentTraceLog {
  /** Folder inside the plugin's private storage. */
  public const STORAGE_DIR   = 'ai-traces';
  private const OPTION       = 'fswa_ai_debug';
  private const TAIL_BYTES   = 512 * 1024; // Read at most the last 512 KB per file.
  private const RETAIN_DAYS  = 30;         // Prune day files older than this.

  /** Default for the logging option when the site has never set it. */
  public const DEFAULT_ENABLED = false;

  /**
   * Is trace logging on? True if the constant is set, or the option is enabled.
   * Off (see class docblock) until a site turns it on.
   */
  public function isEnabled(): bool {
    if (defined('FSWA_AI_DEBUG') && FSWA_AI_DEBUG) {
      return true;
    }
    return (bool) get_option(self::OPTION, self::DEFAULT_ENABLED);
  }

  /**
   * Turn trace logging on or off (persists in the option).
   */
  public function setEnabled(bool $enabled): void {
    // 1/0, not a boolean: update_option() skips writing `false` to an option
    // that does not exist yet.
    update_option(self::OPTION, $enabled ? 1 : 0, false);
  }

  /**
   * Append one trace entry to today's log file. No-op when disabled or when the
   * directory can't be prepared. Never throws — logging must not break a chat.
   *
   * @param array<string, mixed> $entry
   */
  public function record(array $entry): void {
    if (!$this->isEnabled()) {
      return;
    }

    $dir = $this->ensureDir();
    if ($dir === null) {
      return;
    }

    $entry = array_merge(['ts' => gmdate('c')], $entry);
    $line  = wp_json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($line === false) {
      return;
    }

    $file = $dir . '/' . gmdate('Y-m-d') . '.jsonl';
    // First trace of the day: prune stale files so the folder can't grow
    // without bound. Cheap — runs at most once per day. The daily cleanup cron
    // prunes too, which is what covers a site where logging is now off.
    if (!file_exists($file)) {
      $this->prune();
    }
    // A day file created by another OS user (e.g. a root wp-cli session) can
    // leave the web user unable to append — fall back to a per-user sibling
    // instead of dropping traces silently for the rest of the day. recent()
    // globs *.jsonl, so fallback files show up in the panel alongside.
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- WP_Filesystem would need credentials (and may prompt) for a best-effort debug log; a plain permission probe is the right tool here.
    if (file_exists($file) && !is_writable($file)) {
      $uid  = function_exists('posix_geteuid') ? (string) posix_geteuid() : 'alt';
      $file = $dir . '/' . gmdate('Y-m-d') . '-u' . $uid . '.jsonl';
    }
    file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    PrivateStorage::adopt($file);
  }

  /**
   * The most recent trace entries, newest first, across the latest day files.
   *
   * @param int $limit
   * @return array<int, array<string, mixed>>
   */
  public function recent(int $limit = 50): array {
    $dir = $this->dir();
    if (!is_dir($dir)) {
      return [];
    }

    $files = glob($dir . '/*.jsonl') ?: [];
    rsort($files); // Latest day first (filenames are date-sorted).

    $entries = [];
    foreach ($files as $file) {
      // Reverse per file (lines are appended oldest-first) so the combined list
      // stays globally newest-first across day boundaries.
      foreach (array_reverse($this->readLines($file)) as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
          $entries[] = $decoded;
        }
      }
      if (count($entries) >= $limit) {
        break;
      }
    }

    return array_slice($entries, 0, $limit);
  }

  /**
   * Delete all trace files. Returns the number of files removed.
   */
  public function clear(): int {
    $dir = $this->dir();
    if (!is_dir($dir)) {
      return 0;
    }
    $removed = 0;
    foreach (glob($dir . '/*.jsonl') ?: [] as $file) {
      wp_delete_file($file);
      // wp_delete_file() returns nothing, so ask the filesystem whether it worked.
      if (!file_exists($file)) {
        $removed++;
      }
    }
    return $removed;
  }

  /**
   * Delete day files older than RETAIN_DAYS, keyed off the date in the filename
   * (robust against filemtime being touched). Best-effort; never throws.
   */
  public function prune(): void {
    $dir = $this->dir();
    if (!is_dir($dir)) {
      return;
    }
    $cutoff = strtotime('-' . self::RETAIN_DAYS . ' days');
    if ($cutoff === false) {
      return;
    }
    foreach (glob($dir . '/*.jsonl') ?: [] as $file) {
      if (preg_match('/(\d{4}-\d{2}-\d{2})/', basename($file), $m)) {
        $ts = strtotime($m[1]);
        if ($ts !== false && $ts < $cutoff) {
          wp_delete_file($file);
        }
      }
    }
  }

  /**
   * Absolute path of the trace directory (may not exist yet).
   */
  public function dir(): string {
    return PrivateStorage::path(self::STORAGE_DIR);
  }

  /**
   * Create the trace directory with its web-access guards. Returns the path,
   * or null if it could not be created.
   */
  private function ensureDir(): ?string {
    $dir = $this->dir();

    return is_dir($dir) ? $dir : PrivateStorage::ensure(self::STORAGE_DIR);
  }

  /**
   * Read the tail of a file and split into non-empty lines, oldest first.
   *
   * @return array<int, string>
   */
  private function readLines(string $file): array {
    $size = @filesize($file);
    if ($size === false) {
      return [];
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Deliberate: only the last TAIL_BYTES are read, and WP_Filesystem can only pull a whole file into memory.
    $handle = @fopen($file, 'rb');
    if (!$handle) {
      return [];
    }
    if ($size > self::TAIL_BYTES) {
      fseek($handle, -self::TAIL_BYTES, SEEK_END);
      fgets($handle); // Discard the first (likely partial) line.
    }
    $lines = [];
    while (($line = fgets($handle)) !== false) {
      $line = trim($line);
      if ($line !== '') {
        $lines[] = $line;
      }
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Pairs with the fopen() above.
    fclose($handle);
    return $lines;
  }
}
