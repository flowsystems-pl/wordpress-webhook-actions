<?php

namespace FlowSystems\WebhookActions\Services\Ai;

defined('ABSPATH') || exit;

/**
 * Developer trace log for the AI Builder.
 *
 * When enabled, records the exact input/output of every LLM call — the system
 * prompt, the conversation turns we sent, and the model's raw response — as one
 * JSON object per line (JSONL), in a per-day file under the uploads directory.
 * This is the window you need to iterate on prompts: you cannot tune what the
 * model does until you can see what it received and returned.
 *
 * Off by default. Toggled via the `fswa_ai_debug` option (set from the dev panel,
 * which only renders when the SPA runs from the Vite dev server) or by defining
 * the `FSWA_AI_DEBUG` constant. The log dir is protected from web access; even so,
 * traces can contain prompt/payload data, so this is a development aid, not a
 * production feature.
 */
class AgentTraceLog {
  private const DIR_NAME    = 'fswa-ai-logs';
  private const OPTION      = 'fswa_ai_debug';
  private const TAIL_BYTES  = 512 * 1024; // Read at most the last 512 KB per file.

  /**
   * Is trace logging on? True if the constant is set, or the option is enabled.
   */
  public function isEnabled(): bool {
    if (defined('FSWA_AI_DEBUG') && FSWA_AI_DEBUG) {
      return true;
    }
    return (bool) get_option(self::OPTION, false);
  }

  /**
   * Turn trace logging on or off (persists in the option).
   */
  public function setEnabled(bool $enabled): void {
    update_option(self::OPTION, $enabled, false);
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
    file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
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
      foreach ($this->readLines($file) as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
          $entries[] = $decoded;
        }
      }
      if (count($entries) >= $limit) {
        break;
      }
    }

    // Newest first, capped.
    $entries = array_reverse($entries);
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
      if (@unlink($file)) {
        $removed++;
      }
    }
    return $removed;
  }

  /**
   * Absolute path of the trace directory (may not exist yet).
   */
  public function dir(): string {
    $uploads = wp_upload_dir();
    return rtrim($uploads['basedir'], '/') . '/' . self::DIR_NAME;
  }

  /**
   * Create the trace directory (once) and drop in web-access guards. Returns the
   * path, or null if it could not be created.
   */
  private function ensureDir(): ?string {
    $dir = $this->dir();
    if (!is_dir($dir)) {
      if (!wp_mkdir_p($dir)) {
        return null;
      }
      // Block directory listing / direct file access where the server honours it.
      @file_put_contents($dir . '/index.html', '');
      @file_put_contents($dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
    return $dir;
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
    fclose($handle);
    return $lines;
  }
}
