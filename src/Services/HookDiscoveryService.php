<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

/**
 * Discovers do_action() hook names by statically scanning active plugin and theme PHP files.
 * Results are cached in a transient and busted when plugins or theme change.
 */
class HookDiscoveryService {

  const CACHE_KEY = 'fswa_discovered_hooks_v3';
  const FILTERS_CACHE_KEY = 'fswa_discovered_filters_v1';
  const PREFIX_CACHE_KEY = 'fswa_discovered_hooks_prefix_map_v1';
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

    $this->scanAndCache();

    return get_transient(self::CACHE_KEY) ?: [];
  }

  /**
   * Set of hook names statically confirmed to be fired via apply_filters() /
   * apply_filters_ref_array() somewhere in an active plugin/theme/core file.
   * Used purely as a safety exclusion: a hook proven to be a filter must never
   * be proposed as a webhook trigger, since add_action()-ing a filter can
   * swallow/null out its return value and corrupt whatever it was filtering.
   *
   * @return array<string, true>
   */
  public function discoverKnownFilters(): array {
    $cached = get_transient(self::FILTERS_CACHE_KEY);
    if (is_array($cached)) {
      return $cached;
    }

    $this->scanAndCache();

    return get_transient(self::FILTERS_CACHE_KEY) ?: [];
  }

  /**
   * Scan every active plugin/theme/core PHP file once and cache both the
   * action map and the known-filters set from that single pass.
   */
  private function scanAndCache(): void {
    $actions = [];
    $filters = [];

    foreach ($this->getFilesToScan() as $file => $slug) {
      $content = @file_get_contents($file);
      if ($content === false) {
        continue;
      }

      foreach ($this->extractNames($content, 'do_action') as $hookName) {
        if (!isset($actions[$hookName])) {
          $actions[$hookName] = $slug;
        }
      }

      foreach ($this->extractNames($content, 'apply_filters') as $hookName) {
        $filters[$hookName] = true;
      }
    }

    ksort($actions);

    set_transient(self::CACHE_KEY, $actions, self::CACHE_TTL);
    set_transient(self::FILTERS_CACHE_KEY, $filters, self::CACHE_TTL);
  }

  /**
   * Clear the discovery cache (call on plugin activate/deactivate/theme switch).
   */
  public static function clearCache(): void {
    delete_transient(self::CACHE_KEY);
    delete_transient(self::FILTERS_CACHE_KEY);
    delete_transient(self::PREFIX_CACHE_KEY);
  }

  /**
   * Single source of truth for "hooks worth exposing as webhook triggers" —
   * shared by the AI list_triggers ability and the manual trigger-picker UI so
   * the two can never again see a different hook set (that divergence is what
   * let gform_after_submission stay invisible to the AI while merely
   * mis-categorized in the UI).
   *
   * Merges statically-discovered hooks with runtime-registered hooks
   * ($wp_filter) the static scanner missed — e.g. a hook fired through a
   * plugin's own wrapper around do_action() (Gravity Forms' gf_do_action()),
   * where no literal do_action('name', ...) call exists anywhere for the
   * regex to find. Every hook is checked against two safety filters before
   * inclusion: it must not match an excluded pattern (internal WP/admin
   * mechanics, not sensible as a trigger), and it must not be statically
   * confirmed to fire via apply_filters() elsewhere — $wp_filter mixes
   * actions and filters indiscriminately, and add_action()-ing a filter risks
   * swallowing/corrupting its return value.
   *
   * Each hook maps to its best-guess slug (exact static match, else prefix
   * inference), or null when no plugin/theme could be attributed at all —
   * callers that need a slug for every entry (e.g. the AI, which reasons
   * about "which plugin owns this hook") should filter those out; callers
   * that want to show every viable hook regardless (e.g. the UI, falling
   * back to a keyword-guessed category) can keep them.
   *
   * @return array<string, string|null>
   */
  public function discoverAllTriggerable(): array {
    $hooks = [];
    foreach ($this->discover() as $hookName => $slug) {
      if (!$this->isExcludedHook($hookName)) {
        $hooks[$hookName] = $slug;
      }
    }

    $knownFilters = $this->discoverKnownFilters();

    global $wp_filter;
    foreach (array_keys($wp_filter ?? []) as $hookName) {
      $hookName = (string) $hookName;
      if (isset($hooks[$hookName]) || isset($knownFilters[$hookName]) || $this->isExcludedHook($hookName)) {
        continue;
      }
      $hooks[$hookName] = $this->resolveSlug($hookName);
    }

    ksort($hooks);
    return $hooks;
  }

  /**
   * Confidently-attributed subset of discoverAllTriggerable() — every entry
   * has a real slug. Used by the AI ability: an unattributed hook name alone
   * ("what plugin is this?") isn't useful context for the model and would
   * just burn its read budget.
   *
   * @return array<string, string>
   */
  public function discoverWithRuntimeHooks(): array {
    return array_filter(
      $this->discoverAllTriggerable(),
      static fn(?string $slug): bool => $slug !== null
    );
  }

  /**
   * Patterns for hook names that are internal WordPress/plugin mechanics and
   * never sensible as a webhook trigger (admin-ajax callbacks, sanitizers,
   * template partials, etc.), plus generic filter-name suffixes as a second
   * safety net alongside discoverKnownFilters().
   *
   * @return string[]
   */
  private function getExcludedHookPatterns(): array {
    return [
      '/^_/',
      '/^admin_/',
      '/^wp_ajax/',
      '/^rest_api/',
      '/^oembed/',
      '/^customize_/',
      '/^wp_head$/',
      '/^wp_footer$/',
      '/^wp_enqueue/',
      '/^admin_enqueue/',
      '/^login_/',
      '/^register_/',
      '/^widgets_/',
      '/^sidebar/',
      '/^dynamic_sidebar/',
      '/^get_header/',
      '/^get_footer/',
      '/^get_sidebar/',
      '/^template_/',
      '/^the_content$/',
      '/^the_title$/',
      '/^the_excerpt$/',
      '/^body_class$/',
      '/^post_class$/',
      '/^comment_class$/',
      '/^nav_menu/',
      '/^wp_nav_menu/',
      '/^pre_get/',
      '/^posts_/',
      '/^query$/',
      '/^parse_/',
      '/^sanitize_/',
      '/^clean_/',
      '/^check_/',
      '/^is_/',
      '/^load-/',
      '/^print_/',
      '/^show_/',
      '/^display_/',
      '/^render_/',
      '/^do_/',
      '/^doing_/',
      '/^current_/',
      '/^get_/',
      '/^update_/',
      '/^remove_/',
      '/^has_/',
      '/^can_/',
      '/^woocommerce_before/',
      '/^woocommerce_after/',
      // Filter hooks (usually not useful as triggers)
      '/_filter$/',
      '/_filters$/',
    ];
  }

  /**
   * Check if hook matches excluded patterns.
   */
  private function isExcludedHook(string $hookName): bool {
    foreach ($this->getExcludedHookPatterns() as $pattern) {
      if (preg_match($pattern, $hookName)) {
        return true;
      }
    }
    return false;
  }

  /**
   * Best-guess source slug for a hook name: exact match from the static scan
   * first, then prefix inference (e.g. "gform_after_submission" -> "gravityforms"
   * because "gform_entry_created" was found there via a literal do_action() call).
   */
  public function resolveSlug(string $hookName): ?string {
    $hooks = $this->discover();
    if (isset($hooks[$hookName])) {
      return $hooks[$hookName];
    }

    $prefix = $this->extractPrefix($hookName);
    if ($prefix === null) {
      return null;
    }

    return $this->getPrefixMap()[$prefix] ?? null;
  }

  /**
   * Map of hook-name prefix => most common source slug, built from hooks whose
   * plugin/theme origin was already established by static scanning. Used to
   * attribute hooks the scanner couldn't see directly (fired via a wrapper
   * function) back to the plugin that actually owns them.
   *
   * @return array<string, string>
   */
  public function getPrefixMap(): array {
    $cached = get_transient(self::PREFIX_CACHE_KEY);
    if (is_array($cached)) {
      return $cached;
    }

    $tally = [];
    foreach ($this->discover() as $hookName => $slug) {
      $prefix = $this->extractPrefix($hookName);
      if ($prefix === null) {
        continue;
      }
      $tally[$prefix][$slug] = ($tally[$prefix][$slug] ?? 0) + 1;
    }

    $map = [];
    foreach ($tally as $prefix => $slugCounts) {
      arsort($slugCounts);
      $map[$prefix] = array_key_first($slugCounts);
    }

    set_transient(self::PREFIX_CACHE_KEY, $map, self::CACHE_TTL);

    return $map;
  }

  /**
   * First underscore-delimited token of a hook name, e.g. "gform" from
   * "gform_after_submission". Requires 3+ chars so short, noisy prefixes
   * ("wp", "do") don't cross-attribute unrelated hooks.
   */
  private function extractPrefix(string $hookName): ?string {
    $prefix = explode('_', $hookName, 2)[0];
    return strlen($prefix) >= 3 ? $prefix : null;
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
   * Extract string-literal hook names from calls to the given WP function
   * (or its _ref_array variant), e.g. extractNames($content, 'do_action')
   * matches both do_action('name', ...) and do_action_ref_array('name', ...).
   *
   * @return string[]
   */
  private function extractNames(string $content, string $function): array {
    preg_match_all(
      '/' . preg_quote($function, '/') . '(?:_ref_array)?\s*\(\s*[\'"]([a-zA-Z0-9_\-\.\/]+)[\'"]/',
      $content,
      $matches
    );

    return $matches[1] ?? [];
  }
}
