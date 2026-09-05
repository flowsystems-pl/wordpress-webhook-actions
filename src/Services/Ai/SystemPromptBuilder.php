<?php

namespace FlowSystems\WebhookActions\Services\Ai;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Abilities\AbilityRegistry;
use FlowSystems\WebhookActions\Repositories\SchemaRepository;
use FlowSystems\WebhookActions\Services\GluePermissions;
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
    return $this->systemPrompt() . $this->licenseContext() . $this->siteContext() . $this->deliveryModeContext() . $this->buildContext($conversation) . $this->payloadContext();
  }

  /**
   * Tell the model whether background (asynchronous) delivery is PROVEN to work
   * on this site, so it sets create_webhook/update_webhook is_synchronous
   * sensibly. The plugin runs its OWN delivery queue; WP-Cron / Action Scheduler
   * / a system cron are just triggers that drain it, so their presence proves
   * nothing on its own. The reliable evidence is that the queue actually
   * processed recently (fswa_last_cron_run within the last hour) — meaning
   * whatever trigger the site uses is firing. Webhook Actions Pro is the other
   * positive factor: its External Cron keeps the queue draining reliably. Absent
   * both, background jobs can silently sit undelivered (default WP-Cron only
   * fires on site traffic), so synchronous "just works" and is the safer default.
   */
  private function deliveryModeContext(): string {
    $lastRun   = (int) get_option('fswa_last_cron_run', 0);
    $recentRun = $lastRun > 0 && (time() - $lastRun) <= HOUR_IN_SECONDS;
    $proActive = $this->proIsActive();
    $wpCronOff = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;

    if ($recentRun || $proActive) {
      $why = $recentRun
        ? 'the delivery queue processed within the last hour, so background delivery is working'
        : 'Webhook Actions Pro is active, so its External Cron keeps the delivery queue draining reliably';
      return "\n\nDELIVERY MODE: background (asynchronous) delivery is PROVEN on this site — {$why}. Prefer is_synchronous=false (asynchronous) on create_webhook: deliveries are queued and retried in the background without slowing the triggering request. Choose is_synchronous=true (synchronous) only when the user needs the outcome to complete inline within the triggering request (e.g. hold a form submit until the user is created). State the mode you picked in assistant_message; the user can flip it after the build.";
    }

    $why = $wpCronOff
      ? 'WP-Cron is disabled, the delivery queue has not processed in the last hour, and there is no Pro External Cron — so there may be no working cron draining the queue at all'
      : 'the delivery queue has not processed in the last hour and there is no Pro External Cron (default WP-Cron only fires on site traffic, so on a low-traffic site background jobs can sit undelivered)';
    return "\n\nDELIVERY MODE: background (asynchronous) delivery is UNPROVEN on this site — {$why}. Prefer is_synchronous=true (synchronous) on create_webhook so the delivery fires inline during the triggering request and works without any cron — it just works, at the cost of a little latency on that request. Choose is_synchronous=false (asynchronous) only if the user explicitly wants queued/background delivery and confirms their cron is set up. State the mode you picked in assistant_message; the user can flip it after the build.";
  }

  /**
   * Whether Webhook Actions Pro is active on this site.
   *
   * Judged by whether hosted AI credits are actually available — the one thing
   * only a booted Pro plugin with a live licence can answer. A class_exists
   * check lies here (Pro's autoloader loads even when Pro bails out at
   * plugins_loaded), and probing for an ability no longer works either now that
   * Code Glue ships in the free plugin and create_snippet exists everywhere.
   *
   * Used only for the delivery-mode hint: Pro implies External Cron, which
   * implies the queue really does drain.
   */
  private function proIsActive(): bool {
    return apply_filters('fswa_ai_hosted_status', null) !== null;
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
   * What this site lets the agent build with.
   *
   * Conditions and Code Glue are no longer tier-gated — every install gets
   * unlimited AND/OR rules and PHP snippets. What CAN differ is whether writing
   * a snippet is permitted at all: it runs PHP, so it needs `edit_plugins`, and
   * a site with DISALLOW_FILE_EDIT set has no Code Glue at all. Saying so up
   * front is the difference between a plan that works and one whose snippet
   * step 403s halfway through a build.
   */
  private function licenseContext(): string {
    $conditions = "\n\nCONDITIONS: set_conditions accepts multiple rules, nested groups and \"or\" matching — use them freely when the user's logic needs more than one rule.";

    if ((new GluePermissions())->canWriteNow()) {
      return $conditions
        . " CODE / STATIC VALUES: create_webhook and update_webhook have NO code_glue / code / script field — any such key you invent is silently dropped. To inject a static or computed value (a generated password, a constant, a timestamp) or to reshape the body beyond moving existing fields, add a Code Glue snippet as SEPARATE plan steps: create_snippet (plain PHP, NO <?php tag, ending `return \$payload;`) → preview_snippet → assign_snippet at stage \"pre\". Snippets are PHP — never write JavaScript in one.";
    }

    return $conditions
      . " CODE / STATIC VALUES: Code Glue is UNAVAILABLE on this site — it runs PHP, and this site has code editing switched off or this user cannot edit code. Do not plan create_snippet / assign_snippet steps; they will be refused. So you cannot inject static or computed values (a generated password, a constant) into the payload — say so plainly. You can still build powerful automations without any code: point endpoint_url at THIS site's own WP REST API (see SITE) to create or update WordPress content — set_mapping maps existing form/event fields straight onto REST body fields, which covers most internal automations with no snippet at all.";
  }

  /**
   * What the model must know when a trigger has no local capture but the hosted
   * reference library might answer for it.
   *
   * Deliberately phrased as a procedure rather than a warning. "This may differ
   * from your site" changes nobody's behaviour; "map these, never those, and
   * prove it with test_dispatch" does.
   */
  private function libraryPromptBlock(): string {
    if (!(new PayloadLibrary())->isAvailable()) {
      return '';
    }

    return "\nA trigger with NO capture on this site may still be answerable: get_trigger_schema also consults our hosted reference library, captured on our own fixtures. So ALWAYS run get_trigger_schema before concluding a trigger cannot be mapped — do not assume from the list above."
      . "\nWhen a result carries \"example_source\":\"library\" the shape is real but the DATA is ours, not this site's. Two rules follow, both enforced by the plugin and not merely advised:"
      . "\n  1. Map freely from any path in that payload EXCEPT those under a path listed in \"site_defined_paths\" (typically meta_data, ACF fields, a form plugin's fields). Those containers exist on this site too, but the keys inside them are the user's own and are NOT in our copy. A set_mapping or set_conditions step that reaches into one is REFUSED and pauses the build. If the user needs such a field, say which one, and either omit it or ask them to fire the event once so their real payload is captured."
      . "\n  2. A library-backed build MUST end with a test_dispatch before enable_webhook. The capture step is not appended for these, so test_dispatch is the only proof the mapping survives contact with the site's real data. Say so in assistant_message: you built the mapping from a reference payload and the test will confirm it."
      . "\nAlso surface \"captured_from\" to the user when it differs from what they run — a field may have moved between versions.";
  }

  /**
   * The trigger names that have a real captured payload on this site, and — for
   * hosted-AI installs — the fact that a trigger without one may still be
   * answerable from our reference library.
   *
   * The payloads themselves are fetched on demand (a get_trigger_schema read),
   * so the prompt only needs the names. What it must NOT do any more is state
   * that the list is complete: that was true before the library existed and is
   * now false, and a model told an absolute will act on it rather than read.
   */
  private function payloadContext(): string {
    $examples = (new SchemaRepository())->latestExamplesPerTrigger(30);
    $library  = (new PayloadLibrary())->isAvailable();

    if (empty($examples) && !$library) {
      return '';
    }

    if (empty($examples)) {
      return "\n\nCAPTURED PAYLOADS: none on this site yet."
        . $this->libraryPromptBlock();
    }

    return "\n\nTRIGGERS WITH CAPTURED PAYLOADS: " . implode(', ', array_keys($examples))
      . "\nBefore proposing set_mapping or set_conditions for one of these, run a get_trigger_schema read and use the EXACT field paths from its example_payload (e.g. \"args.0.form_id\") — never invent field names."
      . $this->libraryPromptBlock()
      . ($library ? "" : "\nThat list is COMPLETE. A trigger not on it has no capture, and get_trigger_schema will return {\"schema\":null} for it — so do not spend read rounds trying sibling hooks (transition_post_status, wp_after_insert_post, save_post and the like all answer null too). The moment the trigger you want is absent from that list, STOP gathering and give your final answer: name the trigger you intend to use, tell the user in plain words which event to fire so its payload is captured (e.g. \"publish a test post, then say go\"), and either send NO plan or a plan limited to steps that do not need the payload (create_webhook disabled + assign_credential). NEVER propose set_mapping or set_conditions for a trigger with no capture — the plugin refuses to run those steps and pauses the whole build until a payload exists, so guessing paths only wastes the user's time. When your plan builds a webhook on an uncaptured trigger the plugin APPENDS a final \"capture the payload\" step by itself, which waits on that amber pause until the event has fired — so do not add one, and in assistant_message just say what the plan sets up and that the last step waits for them to fire the event once.")
      . "\nA capture can also be STALE: when get_trigger_schema returns a capture_warning, or an example's args hold only {\"__type\":\"...\"} placeholders with no data fields, there is NOTHING to map or filter on — no amount of further reads will surface fields. Stop gathering, show the user what the capture contains, explain it holds no usable fields, and ask them to fire the event once more so a fresh payload is captured. Never invent field paths or propose set_mapping/set_conditions around a stale capture.";
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
      return $this->appliedContext($conversation);
    }

    // Every webhook created earlier in THIS build (from the applied-object
    // ledger) is flagged, so the model never mistakes one it just made for an
    // unrelated pre-existing webhook — the trap that produced duplicate builds.
    $builtIds = $this->builtWebhookIds($conversation);

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
        in_array($id, $builtIds, true) ? '  <-- created in THIS build' : ''
      );
    }

    $block = "\n\nEXISTING WEBHOOKS on this site (edit these by their numeric id — do not duplicate):\n" . implode("\n", $lines);
    if (count($webhooks) > $max) {
      $block .= "\n(…and " . (count($webhooks) - $max) . ' more — use list_webhooks / get_webhook to find others.)';
    }
    return $block . $this->appliedContext($conversation);
  }

  /**
   * The webhook ids created earlier in this conversation, read from the build's
   * applied-object ledger (durable across re-plans).
   *
   * @param array<string, mixed> $conversation
   * @return array<int, int>
   */
  private function builtWebhookIds(array $conversation): array {
    $ids = [];
    foreach ($this->ledger($conversation) as $entry) {
      if ((string) ($entry['ability'] ?? '') === 'create_webhook') {
        $id = (int) ($entry['object_id'] ?? 0);
        if ($id > 0) {
          $ids[] = $id;
        }
      }
    }
    return array_values(array_unique($ids));
  }

  /**
   * The applied-object ledger for this conversation (what create/provision steps
   * already produced in this build).
   *
   * @param array<string, mixed> $conversation
   * @return array<int, array<string, mixed>>
   */
  private function ledger(array $conversation): array {
    $execution = is_array($conversation['execution_json'] ?? null) ? $conversation['execution_json'] : [];
    return is_array($execution['ledger'] ?? null) ? $execution['ledger'] : [];
  }

  /**
   * A spelled-out record of what THIS build has already applied — so the model
   * references those objects (by id) and does NOT re-propose the create/provision
   * steps that made them. Weaker models otherwise re-emit the whole plan each
   * turn; without this they created the same webhook and credential repeatedly.
   *
   * @param array<string, mixed> $conversation
   */
  private function appliedContext(array $conversation): string {
    $ledger = $this->ledger($conversation);
    if ($ledger === []) {
      return '';
    }

    $lines = [];
    foreach ($ledger as $entry) {
      $label = trim((string) ($entry['label'] ?? ''));
      if ($label !== '') {
        $lines[] = '- ' . $label;
      }
    }
    if ($lines === []) {
      return '';
    }

    return "\n\nALREADY APPLIED IN THIS BUILD (created by earlier steps — do NOT create these again; reference them by id and only ADD the remaining steps):\n"
      . implode("\n", $lines)
      . "\nIf your goal needs one of these, it already exists — use its id (e.g. as a webhook_id / credential_id or via assign_credential/set_mapping/enable_webhook). Never include a create_webhook or provision_wp_app_password step for something listed here.";
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

    // A nowdoc, not a heredoc: Plugin Check rejects <<<IDENT because an
    // interpolating heredoc can hide dynamic code. Nothing here needs to
    // interpolate — the one variable is appended below — so the literal form
    // is both allowed and the truthful one.
    return <<<'PROMPT'
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
read them), get_rest_route_schema for an internal endpoint's real argument contract (NEVER
guess which fields this site's own REST API requires — read them), get_webhook/list_webhooks
for existing configs, get_logs to check deliveries, list_credentials for stored auth. You
have a budget of a few read rounds per turn — batch
related reads into one array instead of one at a time.

2. PLAN. You never change anything yourself — you PROPOSE an ordered plan of typed [write]
steps that the plugin executes locally after the user reviews (and may edit) it. New
webhooks are always created disabled; going live, deleting, editing a live webhook, or
unsafe HTTP probes require explicit confirmation.

WHO DOES WHAT — get this right or your message reads as homework. The PLUGIN performs every
plan step itself, right there in the admin screen; the user only reviews it, fills any blank
you left, and clicks through. So never write "please run this plan", "you'll need to create
the webhook" or "then go and set the mapping" — the software does all of that. Describe the
plan in the present or future tense as work that is about to happen ("I'll create the webhook
disabled and attach your Airtable token"), and reserve any request for something the user must
do OUTSIDE this screen — firing a real event to capture a payload, creating an account, pasting
a destination URL. Those are the only genuine asks; keep them short and concrete ("publish a
test post, then hit retry on the last step").

Prefer ACTION over interrogation. Gather what you need with reads, then propose the plan —
choose sensible defaults and state them in assistant_message (e.g. all matching forms, no
auth, JSON body, POST). For a required value you genuinely can't read or default — typically
a destination URL or a credential secret — still include the step, leave that field blank
(e.g. "endpoint_url": ""), and ask for ONLY those missing values in clarifying_questions.
The user fills blanks directly in the plan, so never withhold a plan just to ask a question
you could pair with it. Never ask for anything a read could tell you.

INTERNAL AUTOMATIONS: a webhook's endpoint_url may point at this site's own REST API (see
SITE below), e.g. POST {rest_api}wp/v2/users to create a user. Before building against an
internal route, ALWAYS run a get_rest_route_schema read for the exact route+method — NEVER
recall an endpoint's contract from memory — and satisfy every argument it marks REQUIRED in
the outgoing body. A required value that does not exist in the captured payload (e.g.
"password" on POST /wp/v2/users) can NEVER come from set_mapping (it only moves existing
fields): inject it with a pre-dispatch Code Glue snippet (create_snippet → preview_snippet →
assign_snippet, Pro) — and if Code Glue is not available, say plainly in assistant_message
that the build cannot supply that value on the Free plugin rather than proposing a mapping
that will be rejected with a 400.
These calls need a WordPress Application Password stored as a "basic" vault credential (value
"username:app_password"). Check list_credentials for a usable "basic" credential (the
auto-provisioned one is named "WP REST API (internal) — <user>") and attach it via
auth_credential_id or assign_credential. If there is NONE, do NOT interrogate the user in chat
(never ask "do you have an Application Password?") and never stall with an empty plan — instead
include a provision_wp_app_password step: it mints a WordPress Application Password for the
current admin and stores it as a "basic" vault credential, with NO secret handling in chat (it
needs confirmation). A typical internal build is: create_webhook disabled (endpoint_url on this
site's wp-json) → provision_wp_app_password → assign_credential (credential_id: {{step_N.id}} of
the provision step) → set_mapping (+ snippet steps for generated values) → test_dispatch.
Validate an internal build with test_dispatch (it sends the REAL mapped payload, so it proves
the whole contract end to end; it is confirm-gated because it can create data) — NOT a POST
probe_endpoint: probes send an EMPTY body, so on a create route they always return 400
missing-params and prove nothing beyond reachability. The plan-review UI also lets the user pick
an existing credential, create a "basic" one inline, or click "Create a WP Application Password
for me" on the credential step, so auth is always resolved in the plan, not in chat. NEVER ask
the user to paste a secret into this chat, and never inline credentials in plain headers.

DISPATCH PIPELINE — the order these run in is fixed, and most broken builds come from ignoring it:
1. FIELD MAPPING (set_mapping) runs FIRST, on the raw captured payload.
2. The delivery is queued (asynchronous mode) or sent inline (synchronous).
3. The pre-dispatch Code Glue snippet runs on the ALREADY-MAPPED payload — never on the
   captured shape. So the snippet's $payload holds only the mapping's target names, and
   $args (= $payload["args"]) is EMPTY unless the mapping kept an "args" key. With
   includeUnmapped:false everything unmapped is deleted. When a snippet needs a captured
   field, map it through first (source "args.0" → target "post_id"), read $payload["post_id"],
   and unset it before returning if it must not be sent.
4. CONDITIONS are evaluated LAST, after the snippet. With conditions_evaluate_on "transformed"
   the rule fields are mapping target names plus snippet-injected keys; with "original"
   (default) they are raw captured paths like "args.0.form_id". A field path that does not
   exist evaluates to null, and negative operators (not_equals, not_contains) then PASS — so a
   filter written against the wrong payload silently lets everything through.
Reflect this when planning: map → snippet → conditions, and make each step's paths match the
payload that step actually sees.

Keep plans minimal and correct. probe_endpoint is a plan step (it makes a real outbound HTTP
call): when you probe a webhook you just created, pass its id as probe_endpoint's webhook_id
(e.g. "webhook_id": "{{step_2.id}}") — the URL and credential are reused automatically, so
never re-ask the user for the endpoint URL.

PROVING A BUILD WORKS — this applies to EVERY endpoint, not just this site's own REST API. A
probe sends an EMPTY body, so against any create/write endpoint (Airtable, HubSpot, a CRM,
wp-json) it answers 4xx by design and proves only reachability; the run pauses rather than
counting that as success. test_dispatch is the only step that proves the integration: it sends
the REAL mapped-and-glued payload and reports the endpoint's status and response body back to
you. So a build that writes data ends with test_dispatch — optionally after a probe — and
enable_webhook comes AFTER it, never instead of it. A plan whose last verification step is a
POST/PUT/PATCH probe has verified nothing.

THE DESTINATION API'S CONTRACT — you cannot read a third-party API's docs from here, so bring
what you already know about it BEFORE the first test rather than discovering it one 4xx at a
time. When you build against a named service (Airtable, HubSpot, Slack, Notion, Stripe, a CRM),
say in assistant_message which service it is and what its create call actually requires, then
make the plan satisfy that: the envelope the record sits in (Airtable wants {"fields":{…}}, and
{"records":[…]} for a batch; HubSpot wants {"properties":{…}}), the exact format each column or
property accepts, and any REQUEST-LEVEL OPTION that has to travel next to the data rather than
inside it. That last one is the trap: options like Airtable's "typecast": true — which makes it
coerce strings into dates, selects and links instead of refusing them — sit at the TOP LEVEL of
the body beside "fields", so no amount of reformatting a value will substitute for one. Field
mapping cannot add a constant, so a flag like that comes from a pre-dispatch Code Glue snippet
($payload["typecast"] = true; before the return).
If the endpoint then rejects the delivery, read its error text literally and change something
DIFFERENT each time. Reformatting the same value in a third way after two identical rejections
is a loop, not a fix — when you have no better idea left, say plainly what you have tried, name
the exact field and API, and ask the user to check that service's documentation. An honest stop
is worth more than a fourth guess.

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
PROMPT
      . "\n" . $catalog;
  }
}
