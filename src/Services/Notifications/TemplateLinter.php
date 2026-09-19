<?php

namespace FlowSystems\WebhookActions\Services\Notifications;

defined('ABSPATH') || exit;

/**
 * Static checks on a template: does every placeholder name a known root, does
 * every payload path exist in the captured example, is every modifier one we
 * have, and will the result fit the target channels. The rule editor, the
 * preview endpoint, the AI draft endpoint and the abilities all run it, so a
 * human and the model get the same verdict.
 */
class TemplateLinter {
  /** Delivery-level keys a template may address without an example. */
  private const KNOWN_LEAVES = [
    'webhook'  => ['id', 'uuid', 'name', 'endpoint_url', 'edit_url', 'logs_url'],
    'event'    => ['uuid', 'timestamp', 'type', 'type_label', 'trigger', 'trigger_label'],
    'delivery' => ['attempt', 'max_attempts', 'next_attempt_at', 'http_code', 'error_message', 'response_body', 'duration_ms', 'request_url', 'status', 'reason', 'is_test', 'log_id', 'log_url'],
    'site'     => ['name', 'url', 'admin_url'],
  ];

  /** Character budgets per channel type (body). */
  public const LENGTH_LIMITS = [
    'twilio_sms'      => 160,
    'twilio_whatsapp' => 1024,
    'pushover'        => 1024,
    'ntfy'            => 4096,
    'telegram'        => 4096,
    'discord'         => 2000,
    'slack'           => 3000,
  ];

  private TemplateRenderer $renderer;

  public function __construct(?TemplateRenderer $renderer = null) {
    $this->renderer = $renderer ?? new TemplateRenderer();
  }

  /**
   * @param array<string, string> $template   subject / title / body / short (any subset)
   * @param array<string, mixed>|null $example Captured example payload (envelope) to check payload paths against, null = skip
   * @param array<string, mixed>|null $mapped  Mapped example, when the webhook has a field mapping
   * @param array<int, string> $channelTypes   For length warnings
   * @return array{ok:bool, unknown_roots:array<int,string>, unknown_paths:array<int,string>, unknown_modifiers:array<int,string>, warnings:array<int,string>}
   */
  public function lint(array $template, ?array $example, ?array $mapped, array $channelTypes = []): array {
    $unknownRoots = [];
    $unknownPaths = [];
    $unknownMods  = [];
    $warnings     = [];

    foreach (['subject', 'title', 'body', 'short'] as $key) {
      $text = $template[$key] ?? null;
      if (!is_string($text) || $text === '') {
        continue;
      }
      foreach (TemplateRenderer::placeholders($text) as [$path, $mods]) {
        $this->checkPath($path, $example, $mapped, $unknownRoots, $unknownPaths);
        foreach ($mods as [$name]) {
          if (!in_array($name, TemplateRenderer::MODIFIERS, true)) {
            $unknownMods[] = $name;
          }
        }
      }
    }

    // Length: render against a sample and compare with each channel's budget.
    if (!empty($channelTypes) && isset($template['body']) && is_string($template['body'])) {
      $roots  = TemplateContext::sample(DeliveryEvents::FAILED_ATTEMPT, null, $example, $mapped);
      $body   = $this->renderer->render($template['body'], $roots);
      $length = mb_strlen($body);
      foreach (array_unique($channelTypes) as $type) {
        $limit = self::LENGTH_LIMITS[$type] ?? null;
        if ($limit !== null && $length > $limit) {
          $warnings[] = sprintf(
            /* translators: 1: channel type, 2: rendered length, 3: limit */
            __('%1$s: the body renders to about %2$d characters, over the %3$d limit; it will be truncated.', 'flowsystems-webhook-actions'),
            $type,
            $length,
            $limit
          );
        }
      }
    }

    $unknownRoots = array_values(array_unique($unknownRoots));
    $unknownPaths = array_values(array_unique($unknownPaths));
    $unknownMods  = array_values(array_unique($unknownMods));

    return [
      'ok'                => empty($unknownRoots) && empty($unknownPaths) && empty($unknownMods),
      'unknown_roots'     => $unknownRoots,
      'unknown_paths'     => $unknownPaths,
      'unknown_modifiers' => $unknownMods,
      'warnings'          => $warnings,
    ];
  }

  private function checkPath(string $path, ?array $example, ?array $mapped, array &$unknownRoots, array &$unknownPaths): void {
    $segments = explode('.', $path, 2);
    $root     = $segments[0];
    $rest     = $segments[1] ?? null;

    if (!in_array($root, TemplateContext::ROOTS, true)) {
      $unknownRoots[] = $root;
      return;
    }

    if (isset(self::KNOWN_LEAVES[$root])) {
      if ($rest !== null && !in_array(explode('.', $rest)[0], self::KNOWN_LEAVES[$root], true)) {
        $unknownPaths[] = $path;
      }
      return;
    }

    // payload / original / args: only checkable against a captured example.
    if ($rest === null) {
      return;
    }
    $source = match ($root) {
      'payload'  => $mapped ?? $example,
      'original' => $example,
      'args'     => is_array($example['args'] ?? null) ? $example['args'] : null,
      default    => null,
    };
    if ($source === null) {
      return;
    }
    if ($this->renderer->resolve($root . '.' . $rest, [$root => $source]) === null) {
      $unknownPaths[] = $path;
    }
  }
}
