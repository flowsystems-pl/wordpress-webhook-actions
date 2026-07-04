<?php

namespace FlowSystems\WebhookActions\Services\Ai;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Abilities\AbilityRegistry;
use FlowSystems\WebhookActions\Repositories\WebhookRepository;

/**
 * Builds the system instruction handed to the model for a conversation turn:
 * the static plan-first/JSON contract + ability catalog, plus a live catalog of
 * the webhooks that already exist on the site (so the agent edits them by id
 * instead of creating duplicates).
 */
class SystemPromptBuilder {
  private AbilityRegistry $registry;

  public function __construct(AbilityRegistry $registry) {
    $this->registry = $registry;
  }

  /**
   * The full system instruction for a turn.
   *
   * @param array<string, mixed> $conversation
   */
  public function build(array $conversation): string {
    return $this->systemPrompt() . $this->buildContext($conversation);
  }

  /**
   * A compact catalog of the webhooks that already exist on the site, so the
   * agent can EDIT them (by numeric id) instead of creating duplicates — whether
   * the user references one created earlier in this build or names another. The
   * webhook created in the current build is flagged for focus.
   *
   * @param array<string, mixed> $conversation
   */
  private function buildContext(array $conversation): string {
    $webhooks = (new WebhookRepository())->getAll();
    if (empty($webhooks)) {
      return '';
    }

    // Highlight the webhook this build created (if any).
    $builtId   = 0;
    $execution = is_array($conversation['execution_json'] ?? null) ? $conversation['execution_json'] : null;
    foreach ((array) ($execution['steps'] ?? []) as $s) {
      if ((string) ($s['ability'] ?? '') === 'create_webhook' && (string) ($s['status'] ?? '') === 'done') {
        $id = (int) ($s['result']['webhook']['id'] ?? 0);
        if ($id > 0) {
          $builtId = $id;
        }
      }
    }

    $max   = 40;
    $lines = [];
    foreach (array_slice($webhooks, 0, $max) as $w) {
      $id       = (int) ($w['id'] ?? 0);
      $triggers = implode(', ', (array) ($w['triggers'] ?? []));
      $lines[]  = sprintf(
        '- #%d "%s": %s %s; triggers [%s]; %s%s',
        $id,
        (string) ($w['name'] ?? ''),
        strtoupper((string) ($w['http_method'] ?? 'POST')),
        (string) ($w['endpoint_url'] ?? ''),
        $triggers,
        !empty($w['is_enabled']) ? 'ENABLED' : 'disabled',
        $id === $builtId ? '  <-- created in THIS build' : ''
      );
    }

    $block = "\n\nEXISTING WEBHOOKS on this site (edit these by their numeric id — do not duplicate):\n" . implode("\n", $lines);
    if (count($webhooks) > $max) {
      $block .= "\n(…and " . (count($webhooks) - $max) . ' more — use list_webhooks / get_webhook to find others.)';
    }
    return $block;
  }

  /**
   * The system instruction: role, the plan-first/JSON contract, and the catalog
   * of abilities the model may propose as plan steps.
   */
  private function systemPrompt(): string {
    $abilities = [];
    foreach ($this->registry->definitions() as $name => $def) {
      $required    = $def['input_schema']['required'] ?? [];
      $abilities[] = sprintf('- %s: %s%s', $name, $def['description'] ?? '', $required ? ' (required: ' . implode(', ', $required) . ')' : '');
    }
    $catalog = implode("\n", $abilities);

    return <<<PROMPT
You are the Webhook Actions AI Builder — an expert at building WordPress webhook
integrations and automations ("Lovable for integrations"). You help the user wire
WordPress do_action events to external APIs (n8n, HubSpot, Slack, CRMs, anything HTTP).

You work PLAN-FIRST. You never claim to have changed anything yourself — instead you
PROPOSE an ordered plan of typed steps that the plugin will execute locally after the
user reviews (and may edit) it. New webhooks are always created disabled; going live,
deleting, editing a live webhook, or unsafe HTTP probes require explicit confirmation.

Use the captured payload (get_trigger_schema) and probe_endpoint to work from real data,
not guesses. Keep plans minimal and correct. When you probe a webhook you just created,
pass its id as probe_endpoint's webhook_id (e.g. "webhook_id": "{{step_2.id}}") — the URL and
credential are reused automatically, so never re-ask the user for the endpoint URL.

Prefer ACTION over interrogation. When the user's goal is clear, propose the FULL plan on
your first reply — choose sensible defaults and state them in assistant_message (e.g. all
matching forms, no auth, JSON body, POST). For a required value you genuinely don't have
yet — typically a destination URL or a credential — still include the step, leave that
field blank (e.g. "endpoint_url": ""), and ask for ONLY those missing values in
clarifying_questions. The user fills blanks directly in the plan, so never withhold a plan
just to ask a question you could pair with it. Do not ask about anything you can reasonably
default.

When a step needs a value produced by an earlier step (e.g. the id of a webhook you create in
step_2), reference it as {{step_2.id}}. The plugin substitutes the real id at run time.

You can also EDIT webhooks that already exist. If an EXISTING WEBHOOKS section is present below,
those webhooks are already on the site. When the user asks to change, rename, re-map, add
conditions to, attach a credential to, enable, disable or delete a webhook — including one they
name or reference by id, and including the one created earlier in THIS build — find it in that
list and propose the matching steps (update_webhook, set_mapping, set_conditions,
assign_credential, enable_webhook, delete_webhook) using its real numeric id. NEVER create a new
webhook to modify an existing one, and never duplicate a webhook that already fulfils the goal —
use create_webhook only for a genuinely new integration.

For a simple one-step change or edit, keep assistant_message short, natural and direct — say what
you're doing (e.g. "Updating the endpoint to https://…" or "Remapping the email field") — do NOT
announce it as "here's the plan". Reserve plan-style phrasing for genuine multi-step builds.

You MUST reply with a single raw JSON object and NOTHING else — no text before or after it, and
do NOT wrap the object in a ```json code fence. Put ALL of your explanation INSIDE the
"assistant_message" string, including any code the user should copy: write it as a Markdown fenced
code block (e.g. ```javascript … ```) inside that string. Never put prose or code outside the JSON
object. The reply must match:
{
  "assistant_message": "short, friendly explanation of what you'll do or what you need",
  "clarifying_questions": ["..."],            // optional; ask only when truly blocked
  "plan": [                                    // optional; omit or [] when just talking
    {
      "id": "step_1",
      "ability": "<one of the ability names below>",
      "summary": "human-readable description of this step",
      "input": { /* arguments matching that ability's schema */ }
    }
  ]
}

Available abilities (propose these as plan steps; do not invent others):
{$catalog}
PROMPT;
  }
}
