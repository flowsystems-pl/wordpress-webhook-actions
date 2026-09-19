<?php

namespace FlowSystems\WebhookActions\Services\Notifications;

defined('ABSPATH') || exit;

use WP_Error;
use FlowSystems\WebhookActions\Services\Ai\AgentOrchestrator;
use FlowSystems\WebhookActions\Services\Ai\LlmTransport;
use FlowSystems\WebhookActions\Services\Ai\PayloadRedactor;
use FlowSystems\WebhookActions\Services\PayloadTransformer;

/**
 * Asks the site's AI provider for a notification template. One prompt, one
 * reply, strict JSON — the same provider Build with AI resolves, so a hosted
 * draft costs one credit and a BYOK draft costs whatever the key costs.
 */
class TemplateDrafter {
  private const MAX_FIELDS = 60;

  private TemplateLinter $linter;

  public function __construct(?TemplateLinter $linter = null) {
    $this->linter = $linter ?? new TemplateLinter();
  }

  /**
   * @param array{event:string, channel_types:array<int,string>, instructions:?string, current:?array, roots:array<string,mixed>, example:?array, mapped:?array, webhook:?array} $input
   * @return array{template:array<string,string|bool>, lint:array<string,mixed>, provider:string}|WP_Error
   */
  public function draft(array $input): array|WP_Error {
    $transport = (new LlmTransport())->resolve();
    if ($transport === null) {
      return new WP_Error('fswa_ai_unavailable', __('No AI provider is connected. Set one up under Build with AI to draft templates.', 'flowsystems-webhook-actions'), ['status' => 409]);
    }

    $system = $this->systemPrompt($input);
    $user   = $this->userPrompt($input);

    $reply = $transport->generateText($system, [['role' => 'user', 'content' => $user]], [
      'purpose'     => 'notification_template',
      'max_tokens'  => 1200,
      'temperature' => 0.4,
      'json'        => true,
    ]);
    if (is_wp_error($reply)) {
      return $reply;
    }

    $template = $this->parse((string) $reply);
    if ($template === null) {
      return new WP_Error('fswa_ai_parse', __('The AI reply was not a usable template. Try again or adjust the instructions.', 'flowsystems-webhook-actions'), ['status' => 502]);
    }

    $lint = $this->linter->lint($template, $input['example'] ?? null, $input['mapped'] ?? null, $input['channel_types'] ?? []);

    return [
      'template' => $template,
      'lint'     => $lint,
      'provider' => method_exists($transport, 'requestedId') ? (string) $transport->requestedId() : get_class($transport),
    ];
  }

  private function systemPrompt(array $input): string {
    $event        = (string) $input['event'];
    $channelTypes = array_values(array_unique(array_map('strval', $input['channel_types'] ?? [])));
    $defaults     = DefaultTemplates::for($event);

    $lines   = [];
    $lines[] = 'You write notification templates for a WordPress plugin that delivers webhooks. A template is rendered when a webhook delivery reaches the "' . $event . '" state (' . TemplateContext::eventLabel($event) . ').';
    $lines[] = 'Reply with ONE JSON object and nothing else: {"subject": string, "title": string, "body": string, "short": string}.';
    $lines[] = '- subject: email subject line, one line, no newlines.';
    $lines[] = '- title: card headline for chat tools, under 80 characters.';
    $lines[] = '- body: 2 to 6 short lines separated by \n. Plain text, no Markdown, no HTML. Do not repeat the title.';
    $lines[] = '- short: ONE line under 140 characters for SMS and push, the most important fact first.';
    $lines[] = '';
    $lines[] = 'Placeholders use double braces with a dotted path and optional modifiers: {{ webhook.name }}, {{ delivery.error_message | truncate:120 }}, {{ args.1.email | default:"unknown" }}.';
    $lines[] = 'Roots: webhook.{id,uuid,name,endpoint_url,edit_url,logs_url}; event.{uuid,timestamp,type,type_label,trigger,trigger_label}; delivery.{attempt,max_attempts,next_attempt_at,http_code,error_message,response_body,duration_ms,request_url,status,reason,log_url}; site.{name,url,admin_url}; payload.* (the mapped payload that was sent); original.* (the raw captured payload); args.* (shortcut for original.args.*).';
    $lines[] = 'Modifiers: upper, lower, truncate:N, json, default:"text", date:"Y-m-d H:i", number:N, money, count, join:", ".';
    $lines[] = 'Only use payload/original/args paths that appear in the FIELDS list you are given. Never invent a path. Prefer values that identify the affected record (an order number, an email, a form name) and the error.';
    $lines[] = 'Standard facts (webhook, trigger, attempt, HTTP code, error, next attempt, log link) are appended automatically as fields, so do not list them all again in the body — mention what matters for THIS event.';
    if (!empty($channelTypes)) {
      $lines[] = 'Target channels: ' . implode(', ', $channelTypes) . '.';
      foreach ($channelTypes as $type) {
        if (isset(TemplateLinter::LENGTH_LIMITS[$type])) {
          $lines[] = '- ' . $type . ' limits the body to about ' . TemplateLinter::LENGTH_LIMITS[$type] . ' characters.';
        }
      }
    }
    $lines[] = '';
    $lines[] = 'Style anchor (the shipped default for this event):';
    $lines[] = wp_json_encode(['subject' => $defaults['subject'], 'title' => $defaults['title'], 'body' => $defaults['body'], 'short' => $defaults['short']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return implode("\n", $lines);
  }

  private function userPrompt(array $input): string {
    $webhook = $input['webhook'] ?? null;
    $roots   = $input['roots'] ?? [];
    $lines   = [];

    $lines[] = 'WEBHOOK: ' . ($webhook['name'] ?? __('(site-wide rule, any webhook)', 'flowsystems-webhook-actions'));
    if (!empty($webhook['endpoint_url'])) {
      $host = wp_parse_url((string) $webhook['endpoint_url'], PHP_URL_HOST);
      $lines[] = 'DESTINATION HOST: ' . ($host ?: $webhook['endpoint_url']);
    }
    if (!empty($roots['event']['trigger'])) {
      $lines[] = 'TRIGGER: ' . $roots['event']['trigger'] . ' (' . ($roots['event']['trigger_label'] ?? '') . ')';
    }
    if (!empty($webhook['description'])) {
      $lines[] = 'DESCRIPTION: ' . mb_substr((string) $webhook['description'], 0, 400);
    }

    $fields = $this->fieldList($input['example'] ?? null, $input['mapped'] ?? null);
    if (!empty($fields)) {
      $lines[] = '';
      $lines[] = 'FIELDS (path = sample value):';
      foreach ($fields as $line) {
        $lines[] = $line;
      }
    } else {
      $lines[] = '';
      $lines[] = 'FIELDS: no captured payload yet — use only webhook.*, event.*, delivery.* and site.* placeholders.';
    }

    $current = $input['current'] ?? null;
    if (is_array($current) && !empty(array_filter($current))) {
      $lines[] = '';
      $lines[] = 'CURRENT TEMPLATE (improve it, keep what works):';
      $lines[] = wp_json_encode(array_intersect_key($current, array_flip(['subject', 'title', 'body', 'short'])), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    $instructions = trim((string) ($input['instructions'] ?? ''));
    if ($instructions !== '') {
      $lines[] = '';
      $lines[] = 'INSTRUCTIONS FROM THE USER: ' . mb_substr($instructions, 0, 1000);
    }

    return implode("\n", $lines);
  }

  /**
   * Flattened example paths with short, redacted sample values — the model
   * needs names, never whole records.
   *
   * @return array<int, string>
   */
  private function fieldList(?array $example, ?array $mapped): array {
    $out   = [];
    $paths = new PayloadTransformer();

    if (is_array($mapped) && $mapped !== $example) {
      foreach ($this->flatten(PayloadRedactor::redact($mapped), 'payload') as $path => $value) {
        $out[] = $path . ' = ' . $value;
      }
    }
    if (is_array($example)) {
      $args = is_array($example['args'] ?? null) ? $example['args'] : [];
      foreach ($this->flatten(PayloadRedactor::redact($args), 'args') as $path => $value) {
        $out[] = $path . ' = ' . $value;
      }
    }
    unset($paths);

    return array_slice($out, 0, self::MAX_FIELDS);
  }

  /**
   * @return array<string, string>
   */
  private function flatten(array $data, string $prefix, int $depth = 0): array {
    $out = [];
    foreach ($data as $key => $value) {
      $path = $prefix . '.' . $key;
      if (is_array($value)) {
        if ($depth >= 4 || empty($value)) {
          $out[$path] = '[' . (\FlowSystems\WebhookActions\Support\Arr::isList($value) ? 'list' : 'object') . ']';
          continue;
        }
        $out += $this->flatten($value, $path, $depth + 1);
        continue;
      }
      $sample = is_scalar($value) ? (string) $value : (is_null($value) ? 'null' : '[value]');
      if (is_bool($value)) {
        $sample = $value ? 'true' : 'false';
      }
      $out[$path] = mb_substr(str_replace(["\n", "\r"], ' ', $sample), 0, 60);
      if (count($out) >= self::MAX_FIELDS) {
        break;
      }
    }

    return $out;
  }

  /**
   * @return array<string, string|bool>|null
   */
  private function parse(string $reply): ?array {
    $decoded = json_decode($reply, true);
    if (!is_array($decoded)) {
      $repaired = AgentOrchestrator::repairJsonObject($reply);
      $decoded  = $repaired !== null ? json_decode($repaired, true) : null;
    }
    if (!is_array($decoded)) {
      return null;
    }
    // Some models wrap the object: {"template": {...}}.
    if (isset($decoded['template']) && is_array($decoded['template'])) {
      $decoded = $decoded['template'];
    }

    $template = [];
    foreach (['subject', 'title', 'body', 'short'] as $key) {
      if (isset($decoded[$key]) && is_string($decoded[$key]) && trim($decoded[$key]) !== '') {
        $template[$key] = trim($decoded[$key]);
      }
    }
    if (!isset($template['body']) && !isset($template['title'])) {
      return null;
    }
    $template['include_fields'] = true;

    return $template;
  }
}
