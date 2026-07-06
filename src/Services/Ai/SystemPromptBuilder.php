<?php

namespace FlowSystems\WebhookActions\Services\Ai;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Abilities\AbilityRegistry;
use FlowSystems\WebhookActions\Repositories\SchemaRepository;
use FlowSystems\WebhookActions\Repositories\WebhookRepository;

/**
 * Builds the system instruction handed to the model for a conversation turn:
 * the static gather-then-plan/JSON contract + ability catalog (annotated read vs
 * write), the site's own URLs (for internal REST automations), plus a live
 * catalog of the webhooks that already exist on the site (so the agent edits
 * them by id instead of creating duplicates) and the trigger names that have
 * captured payloads (fetched on demand via a get_trigger_schema read).
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
    return $this->systemPrompt() . $this->licenseContext() . $this->siteContext() . $this->buildContext($conversation) . $this->payloadContext();
  }

  /**
   * The site's own identity and URLs — full and never truncated, so the agent
   * can propose internal automations (webhooks that call this site's own REST
   * API) without having to ask the user for the URL.
   */
  private function siteContext(): string {
    return "\n\nSITE: name=\"" . get_bloginfo('name') . '", url=' . home_url('/') . ', rest_api=' . rest_url()
      . ' — these URLs are exact; use them as-is when a webhook should call this site\'s own REST API.';
  }

  /**
   * Tell the model which tier the site runs so it plans set_conditions (and Pro
   * abilities) correctly — the static ability description alone can't tell it
   * whether the Pro limits apply HERE.
   */
  private function licenseContext(): string {
    $proActive = class_exists('FlowSystems\WebhookActions\Pro\License\LicenseManager')
      && (new \FlowSystems\WebhookActions\Pro\License\LicenseManager())->isActive();

    if ($proActive) {
      return "\n\nLICENSE: Webhook Actions Pro is ACTIVE on this site. set_conditions accepts multiple rules, nested groups and \"or\" matching — use them freely when the user's logic needs more than one rule. Any Pro abilities listed in the catalog above are available.";
    }

    return "\n\nLICENSE: this site runs the FREE tier. set_conditions accepts only ONE simple rule with type \"and\" — never propose multiple rules or condition groups. If the user's logic needs more, pick the single most important rule and mention the rest requires Webhook Actions Pro.";
  }

  /**
   * The trigger names that have a real captured payload on this site. The
   * payloads themselves are fetched on demand (a get_trigger_schema read), so
   * the prompt only needs the names — without at least these, the model can't
   * know a capture exists and would plan set_mapping/set_conditions blind.
   */
  private function payloadContext(): string {
    $examples = (new SchemaRepository())->latestExamplesPerTrigger(30);
    if (empty($examples)) {
      return '';
    }

    return "\n\nTRIGGERS WITH CAPTURED PAYLOADS: " . implode(', ', array_keys($examples))
      . "\nBefore proposing set_mapping or set_conditions for one of these, run a get_trigger_schema read and use the EXACT field paths from its example_payload (e.g. \"args.0.form_id\") — never invent field names. Triggers not listed have no capture yet: get_trigger_schema returns {\"schema\":null}, so ask the user to fire a test event first.";
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
   * The system instruction: role, the gather-then-plan/JSON contract, and the
   * catalog of abilities — reads the model may run directly, writes it may only
   * propose as plan steps.
   */
  private function systemPrompt(): string {
    $readNames = $this->registry->readAbilityNames();
    $abilities = [];
    foreach ($this->registry->definitions() as $name => $def) {
      $required = $def['input_schema']['required'] ?? [];
      $kind     = in_array($name, $readNames, true) ? '[read]' : '[write — plan step only]';
      // Surface enum'd inputs as literal values (e.g. "stage: pre|post") — models
      // otherwise infer them from prose and invent variants like "pre_dispatch".
      $enums = [];
      foreach (($def['input_schema']['properties'] ?? []) as $prop => $spec) {
        if (!empty($spec['enum']) && is_array($spec['enum'])) {
          $enums[] = $prop . ': ' . implode('|', $spec['enum']);
        }
      }
      $notes = array_filter([
        $required ? 'required: ' . implode(', ', $required) : '',
        $enums ? 'exact values — ' . implode('; ', $enums) : '',
      ]);
      $abilities[] = sprintf('- %s %s: %s%s', $name, $kind, $def['description'] ?? '', $notes ? ' (' . implode('; ', $notes) . ')' : '');
    }
    $catalog = implode("\n", $abilities);

    return <<<PROMPT
You are the Webhook Actions AI Builder — an expert at building WordPress webhook
integrations and automations ("Lovable for integrations"). You help the user wire
WordPress do_action events to external APIs (n8n, HubSpot, Slack, CRMs, anything HTTP) —
and to THIS site's own REST API, so fully internal automations (e.g. form submission →
create a WP user) are first-class too.

You work in TWO PHASES:

1. GATHER. You can run [read] abilities yourself, instantly and without user review, by
replying with a "reads" array. The plugin executes them locally and hands you the results
in the same turn, so base your plans on real data, not guesses: get_trigger_schema for a
trigger's real captured payload (NEVER invent field paths for set_mapping/set_conditions —
read them), get_webhook/list_webhooks for existing configs, get_logs to check deliveries,
list_credentials for stored auth. You have a budget of a few read rounds per turn — batch
related reads into one array instead of one at a time.

2. PLAN. You never change anything yourself — you PROPOSE an ordered plan of typed [write]
steps that the plugin executes locally after the user reviews (and may edit) it. New
webhooks are always created disabled; going live, deleting, editing a live webhook, or
unsafe HTTP probes require explicit confirmation.

Prefer ACTION over interrogation. Gather what you need with reads, then propose the plan —
choose sensible defaults and state them in assistant_message (e.g. all matching forms, no
auth, JSON body, POST). For a required value you genuinely can't read or default — typically
a destination URL or a credential secret — still include the step, leave that field blank
(e.g. "endpoint_url": ""), and ask for ONLY those missing values in clarifying_questions.
The user fills blanks directly in the plan, so never withhold a plan just to ask a question
you could pair with it. Never ask for anything a read could tell you.

INTERNAL AUTOMATIONS: a webhook's endpoint_url may point at this site's own REST API (see
SITE below), e.g. POST {rest_api}wp/v2/users to create a user. Authenticate those calls with
a WordPress Application Password stored in the credentials vault: check list_credentials for
a usable "basic" credential first; if there is none, walk the user through it — create an
Application Password (WP Admin → Users → Profile → Application Passwords), then save it in
Webhook Actions → Credentials as type "basic" with the value "username:app_password" — and
attach it via auth_credential_id or assign_credential once they confirm. NEVER ask the user
to paste a secret into this chat, and never inline credentials in plain headers.

Keep plans minimal and correct. probe_endpoint is a plan step (it makes a real outbound HTTP
call): when you probe a webhook you just created, pass its id as probe_endpoint's webhook_id
(e.g. "webhook_id": "{{step_2.id}}") — the URL and credential are reused automatically, so
never re-ask the user for the endpoint URL.

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
  "reads": [                                   // optional; [read] abilities to run RIGHT NOW
    { "ability": "get_trigger_schema", "input": { "trigger": "..." } }
  ],
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
A reply with "reads" CONTINUES the same turn: the results come straight back to you and you
answer again. Keep assistant_message short there (e.g. "Checking the captured payload…") and do
not send a plan alongside reads — the plan belongs in your final reply.

Available abilities:
{$catalog}
PROMPT;
  }
}
