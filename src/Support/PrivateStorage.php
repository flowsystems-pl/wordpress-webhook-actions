<?php

namespace FlowSystems\WebhookActions\Support;

defined('ABSPATH') || exit;

/**
 * The folder this plugin keeps its own files in: archived delivery logs and
 * the AI Builder trace log.
 *
 * Both used to sit at fixed paths under uploads (`fswa-logs/archive/`,
 * `fswa-ai-logs/`) behind an .htaccess. That file only means something to
 * Apache. nginx, Caddy and IIS ignore it and served the files to anyone who
 * asked, and the file names were dates, so the URLs could be guessed.
 *
 * The folder name now contains a random token that is stored in the database
 * and never printed in a page. The path is:
 *
 *   uploads/fswa-private/<32 hex characters>/
 *
 * The token sits one level down so that a server with directory listing on
 * for uploads shows only `fswa-private`, whose own listing is covered by the
 * index files written here. The .htaccess and web.config are still written,
 * as a second layer on the servers that read them.
 *
 * A site that wants the files outside the web root altogether can point the
 * `fswa_private_storage_dir` filter at any writable directory.
 */
class PrivateStorage {
  public const OPTION = 'fswa_storage_token';

  private const BASE_DIR = 'fswa-private';

  /**
   * Absolute path of the storage root, or of a folder inside it. Nothing is
   * created.
   *
   * @param string $sub Folder inside the root, e.g. "log-archive".
   */
  public static function path(string $sub = ''): string {
    $uploads = wp_upload_dir(null, false);
    $root    = rtrim((string) $uploads['basedir'], '/\\') . '/' . self::BASE_DIR . '/' . self::token();

    /**
     * Move the plugin's private files somewhere else, for example a directory
     * outside the web root.
     *
     * @param string $root Absolute path, no trailing slash.
     */
    $filtered = apply_filters('fswa_private_storage_dir', $root);
    if (is_string($filtered) && $filtered !== '') {
      $root = rtrim($filtered, '/\\');
    }

    $sub = trim($sub, '/\\');

    return $sub === '' ? $root : $root . '/' . $sub;
  }

  /**
   * Create a folder inside the storage root with its guard files, and return
   * its path. Null when it cannot be created.
   */
  public static function ensure(string $sub = ''): ?string {
    $root = self::path();
    $dir  = self::path($sub);

    if (!is_dir($dir) && !wp_mkdir_p($dir)) {
      return null;
    }

    // Every level from the fixed base folder down to the one asked for.
    $levels = [dirname($root), $root];
    $walk   = $root;
    foreach (array_filter(explode('/', trim($sub, '/\\'))) as $segment) {
      $walk    .= '/' . $segment;
      $levels[] = $walk;
    }

    foreach ($levels as $level) {
      self::adopt($level);
      self::guard($level);
    }

    return $dir;
  }

  /**
   * Hand a file or folder this process just created to the owner of the
   * uploads directory, when this process is root.
   *
   * Updates are often finished from wp-cli run as root (`docker exec … wp`,
   * a root crontab). Whatever that creates belongs to root, and the web
   * server's PHP then cannot write the next archive file or build a download
   * ZIP next to it. Does nothing for any other user, or without the posix
   * extension.
   */
  public static function adopt(string $path): void {
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0 || !file_exists($path)) {
      return;
    }

    $uploads = wp_upload_dir(null, false);
    $owner   = @fileowner((string) $uploads['basedir']);
    $group   = @filegroup((string) $uploads['basedir']);

    if ($owner === false || $owner === 0 || @fileowner($path) === $owner) {
      return;
    }

    // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_chown, WordPress.WP.AlternativeFunctions.file_system_operations_chgrp -- WP_Filesystem has no credentials in cron or wp-cli; see guard().
    @chown($path, $owner);
    if ($group !== false) {
      @chgrp($path, $group);
    }
    // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_chown, WordPress.WP.AlternativeFunctions.file_system_operations_chgrp
  }

  /**
   * Write the files that stop a folder being listed or read directly, where
   * the server honours them. Safe to call on every write: existing files are
   * left alone.
   */
  public static function guard(string $dir): void {
    if (!is_dir($dir)) {
      return;
    }

    $files = [
      'index.php'  => "<?php\n// Silence is golden.\n",
      'index.html' => '',
      '.htaccess'  => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n",
      'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <authorization>\n      <deny users=\"*\" />\n    </authorization>\n  </system.webServer>\n</configuration>\n",
    ];

    foreach ($files as $name => $contents) {
      $file = $dir . '/' . $name;
      if (!file_exists($file)) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Runs from cron and from hook callbacks, where WP_Filesystem may need credentials it cannot ask for.
        @file_put_contents($file, $contents);
        self::adopt($file);
      }
    }
  }

  /**
   * Delete the storage root and everything in it. Used on uninstall.
   */
  public static function destroy(): void {
    if (get_option(self::OPTION, '') === '') {
      return;
    }

    self::deleteTree(self::path());

    // The fixed base folder goes too once nothing else is in it.
    $base = dirname(self::path());
    if (basename($base) === self::BASE_DIR) {
      $left = array_diff((array) @scandir($base), ['.', '..', 'index.php', 'index.html', '.htaccess', 'web.config']);
      if ($left === []) {
        self::deleteTree($base);
      }
    }

    delete_option(self::OPTION);
  }

  /**
   * Recursively delete a directory. Returns true when nothing is left.
   */
  public static function deleteTree(string $dir): bool {
    if (!is_dir($dir)) {
      return true;
    }

    foreach ((array) @scandir($dir) as $entry) {
      if ($entry === '.' || $entry === '..' || !is_string($entry)) {
        continue;
      }
      $path = $dir . '/' . $entry;
      if (is_dir($path) && !is_link($path)) {
        self::deleteTree($path);
      } else {
        wp_delete_file($path);
      }
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- See guard(): no WP_Filesystem credentials in cron.
    return @rmdir($dir);
  }

  /**
   * The random part of the folder name. Created on first use.
   */
  private static function token(): string {
    $token = get_option(self::OPTION, '');
    if (is_string($token) && preg_match('/^[a-f0-9]{32}$/', $token)) {
      return $token;
    }

    try {
      $token = bin2hex(random_bytes(16));
    } catch (\Exception $e) {
      $token = md5(wp_generate_password(64, true, true) . microtime(true));
    }

    // Not autoloaded: it is only read when a file is about to be touched.
    // add_option() so that two requests creating it at once agree on one value.
    add_option(self::OPTION, $token, '', false);
    $stored = get_option(self::OPTION, $token);

    return is_string($stored) && preg_match('/^[a-f0-9]{32}$/', $stored) ? $stored : $token;
  }
}
