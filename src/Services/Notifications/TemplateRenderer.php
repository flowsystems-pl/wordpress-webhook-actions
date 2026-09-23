<?php

namespace FlowSystems\WebhookActions\Services\Notifications;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Services\PayloadTransformer;

/**
 * The `{{ path | modifier }}` engine behind notification templates.
 *
 * Same placeholder shape as the dynamic-URL feature (`UrlTemplate`) and the
 * same dot-path resolver the field mapping uses, so `{{ args.0.form_id }}`
 * means the same thing everywhere in the plugin. Modifiers are a fixed, small
 * set — this is deliberately not a template language.
 *
 * Output is plain text. Escaping for HTML / mrkdwn / Markdown is the channel
 * driver's job, because only the driver knows its target.
 */
class TemplateRenderer {
  /** `{{ root.path | mod:arg | mod }}` */
  public const PATTERN = '/\{\{\s*([\w][\w.\-]*)\s*((?:\|[^|}]*)*)\}\}/';

  public const MODIFIERS = ['upper', 'lower', 'truncate', 'json', 'default', 'date', 'number', 'money', 'count', 'join'];

  /** Arrays rendered without `| json` are compacted and cut at this length. */
  private const INLINE_ARRAY_CAP = 500;

  private PayloadTransformer $paths;

  public function __construct(?PayloadTransformer $paths = null) {
    $this->paths = $paths ?? new PayloadTransformer();
  }

  /**
   * Render a template against the context roots.
   *
   * @param array<string, mixed> $roots webhook / event / delivery / payload / original / args / site
   */
  public function render(string $template, array $roots): string {
    return (string) preg_replace_callback(self::PATTERN, function (array $m) use ($roots): string {
      $value = $this->resolve($m[1], $roots);
      $found = $value !== null;

      foreach (self::parseModifiers($m[2] ?? '') as [$name, $arg]) {
        [$value, $found] = $this->apply($name, $arg, $value, $found);
      }

      return $this->stringify($value);
    }, $template);
  }

  /**
   * Resolve one dotted path against the roots. Returns null when missing.
   */
  public function resolve(string $path, array $roots): mixed {
    $segments = explode('.', $path, 2);
    $root     = $segments[0];
    if (!array_key_exists($root, $roots)) {
      return null;
    }
    if (!isset($segments[1])) {
      return $roots[$root];
    }
    if (!is_array($roots[$root])) {
      return null;
    }

    return $this->paths->getValueByPath($roots[$root], $segments[1]);
  }

  /**
   * Every placeholder in a template: [path, [[modifier, arg], …]].
   *
   * @return array<int, array{0:string, 1:array<int, array{0:string, 1:?string}>}>
   */
  public static function placeholders(string $template): array {
    if (!preg_match_all(self::PATTERN, $template, $all, PREG_SET_ORDER)) {
      return [];
    }
    $out = [];
    foreach ($all as $m) {
      $out[] = [$m[1], self::parseModifiers($m[2] ?? '')];
    }

    return $out;
  }

  /**
   * `| truncate:120 | default:"n/a"` → [['truncate','120'], ['default','n/a']]
   *
   * @return array<int, array{0:string, 1:?string}>
   */
  public static function parseModifiers(string $chain): array {
    $out = [];
    foreach (array_filter(array_map('trim', explode('|', $chain))) as $piece) {
      if (!preg_match('/^([a-z_]+)(?::(.*))?$/s', $piece, $m)) {
        $out[] = [strtolower($piece), null];
        continue;
      }
      $arg = isset($m[2]) ? trim($m[2]) : null;
      if ($arg !== null && strlen($arg) >= 2 && (($arg[0] === '"' && substr($arg, -1) === '"') || ($arg[0] === "'" && substr($arg, -1) === "'"))) {
        $arg = substr($arg, 1, -1);
      }
      $out[] = [strtolower($m[1]), $arg];
    }

    return $out;
  }

  /**
   * @return array{0:mixed, 1:bool} value and whether it counts as "present"
   */
  private function apply(string $name, ?string $arg, mixed $value, bool $found): array {
    switch ($name) {
      case 'default':
        if (!$found || $value === '' || $value === [] ) {
          return [$arg ?? '', true];
        }
        return [$value, $found];

      case 'upper':
        return [is_scalar($value) ? mb_strtoupper((string) $value) : $value, $found];

      case 'lower':
        return [is_scalar($value) ? mb_strtolower((string) $value) : $value, $found];

      case 'truncate':
        $limit = max(1, (int) ($arg ?? 120));
        $text  = $this->stringify($value);
        return [mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, $limit)) . '…' : $text, $found];

      case 'json':
        return [wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), $found];

      case 'date':
        $format = $arg ?: 'Y-m-d H:i';
        $ts     = is_numeric($value) ? (int) $value : (is_string($value) && $value !== '' ? strtotime($value) : false);
        if ($ts === false || $ts === null) {
          return [$value, $found];
        }
        return [wp_date($format, $ts), true];

      case 'number':
        $decimals = $arg !== null ? (int) $arg : 0;
        return [is_numeric($value) ? number_format_i18n((float) $value, $decimals) : $value, $found];

      case 'money':
        return [is_numeric($value) ? number_format_i18n((float) $value, 2) : $value, $found];

      case 'count':
        return [is_array($value) ? count($value) : (is_string($value) ? mb_strlen($value) : 0), true];

      case 'join':
        if (!is_array($value)) {
          return [$value, $found];
        }
        $glue = $arg ?? ', ';
        return [implode($glue, array_map(fn($v) => $this->stringify($v), $value)), $found];

      default:
        // Unknown modifier: leave the value alone (the linter reports it).
        return [$value, $found];
    }
  }

  private function stringify(mixed $value): string {
    if ($value === null) {
      return '';
    }
    if (is_bool($value)) {
      return $value ? 'true' : 'false';
    }
    if (is_scalar($value)) {
      return (string) $value;
    }
    $json = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
      return '';
    }

    return strlen($json) > self::INLINE_ARRAY_CAP ? substr($json, 0, self::INLINE_ARRAY_CAP) . '…' : $json;
  }
}
