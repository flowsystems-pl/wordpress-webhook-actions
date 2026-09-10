<?php

namespace FlowSystems\WebhookActions\Services;

use FlowSystems\WebhookActions\Support\Arr;

defined('ABSPATH') || exit;

/**
 * Discovers do_action() hook names by statically scanning active plugin and theme PHP files.
 * Results are cached in a transient and busted when plugins or theme change.
 */
class HookDiscoveryService {

  const CACHE_KEY = 'fswa_discovered_hooks_v3';
  const FILTERS_CACHE_KEY = 'fswa_discovered_filters_v2';
  const PREFIX_CACHE_KEY = 'fswa_discovered_hooks_prefix_map_v2';
  const FILTER_PATTERNS_CACHE_KEY = 'fswa_discovered_filter_patterns_v1';
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
    if (is_array($cached) && !Arr::isList($cached)) {
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
   * Regexes for filters whose hook name is built at runtime — see
   * extractDynamicFilterPatterns(). Consulted alongside discoverKnownFilters(),
   * which can only match a name that appears in full as a literal.
   *
   * @return array<int, string>
   */
  public function discoverDynamicFilterPatterns(): array {
    $cached = get_transient(self::FILTER_PATTERNS_CACHE_KEY);
    if (is_array($cached)) {
      return $cached;
    }

    $this->scanAndCache();

    return get_transient(self::FILTER_PATTERNS_CACHE_KEY) ?: [];
  }

  /**
   * Whether a concrete hook name matches any known dynamic filter pattern.
   */
  private function matchesDynamicFilter(string $hookName, array $patterns): bool {
    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $hookName)) {
        return true;
      }
    }

    return false;
  }

  /**
   * Whether a slash-namespaced hook sits UNDER a name we have confirmed to be a
   * filter — `acf/validate_field/type=text` under `acf/validate_field`.
   *
   * Plugins that namespace with `/` use the deeper segments as variations of
   * the parent hook, and a variation of a filter is a filter. Only hooks with
   * no do_action() evidence of their own ever reach this test, so the cost of
   * being wrong is one trigger nobody can pick, against a filter that silently
   * nulls whatever it was filtering.
   *
   * @param array<string, true> $knownFilters
   */
  private function isFilterVariation(string $hookName, array $knownFilters): bool {
    $cursor = $hookName;

    while (($cut = strrpos($cursor, '/')) !== false) {
      $cursor = substr($cursor, 0, $cut);

      if (isset($knownFilters[$cursor])) {
        return true;
      }
    }

    return false;
  }

  /**
   * Scan every active plugin/theme/core PHP file once and cache both the
   * action map and the known-filters set from that single pass.
   */
  private function scanAndCache(): void {
    $actions  = [];
    $filters  = [];
    $patterns = [];

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

      foreach ($this->extractDynamicFilterPatterns($content) as $pattern) {
        $patterns[$pattern] = true;
      }

      foreach ($this->extractDeclaredFilterNames($content) as $hookName) {
        $filters[$hookName] = true;
      }
    }

    // Our own directory is excluded from the scan above, so our do_action()
    // hooks are never offered as triggers — which is right, they are internal.
    // But that also meant our apply_filters() calls never reached the
    // known-filters set, while the runtime $wp_filter pass in
    // discoverAllTriggerable() still saw them the moment anything hooked one.
    //
    // The result was that fswa_payload, fswa_webhook_payload, fswa_webhook_url
    // and fswa_normalize_object were offered as triggers. Choosing one is
    // destructive, not merely useless: HooksHandler::registerTriggerHandler()
    // returns void, and on a FILTER WordPress takes the callback's return value
    // as the filtered value — so a webhook triggered on fswa_payload nulls the
    // payload of every dispatch on the site.
    //
    // Filters only. Actions from here stay out of the catalogue as before.
    foreach ($this->getOwnFiles() as $file) {
      $content = @file_get_contents($file);
      if ($content === false) {
        continue;
      }

      foreach ($this->extractNames($content, 'apply_filters') as $hookName) {
        $filters[$hookName] = true;
      }

      foreach ($this->extractDynamicFilterPatterns($content) as $pattern) {
        $patterns[$pattern] = true;
      }
    }

    ksort($actions);

    set_transient(self::CACHE_KEY, $actions, self::CACHE_TTL);
    set_transient(self::FILTERS_CACHE_KEY, $filters, self::CACHE_TTL);
    set_transient(self::FILTER_PATTERNS_CACHE_KEY, array_keys($patterns), self::CACHE_TTL);
  }

  /**
   * Our own plugin's PHP files, scanned for apply_filters() only — see
   * scanAndCache(). Kept separate from getFilesToScan() precisely because the
   * two callers want different things from this directory.
   *
   * @return array<int, string>
   */
  private function getOwnFiles(): array {
    $ownDir = defined('FSWA_FILE') ? realpath(dirname(FSWA_FILE)) : false;

    return $ownDir ? $this->getPhpFiles($ownDir) : [];
  }

  /**
   * Clear the discovery cache (call on plugin activate/deactivate/theme switch).
   */
  public static function clearCache(): void {
    delete_transient(self::CACHE_KEY);
    delete_transient(self::FILTERS_CACHE_KEY);
    delete_transient(self::FILTER_PATTERNS_CACHE_KEY);
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

    $knownFilters   = $this->discoverKnownFilters();
    $filterPatterns = $this->discoverDynamicFilterPatterns();

    global $wp_filter;
    foreach (array_keys($wp_filter ?? []) as $hookName) {
      $hookName = (string) $hookName;
      if (isset($hooks[$hookName]) || isset($knownFilters[$hookName]) || $this->isExcludedHook($hookName)) {
        continue;
      }
      // Only runtime-only hooks reach here — anything statically confirmed as a
      // do_action() is already in $hooks and was skipped above. So a match here
      // is a name we have no action evidence for that a plugin demonstrably
      // builds as a filter name, and refusing it costs at worst one trigger.
      if (
        $this->matchesDynamicFilter($hookName, $filterPatterns)
        || $this->isFilterVariation($hookName, $knownFilters)
      ) {
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
  /**
   * Regexes matching hook names that are fired via apply_filters() under a
   * name BUILT AT RUNTIME — `apply_filters( "acf/update_value/type={$field['type']}", … )`.
   *
   * extractNames() cannot see these: it only matches a name that is a complete
   * literal inside the quotes, so an interpolated name produces no match at
   * all. The base name is caught when the plugin also fires it plainly, but the
   * concrete variant the site actually runs is not — and $wp_filter hands us
   * that concrete name. ACF is the demonstration: `acf/update_value` was
   * correctly excluded while `acf/update_value/type=select` was offered as a
   * trigger, and a webhook on a filter takes the handler's void return as the
   * filtered value, so choosing it silently destroys the field being saved.
   *
   * The literal fragments become the anchors and each interpolated expression
   * becomes a wildcard, so `"woocommerce_{$type}_settings"` yields
   * `^woocommerce_.*_settings$` rather than the far blunter `^woocommerce_`.
   *
   * TWO GUARDS, because a bad pattern here silently removes triggers people
   * want, and this runs against every active plugin:
   *
   *  1. At least 10 literal characters, so `"{$a}_{$b}"` contributes nothing.
   *  2. A pattern that is only a prefix must end on a namespace separator
   *     (`/ = : . -`). Without this, `apply_filters( "gform_{$id}" )` would
   *     take out every `gform_*` action on the site. A pattern with literals on
   *     BOTH sides of a wildcard is specific enough to keep as it is.
   *
   * Statically-confirmed do_action() hooks are unaffected either way: they are
   * already in the map before the runtime pass consults these.
   *
   * @return array<int, string>
   */
  private function extractDynamicFilterPatterns(string $content): array {
    // A double-quoted first argument that contains a `$`, i.e. is interpolated.
    preg_match_all(
      '/apply_filters(?:_ref_array)?\s*\(\s*"((?:[^"\\\\]|\\\\.)*\$(?:[^"\\\\]|\\\\.)*)"/',
      $content,
      $matches
    );

    $patterns = [];

    foreach ($matches[1] ?? [] as $raw) {
      $pattern = $this->patternFromInterpolated($raw);

      if ($pattern !== null) {
        $patterns[] = $pattern;
      }
    }

    // `apply_filters( 'acf/load_field/name=' . $name, … )` — a literal head
    // glued to an expression. Same class, different syntax.
    preg_match_all(
      '/apply_filters(?:_ref_array)?\s*\(\s*\'([^\']+)\'\s*\./',
      $content,
      $concat
    );

    foreach ($concat[1] ?? [] as $head) {
      $pattern = $this->patternFromParts([$head], true);

      if ($pattern !== null) {
        $patterns[] = $pattern;
      }
    }

    return $patterns;
  }

  /**
   * Split an interpolated double-quoted hook name into its literal parts and
   * hand them to patternFromParts().
   */
  private function patternFromInterpolated(string $raw): ?string {
    // `{$field['type']}` / `{$obj->prop}` / `$var` / `$arr[0]` / `$obj->prop`.
    $parts = preg_split(
      '/\{\$.*?\}|\$[A-Za-z_][A-Za-z0-9_]*(?:->[A-Za-z_][A-Za-z0-9_]*|\[[^\]]*\])*/',
      $raw
    );

    if (!is_array($parts)) {
      return null;
    }

    $trailingWildcard = $parts !== [] && end($parts) === '';

    return $this->patternFromParts(array_values(array_filter($parts, static fn($p) => $p !== '')), $trailingWildcard);
  }

  /**
   * Build the anchored regex, applying both guards. Returns null when the
   * literal evidence is too thin to exclude on safely.
   *
   * @param array<int, string> $parts
   */
  private function patternFromParts(array $parts, bool $trailingWildcard): ?string {
    if ($parts === []) {
      return null;
    }

    if (strlen(implode('', $parts)) < 10) {
      return null;
    }

    // Guard 2: a prefix-only pattern needs a namespace separator to be safe.
    if (count($parts) === 1 && $trailingWildcard) {
      if (!preg_match('#[/=:.\-]$#', $parts[0])) {
        return null;
      }
    }

    $quoted = array_map(static fn(string $p): string => preg_quote($p, '#'), $parts);

    return '#^' . implode('.*', $quoted) . ($trailingWildcard ? '.*' : '') . '$#';
  }

  /**
   * Filter names a plugin DECLARES through a helper instead of firing with a
   * literal apply_filters() — ACF's
   * `acf_add_filter_variations( 'acf/validate_field', array('type'), 0 )` and
   * `acf_add_deprecated_filter( 'acf/get_valid_field', … )`. Both end up fired
   * by a generic dispatcher, so no literal call exists for extractNames() to
   * find and the name reaches us only through $wp_filter, as an action.
   *
   * Matched by SHAPE, not by plugin: an identifier containing `filter` plus one
   * of variation / deprecated / alias / register, with a literal first argument.
   * Bare `add_filter()` and `remove_filter()` are deliberately NOT matched —
   * those register a listener, and hooking a listener onto an action name is
   * common enough that treating it as proof of a filter would over-exclude.
   *
   * @return array<int, string>
   */
  private function extractDeclaredFilterNames(string $content): array {
    preg_match_all(
      '/\b[A-Za-z_][A-Za-z0-9_]*(?:variation|deprecated|alias|register)[A-Za-z0-9_]*filter[A-Za-z0-9_]*\s*\(\s*[\'"]([a-zA-Z0-9_\-\.\/]+)[\'"]'
      . '|\b[A-Za-z_][A-Za-z0-9_]*filter[A-Za-z0-9_]*(?:variation|deprecated|alias|register)[A-Za-z0-9_]*\s*\(\s*[\'"]([a-zA-Z0-9_\-\.\/]+)[\'"]/i',
      $content,
      $matches,
      PREG_SET_ORDER
    );

    $names = [];

    foreach ($matches as $match) {
      $name = $match[1] !== '' ? $match[1] : ($match[2] ?? '');

      if ($name !== '') {
        $names[] = $name;
      }
    }

    return $names;
  }

  private function extractNames(string $content, string $function): array {
    preg_match_all(
      '/' . preg_quote($function, '/') . '(?:_ref_array)?\s*\(\s*[\'"]([a-zA-Z0-9_\-\.\/]+)[\'"]/',
      $content,
      $matches
    );

    return $matches[1] ?? [];
  }
}
