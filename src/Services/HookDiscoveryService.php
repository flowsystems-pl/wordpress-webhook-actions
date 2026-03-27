<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

/**
 * Discovers do_action() hook names by statically scanning active plugin and theme PHP files.
 * Results are cached in a transient and busted when plugins or theme change.
 */
class HookDiscoveryService {

  const CACHE_KEY = 'fswa_discovered_hooks_v3';
  const CACHE_TTL = DAY_IN_SECONDS;

  /**
   * Return discovered hooks as [ hookName => sourceSlug ].
   * First plugin to define a hook wins on conflicts.
   *
   * @return array<string, string>
   */
  public function discover(): array {
    $cached = get_transient(self::CACHE_KEY);
    // Validate format: must be associative (hook => slug), not a flat list.
    if (is_array($cached) && !array_is_list($cached)) {
      return $cached;
    }

    $hooks = [];

    foreach ($this->getFilesToScan() as $file => $slug) {
      foreach ($this->extractHooksFromFile($file) as $hookName) {
        if (!isset($hooks[$hookName])) {
          $hooks[$hookName] = $slug;
        }
      }
    }

    ksort($hooks);

    set_transient(self::CACHE_KEY, $hooks, self::CACHE_TTL);

    return $hooks;
  }

  /**
   * Clear the discovery cache (call on plugin activate/deactivate/theme switch).
   */
  public static function clearCache(): void {
    delete_transient(self::CACHE_KEY);
  }

  /**
   * Collect PHP files mapped to their source slug.
   * Plugins: dirname of the plugin entry file (e.g. "contact-form-7").
   * Theme: folder name from get_template() / get_stylesheet().
   *
   * @return array<string, string>  file path => source slug
   */
  private function getFilesToScan(): array {
    $files = [];
    $ownDir = realpath(dirname(FSWA_FILE));

    foreach (get_option('active_plugins', []) as $pluginFile) {
      $slug = dirname($pluginFile); // e.g. "contact-form-7"
      $pluginDir = realpath(WP_PLUGIN_DIR . '/' . $slug);

      if (!$pluginDir || $pluginDir === $ownDir) {
        continue;
      }

      foreach ($this->getPhpFiles($pluginDir) as $file) {
        $files[$file] = $slug;
      }
    }

    // WordPress core
    foreach (['wp-includes', 'wp-admin'] as $coreDir) {
      foreach ($this->getPhpFiles(ABSPATH . $coreDir) as $file) {
        $files[$file] = 'wordpress';
      }
    }

    $themeSlug = get_template();
    $themeDir = get_template_directory();
    foreach ($this->getPhpFiles($themeDir) as $file) {
      $files[$file] = $themeSlug;
    }

    $childThemeSlug = get_stylesheet();
    $childThemeDir = get_stylesheet_directory();
    if ($childThemeDir !== $themeDir) {
      foreach ($this->getPhpFiles($childThemeDir) as $file) {
        $files[$file] = $childThemeSlug;
      }
    }

    return $files;
  }

  /**
   * Recursively collect PHP files, skipping vendor/node_modules/.git.
   *
   * @return string[]
   */
  private function getPhpFiles(string $dir): array {
    if (!is_dir($dir)) {
      return [];
    }

    $skip = ['vendor', 'node_modules', '.git'];
    $files = [];

    try {
      $dirIterator = new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS);
      $filterIterator = new \RecursiveCallbackFilterIterator(
        $dirIterator,
        function (\SplFileInfo $current, mixed $_, \RecursiveIterator $iterator) use ($skip): bool {
          if ($iterator->hasChildren() && in_array($current->getFilename(), $skip, true)) {
            return false;
          }
          return true;
        }
      );

      foreach (new \RecursiveIteratorIterator($filterIterator) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
          $files[] = $file->getPathname();
        }
      }
    } catch (\Exception) {
      // Skip unreadable directories silently
    }

    return $files;
  }

  /**
   * Extract string-literal hook names from do_action() / do_action_ref_array() calls.
   *
   * @return string[]
   */
  private function extractHooksFromFile(string $file): array {
    $content = @file_get_contents($file);
    if ($content === false) {
      return [];
    }

    preg_match_all(
      '/do_action(?:_ref_array)?\s*\(\s*[\'"]([a-zA-Z0-9_\-\.\/]+)[\'"]/',
      $content,
      $matches
    );

    return $matches[1] ?? [];
  }
}
