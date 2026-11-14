<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Support\CredentialScrubber;
use FlowSystems\WebhookActions\Support\PrivateStorage;

/**
 * Removes passwords that earlier versions already stored.
 *
 * Since 3.4.3 a password never reaches the payload (see CredentialScrubber).
 * This is the other half: rows and files written before the update still hold
 * what was captured then.
 *
 * Captured examples and the queue are small and are cleaned first, in the
 * upgrade request itself. The delivery log can be large, so it is walked in id
 * order a slice at a time, and the archive one day file at a time, on the
 * every-minute queue tick until both are done. Progress is kept in one option.
 *
 * Every step is safe to repeat: scrubbing a clean row changes nothing.
 */
class StoredCredentialCleanup {
  public const OPTION = 'fswa_credential_cleanup';

  /** Rows looked at per query. Bounds one query's work on a large log table. */
  private const LOG_WINDOW = 1000;

  /** Text that has to appear in a row before it is worth decoding. */
  private const NEEDLES = ['pass', 'pwd', 'pswd', 'activation_key'];

  private const NEEDLE_PATTERN = '/pass|pwd|pswd|activation_key/i';

  /**
   * Begin a cleanup. Called from the 2.6.0 migration. The state is written
   * before any work is done, so whatever this request does not finish (or
   * fails at) is picked up by the queue tick.
   */
  public static function start(): void {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $maxId = (int) $wpdb->get_var("SELECT MAX(id) FROM {$wpdb->prefix}fswa_logs");

    update_option(self::OPTION, [
      'stage'       => 'tables',
      'log_cursor'  => 0,
      // Rows above this id were written by the new code and are already clean.
      'log_max'     => $maxId,
      'file_cursor' => '',
      'started_at'  => time(),
    ], true);

    // A couple of seconds now, so a small site is clean before the first page
    // loads. The captured examples and the queue always finish in this request.
    (new self())->run(2.0);
  }

  /**
   * Go over the archive files again. Called when files from the old location
   * arrive after the cleanup had finished: a move that failed during the
   * update and succeeded on a later run.
   */
  public static function requeueArchive(): void {
    $state = get_option(self::OPTION, null);

    if (is_array($state) && ($state['stage'] ?? '') === 'done') {
      $state['stage']       = 'archive';
      $state['file_cursor'] = '';
      unset($state['finished_at']);
      update_option(self::OPTION, $state, true);
    }
  }

  public static function isPending(): bool {
    $state = get_option(self::OPTION, null);

    return is_array($state) && ($state['stage'] ?? 'done') !== 'done';
  }

  /**
   * Queue-tick callback: one time-boxed slice of work.
   */
  public static function tick(): void {
    if (self::isPending()) {
      (new self())->run(8.0);
    }
  }

  /**
   * Work until the time budget is spent or nothing is left.
   */
  public function run(float $seconds): void {
    $deadline = microtime(true) + $seconds;
    $state    = get_option(self::OPTION, null);

    if (!is_array($state)) {
      return;
    }

    while (($state['stage'] ?? 'done') !== 'done' && microtime(true) < $deadline) {
      if ($state['stage'] === 'tables') {
        // Small tables, done in one go. The queue matters most: a job that was
        // waiting during the update would otherwise deliver the old payload.
        $this->cleanSchemas();
        $this->cleanQueue();
        $state['stage'] = 'logs';
      } elseif ($state['stage'] === 'logs') {
        $state = $this->logSlice($state);
      } elseif ($state['stage'] === 'archive') {
        $state = $this->archiveFile($state);
      } else {
        $state['stage'] = 'done';
      }

      update_option(self::OPTION, $state, true);
    }

    if (($state['stage'] ?? '') === 'done' && empty($state['finished_at'])) {
      $state['finished_at'] = time();
      // Finished: nothing reads this on a normal request any more.
      update_option(self::OPTION, $state, false);
    }
  }

  /**
   * Scrub one delivery-log row. Takes and returns the raw column strings, and
   * returns only the columns that changed.
   *
   * @param array<string, mixed> $row trigger_name, request_payload, original_payload, response_body, attempt_history
   * @return array<string, string>
   */
  public static function scrubLogRow(array $row): array {
    $hook     = is_string($row['trigger_name'] ?? null) ? $row['trigger_name'] : '';
    $scrubber = new CredentialScrubber();
    $changed  = [];
    $decoded  = [];

    // Pass 1, by name and position. The pre-mapping payload goes first: it is
    // the one that still has `user_pass` under its own key, and what is found
    // there is what pass 2 looks for everywhere else.
    foreach (['original_payload', 'request_payload'] as $column) {
      $data = is_string($row[$column] ?? null) ? json_decode($row[$column], true) : null;
      if (is_array($data)) {
        $decoded[$column] = $scrubber->envelope($data, $hook);
      }
    }

    $response = is_string($row['response_body'] ?? null) ? $scrubber->value($row['response_body']) : null;

    $history = is_string($row['attempt_history'] ?? null) ? json_decode($row['attempt_history'], true) : null;
    if (is_array($history)) {
      foreach ($history as $i => $attempt) {
        if (is_array($attempt) && is_string($attempt['response_body'] ?? null)) {
          $history[$i]['response_body'] = $scrubber->value($attempt['response_body']);
        }
      }
    }

    // Pass 2, by value: the same password under a name a field mapping gave it,
    // or echoed back by the endpoint.
    $secrets = $scrubber->removed();

    foreach ($decoded as $column => $data) {
      $data = $scrubber->removeKnown($data, $secrets);
      $json = wp_json_encode($data);
      if ($json !== false && $data !== json_decode($row[$column], true)) {
        $changed[$column] = $json;
      }
    }

    if ($response !== null) {
      $response = $scrubber->removeKnown($response, $secrets);
      if ($response !== $row['response_body']) {
        $changed['response_body'] = (string) $response;
      }
    }

    if (is_array($history)) {
      $history = $scrubber->removeKnown($history, $secrets);
      if ($history !== json_decode($row['attempt_history'], true)) {
        $json = wp_json_encode($history);
        if ($json !== false) {
          $changed['attempt_history'] = $json;
        }
      }
    }

    return $changed;
  }

  /**
   * Captured example payloads. One row per webhook and trigger.
   */
  private function cleanSchemas(): void {
    global $wpdb;

    $table = $wpdb->prefix . 'fswa_trigger_schemas';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $rows = $wpdb->get_results("SELECT id, trigger_name, example_payload FROM {$table} WHERE example_payload IS NOT NULL AND example_payload <> ''", ARRAY_A) ?: [];

    foreach ($rows as $row) {
      if (!self::worthDecoding((string) $row['example_payload'], (string) $row['trigger_name'])) {
        continue;
      }

      $data = json_decode((string) $row['example_payload'], true);
      if (!is_array($data)) {
        continue;
      }

      $clean = (new CredentialScrubber())->envelope($data, (string) $row['trigger_name']);
      if ($clean !== $data) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update($table, ['example_payload' => wp_json_encode($clean)], ['id' => (int) $row['id']]);
      }
    }
  }

  /**
   * Queue jobs, waiting ones included. A job is `{webhook, payload,
   * original_payload, ...}`; only the two payloads are touched, never the
   * webhook's own settings.
   */
  private function cleanQueue(): void {
    global $wpdb;

    $table  = $wpdb->prefix . 'fswa_queue';
    $lastId = 0;

    do {
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
      $rows = $wpdb->get_results(
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->prepare("SELECT id, trigger_name, payload FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT 200", $lastId),
        ARRAY_A
      ) ?: [];

      foreach ($rows as $row) {
        $lastId = (int) $row['id'];

        if (!self::worthDecoding((string) $row['payload'], (string) $row['trigger_name'])) {
          continue;
        }

        $job = json_decode((string) $row['payload'], true);
        if (!is_array($job)) {
          continue;
        }

        $scrubber = new CredentialScrubber();
        $clean    = $job;
        foreach (['original_payload', 'payload'] as $key) {
          if (is_array($clean[$key] ?? null)) {
            $clean[$key] = $scrubber->envelope($clean[$key], (string) $row['trigger_name']);
          }
        }
        foreach (['original_payload', 'payload'] as $key) {
          if (is_array($clean[$key] ?? null)) {
            $clean[$key] = $scrubber->removeKnown($clean[$key], $scrubber->removed());
          }
        }

        if ($clean !== $job) {
          // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
          $wpdb->update($table, ['payload' => wp_json_encode($clean)], ['id' => $lastId]);
        }
      }
    } while (count($rows) === 200);
  }

  /**
   * One window of the delivery log.
   *
   * @param array<string, mixed> $state
   * @return array<string, mixed>
   */
  private function logSlice(array $state): array {
    global $wpdb;

    $table = $wpdb->prefix . 'fswa_logs';
    $from  = (int) ($state['log_cursor'] ?? 0);
    $max   = (int) ($state['log_max'] ?? 0);

    if ($from >= $max) {
      $state['stage'] = 'archive';
      return $state;
    }

    $to = min($max, $from + self::LOG_WINDOW);

    // Let the database skip the rows that cannot hold anything, so the large
    // text columns of ordinary deliveries never leave it.
    $likes  = [];
    $params = [$from, $to];
    foreach (['request_payload', 'original_payload', 'response_body', 'attempt_history'] as $column) {
      foreach (self::NEEDLES as $needle) {
        $likes[]  = "{$column} LIKE %s";
        $params[] = '%' . $wpdb->esc_like($needle) . '%';
      }
    }
    foreach (CredentialScrubber::positionalHooks() as $hook) {
      $likes[]  = 'trigger_name = %s';
      $params[] = $hook;
    }

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT id, trigger_name, request_payload, original_payload, response_body, attempt_history
           FROM {$table}
          WHERE id > %d AND id <= %d AND (" . implode(' OR ', $likes) . ')',
        $params
      ),
      ARRAY_A
    ) ?: [];
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

    foreach ($rows as $row) {
      $changed = self::scrubLogRow($row);
      if ($changed !== []) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update($table, $changed, ['id' => (int) $row['id']]);
      }
    }

    $state['log_cursor'] = $to;

    return $state;
  }

  /**
   * One archive day file, rewritten line by line. Files are taken in name
   * order and the cursor is the last one finished.
   *
   * @param array<string, mixed> $state
   * @return array<string, mixed>
   */
  private function archiveFile(array $state): array {
    $dir    = PrivateStorage::path(LogArchiver::STORAGE_DIR);
    $cursor = (string) ($state['file_cursor'] ?? '');

    $files = [];
    foreach (glob($dir . '/????-??/*.json') ?: [] as $file) {
      $files[] = basename(dirname($file)) . '/' . basename($file);
    }
    sort($files);

    $next = null;
    foreach ($files as $relative) {
      if (strcmp($relative, $cursor) > 0) {
        $next = $relative;
        break;
      }
    }

    if ($next === null) {
      $state['stage'] = 'done';
      return $state;
    }

    self::scrubArchiveFile($dir . '/' . $next);
    $state['file_cursor'] = $next;

    return $state;
  }

  /**
   * Rewrite one archive file (a log row per line) without the passwords in it.
   * Left untouched when no line needed changing.
   */
  public static function scrubArchiveFile(string $file): void {
    // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Line by line on purpose: a day file can be far larger than the memory limit, and WP_Filesystem only reads whole files.
    $in = @fopen($file, 'rb');
    if (!$in) {
      return;
    }

    $tmp = $file . '.tmp';
    $out = @fopen($tmp, 'wb');
    if (!$out) {
      fclose($in);
      return;
    }

    $dirty = false;
    while (($line = fgets($in)) !== false) {
      if (preg_match(self::NEEDLE_PATTERN, $line) || self::lineHasPositionalHook($line)) {
        $row = json_decode($line, true);
        if (is_array($row)) {
          $changed = self::scrubLogRow($row);
          if ($changed !== []) {
            $line  = wp_json_encode(array_merge($row, $changed)) . "\n";
            $dirty = true;
          }
        }
      }
      fwrite($out, $line);
    }

    fclose($in);
    fclose($out);
    // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

    if ($dirty) {
      // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic replace on the same filesystem; WP_Filesystem::move() copies.
      if (!@rename($tmp, $file)) {
        wp_delete_file($tmp);
      }
      PrivateStorage::adopt($file);
    } else {
      wp_delete_file($tmp);
    }
  }

  private static function lineHasPositionalHook(string $line): bool {
    foreach (CredentialScrubber::positionalHooks() as $hook) {
      if (strpos($line, '"' . $hook . '"') !== false) {
        return true;
      }
    }

    return false;
  }

  /**
   * Can this stored JSON hold anything the scrubber would remove?
   */
  private static function worthDecoding(string $json, string $hook): bool {
    return (bool) preg_match(self::NEEDLE_PATTERN, $json) || in_array($hook, CredentialScrubber::positionalHooks(), true);
  }
}
