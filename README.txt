=== Webhook Actions by Flow Systems ===
Contributors: mateuszflowsystems
Tags: webhooks, automation, integration, n8n, api
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.12.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Operate WordPress like modern infrastructure — turn any WordPress action into a real API event your CRMs, n8n flows, AI agents, and internal services can consume.

== Description ==

Most WordPress integrations are glue code. A `wp_remote_post()` here, a custom plugin there, an Action Scheduler job nobody else on the team understands. Webhook Actions by Flow Systems replaces that pile with a single, configurable event layer — so you can ship integrations at the pace the rest of your stack moves.

Any `do_action` becomes a first-class API event: queued, retried, logged, replayable, and reachable over a token-authenticated REST API. The WooCommerce-to-HubSpot round-trip that used to be a two-week project is now an afternoon of configuration. The "can n8n pick this up?" question gets a yes before the meeting ends.

- **Ship full CRM and SaaS integrations in an afternoon.** Two webhooks and a dynamic URL template (`https://api.hubapi.com/crm/objects/2026-03/deals/{{ _hs_deal_id }}`) turn a WooCommerce order lifecycle into a HubSpot deal lifecycle. Same pattern for Pipedrive, Notion, Airtable, internal services — any REST API that puts ids in the URL path. **(Pro)** for the `{{ }}` template syntax, or use the `fswa_webhook_url` filter on the free plan to rewrite the URL from PHP.
- **Speak n8n, Make, Zapier, and AI-agent fluent.** Send any WordPress event into n8n; pull a Claude Code or Cursor agent into your wp-admin via scoped API tokens and let it inspect logs, retry deliveries, and toggle integrations during deploys — without ever touching WordPress credentials.
- **Operate WordPress like a real backend.** Every event has a UUID, a full request/response log, a replay button, and an HTTP-addressable REST endpoint. Conditional dispatch, custom headers, query parameters, all five HTTP methods, dynamic URL templates — match exactly what each external API expects.
- **Replace expensive automation subscriptions with code you own.** Move the Zapier/Make tasks that bill per-run back into WordPress. Same triggers, same destinations, no per-zap pricing, no third-party data hop.
- **Let the rest of the team ship without asking for help.** Junior devs and ops folks configure webhooks in the admin panel. Filters and a **(Pro)** Code Glue snippet system are there when something custom is genuinely needed. The integrations stay readable, observable, and yours.

Backed by a dedicated persistent queue with intelligent retry and exponential backoff — the queue lives in your database, under your control. Action Scheduler is auto-detected as the optional trigger (the same job runner WooCommerce uses for production stores) and gracefully falls back to WP-Cron when it isn't available. Same plugin scales from a Contact Form 7 lead form to a high-traffic Black Friday store without changing a line.

= The integration architect's toolkit =

You already think in events, payloads, idempotency, and observability. Webhook Actions by Flow Systems is the kit that matches: a persistent queue, a full delivery log, replay, scoped REST API tokens, an extensible filter and action surface (`fswa_webhook_payload`, `fswa_webhook_url`, `fswa_glue_post_dispatch` and more), and an event surface AI agents can safely talk to. Plug it in, and the WordPress side of your stack starts to look like the rest of it.

👉 Step-by-step example: [Send Contact Form 7 submissions to a webhook (n8n demo)](https://wpwebhooks.org/examples/cf7-to-webhook/)
👉 Step-by-step example: [Send Gravity Forms Submissions to n8n](https://wpwebhooks.org/examples/gravity-forms-webhooks/)
👉 Step-by-step example: [Send IvyForms submissions to a webhook (n8n demo)](https://wpwebhooks.org/examples/ivyforms-to-webhook/)
👉 Step-by-step example: [Send WooCommerce orders to n8n on completion, only when the total is over $999 — wired up with a Claude Code agent](https://wpwebhooks.org/examples/woocommerce-order-webhook-claude-code/)

= ⚡ Webhook Actions Pro =

Unlock unlimited conditions, per-webhook retry and backoff settings, type casting in payload mapping, and more.

[See pricing and upgrade →](https://wpwebhooks.org/pricing/)

= Typical Use Cases =

- CF7 to Webhook: Send Contact Form 7 Data to n8n or external APIs
- Gravity Forms webhooks for sending submission to CRM
- Send IvyForms submissions to n8n or external APIs
- Build reliable form-to-CRM integrations with retry protection
- Process high-volume WooCommerce webhooks using Action Scheduler
- Send WooCommerce orders to n8n with retry protection
- Sync WordPress users to external CRMs safely
- Trigger backend microservices from WP hooks
- Send event-driven data to internal APIs
- Replace fragile custom `wp_remote_post()` integrations
- Build idempotent WordPress automation pipelines
- Sync WooCommerce orders to HubSpot CRM, Pipedrive, or any REST API — create a deal on payment, then PATCH the same deal when the order completes, by storing the remote ID locally and replaying it into the URL on later events
- Dynamic per-event endpoints — point a webhook at `https://api.example.com/resources/{{ resource_id }}` and the URL is resolved at dispatch time against the live payload
- Query delivery logs, trigger retries, or manage webhooks programmatically from CI/CD pipelines or external dashboards using API tokens
- Allow AI coding assistants (e.g. Claude Code) to inspect webhook logs and retry failed events automatically
- Use AI agents to monitor webhook delivery health and operate the queue through the REST API

= Event Identity & Idempotency =

Every dispatched webhook includes:

- Unique UUID (v4) per event
- ISO 8601 UTC timestamp
- Embedded `event.id`, `event.timestamp`, `event.version` in the payload
- HTTP headers: `X-Event-Id`, `X-Event-Timestamp`, `X-Webhook-Id`

`X-Webhook-Id` carries the webhook's own stable UUID — distinct from the per-event `X-Event-Id`. When multiple webhooks point to the same endpoint, the receiving system can use `X-Webhook-Id` to identify which webhook configuration triggered the delivery without inspecting the payload.

This enables downstream deduplication, idempotent workflow design, and reliable debugging across systems.

= Reliable Queue & Smart Retry =

Webhooks are never sent directly from request execution. Instead:

- Events are stored in a persistent database queue
- Processed asynchronously via background jobs
- Dispatched in batches to avoid performance impact

Smart retry routing:

- 5xx and 429 responses → automatic exponential backoff retry
- 4xx and 3xx responses → immediately marked as `permanently_failed`
- Configurable maximum retry attempts
- Full attempt history stored per event

No silent failures.

= Replay Webhook Events =

Webhook debugging is difficult when events cannot be reproduced.

Webhook Actions by Flow Systems allows you to replay any webhook event directly from the delivery logs — including successful deliveries and condition-skipped events.

This makes it easy to:

- Re-run automation workflows
- Debug external integrations
- Recover from temporary endpoint failures
- Test webhook consumers without recreating WordPress events
- Re-evaluate previously skipped events after changing webhook conditions

Each replay uses the original payload and event metadata, ensuring consistent behavior across retries and debugging sessions.

= Conditional Dispatch =

Not every WordPress event should trigger a webhook. Conditional dispatch lets you define field-level rules on any webhook — the event is only delivered if the conditions pass. Events that fail the check are logged with a `skipped` status and can be replayed later after adjusting the conditions.

Conditions are evaluated using dot-notation field paths. Each condition specifies a field, an optional type cast, an operator, and a comparison value. The field selector shows the live captured payload so you can click through nested structures and pick the exact path without typing it manually.

**Evaluate against original or transformed payload**

Each trigger schema exposes a toggle to choose which payload conditions are evaluated against:

- **Original** — the pre-mapping payload exactly as the WordPress hook fired (default for most use cases). Use this to filter on raw hook arguments like the new WooCommerce status in `args.2`.
- **Transformed** — the post-mapping, post-enrichment payload that will actually be sent. Use this to filter on fields injected by `fswa_payload`, `fswa_webhook_payload`, or **(Pro)** Code Glue snippets — for example, dispatch only when a remote id was successfully resolved.

**Operators include:** equals, not equals, contains, starts with, ends with, is empty, has value, greater than, less than, `array_contains`, `object_contains`

**Type casting before comparison:** auto-detect, number, string, boolean, or `stringify` (JSON-encodes arrays and objects into a string for pattern matching)

**Example — WooCommerce: fire only when a specific product is in the order**

A `woocommerce_order_status_changed` hook passes the full order object. The payload includes `args.1.line_items` — an array of purchased products, each with fields like `product_id`, `quantity`, and `subtotal`. To send a webhook only when product ID 26 appears in the order:

- Field: `args.1.line_items`
- Operator: `has value` → key: `product_id`, value: `26`

The webhook stays silent for every other order and fires only when that product is purchased. No custom PHP, no extra filters — just a condition rule configured in the admin panel.

The same pattern works for any hook-based event: filter by post type, form field value, user role, order total, or any other field present in the payload.

Free plan includes one condition with AND matching. [Upgrade to Pro](https://wpwebhooks.org/pricing/) for unlimited conditions, multiple condition groups with independent AND/OR logic per group, and ANY (OR) matching.

= Synchronous Execution =

By default, all webhooks are delivered asynchronously via the built-in queue — events are stored, processed in the background, and retried automatically on failure. This is the recommended approach for production sites.

For specific webhooks that require inline delivery (e.g. an internal API that must respond within the same request), you can enable **Synchronous Execution** per webhook:

- The webhook fires during the WordPress request that triggered it — no queue delay
- The first attempt runs blocking in the current request
- If that attempt fails with a retryable error (5xx, transport error), it automatically falls back to the queue with standard exponential backoff starting at attempt 2
- Non-retryable failures (4xx) are marked permanently failed immediately
- A warning dialog must be acknowledged before enabling, and can be dismissed permanently per-browser

Use with caution on user-facing requests — a slow or unreachable endpoint will delay page loads, form submissions, and other frontend interactions.

= Delivery Observability =

Operational visibility built into the admin panel:

Status states: `pending`, `processing`, `success`, `failed` (retrying), `permanently_failed`

- Attempt timeline per event
- HTTP status codes and response bodies
- Inspect full request payloads
- Manual retry (single or bulk)
- Replay webhook events for debugging and testing integrations

Filter by: event UUID, target URL, date range, status

Queue health metrics:

- Average attempts per event
- Oldest pending job age
- Queue stuck detection
- WP-Cron-only warning

Designed as an operations console — not just a webhook sender.

= Payload Mapping =

Adapt outgoing JSON payloads to match any external API:

- Rename fields using dot notation
- Restructure nested objects
- Exclude sensitive or unnecessary data
- Cast field values to number, string, or boolean before sending (e.g. WooCommerce price `"100.50"` → `100.5`)
- Store example payloads for configuration
- Modify via `fswa_payload` filter

Payloads always include stable event metadata for consistency.

= Configurable HTTP Requests =

Every webhook can be configured to match exactly what the target API expects:

**HTTP Method**

Choose the method used for each delivery: GET, POST, PUT, PATCH, or DELETE. Default is POST.

**Custom Request Headers**

Add any number of key/value header pairs sent with every delivery. Header values support dot-notation paths — reference any field from the outgoing payload directly (e.g. `event.id`, `site.url`). Resolved at dispatch time against the live payload.

**URL Query Parameters**

Append query parameters to the endpoint URL at dispatch time. Values also support dot-notation payload resolution.

For GET and DELETE requests — where a request body is not appropriate — query parameters become the primary payload transport. If no params are configured, the full payload is sent as a `?payload=` fallback. POST, PUT, and PATCH send a JSON body as normal; any configured params are appended to the URL in addition.

**Request details in delivery logs**

Every delivery log stores the exact headers sent and the fully resolved URL (including all query parameters), so you can inspect precisely what was dispatched.

= Per-Event Dynamic URLs (Free, via filter) =

Many REST APIs require an object id directly in the path — HubSpot, Pipedrive, Stripe, Notion, custom internal services. The free plugin exposes the `fswa_webhook_url` filter so you can rewrite the endpoint URL per event from PHP, with full access to the outgoing payload, the webhook configuration, the trigger name, and the original pre-mapping payload.

`add_filter( 'fswa_webhook_url', function ( $url, $payload, $webhook, $trigger, $original ) {`
`    if ( (int) $webhook['id'] === 30 ) {`
`        $deal_id = $payload['_hs_deal_id'] ?? '';`
`        return "https://api.hubapi.com/crm/objects/2026-03/deals/{$deal_id}";`
`    }`
`    return $url;`
`}, 10, 5 );`

The same filter powers the **(Pro)** template syntax described below, so any URL you can build with `{{ }}` placeholders you can also build by hand on the free plan.

= Dynamic URL Templates (Pro) =

**(Pro)** Endpoint URLs can contain `{{ field.path }}` placeholders that are resolved per event against the live payload at dispatch time — no PHP required. Configure entirely from the webhook edit screen.

**Syntax**

`https://api.hubapi.com/crm/objects/2026-03/deals/{{ _hs_deal_id }}`
`https://api.example.com/v1/resources/{{ resource_id }}/notes`
`https://api.example.com/users/{{ user.id }}/orders/{{ order.id }}`

Same dot-notation as custom headers and URL parameters. Values are `rawurlencode()`'d before substitution to keep the URL valid.

**Resolution order**

The template is resolved against the outgoing (post-mapping) payload first. If a placeholder is not found there, the original pre-mapping payload is consulted as a fallback — so paths from the captured event keep working even after payload mapping renames or removes top-level fields.

**Example — WooCommerce → HubSpot deal update**

1. On `woocommerce_payment_complete`, send a POST to `https://api.hubapi.com/crm/objects/2026-03/deals` to create the deal. Store the returned deal id in the WooCommerce order's post meta.
2. On `woocommerce_order_status_changed`, configure a second webhook with endpoint URL `https://api.hubapi.com/crm/objects/2026-03/deals/{{ _hs_deal_id }}` and method `PATCH`. Inject `_hs_deal_id` into the payload (read from order meta), and the URL resolves to the right HubSpot deal on every event.

This pattern works for any REST API that uses resource ids in the URL path. Injecting the id from external storage (post meta, options, transients) can be done with the `fswa_webhook_payload` filter on the free plan, or **(Pro)** with no code at all using [Webhook Actions Pro Code Glue](https://wpwebhooks.org/pricing/).

= REST API Access with Token Authentication =

The plugin exposes a full operational REST API (`/wp-json/fswa/v1/`) that powers the admin interface and can also be used directly by external tools, automation systems, AI agents, and CI/CD pipelines.

Every endpoint supports dual authentication:

- WordPress admin session (cookie-based, used by the admin panel)
- API token — for programmatic access without a browser session

**API Tokens**

Create tokens directly from the API Tokens screen in the admin panel. Each token is assigned one of three scopes:

- `read` — GET access to webhooks, logs, queue, health, triggers, and schemas
- `operational` — Read + toggle webhooks on/off, retry and replay log entries, execute queue jobs
- `full` — Operational + create, update, and delete webhooks, schemas, and queue jobs

Token authentication is accepted via:

- `X-FSWA-Token: <token>` header (recommended)
- `Authorization: Bearer <token>` header
- `?api_token=<token>` query parameter

Tokens can be set to expire and rotated at any time. Rotation issues a new secret immediately while preserving the token's name, scope, and settings. Token management always requires a WordPress admin login — tokens cannot be used to create or manage other tokens.

Full REST API documentation: [REST API Reference](https://wpwebhooks.org/webhook-wordpress-plugin-api/)

= AI Agents and Programmatic Automation =

The REST API makes Webhook Actions by Flow Systems accessible to AI-powered tools and coding agents.

Automation systems, CI pipelines, and AI coding assistants (such as Claude Code or Cursor) can safely interact with webhook infrastructure using API tokens without requiring WordPress admin sessions.

Typical AI-driven workflows include:

- AI agents monitoring webhook delivery health
- Automatically retrying failed webhook events
- Inspecting delivery logs to debug integrations
- Enabling or disabling webhooks dynamically during deployments
- Managing automation pipelines across environments

Because the API exposes operational endpoints for logs, queue jobs, webhooks, and triggers, external agents can treat WordPress as a programmable event infrastructure.

Example scenarios:

• A Claude Code agent analyzes webhook delivery logs and automatically retries failed integrations.
• A CI/CD pipeline disables webhook triggers during deployments and re-enables them afterward.
• Automation systems query webhook health metrics and alert when the queue becomes stuck.
• External dashboards display real-time webhook delivery metrics using API tokens.

This allows WordPress automation pipelines to be controlled entirely through HTTP APIs, enabling advanced integration with AI-driven development workflows.

= Webhook Actions Pro (full feature list) =

Webhook Actions Pro extends the plugin with advanced features for production workflows. Every feature in this section requires an active Pro license.

- **(Pro)** Unlimited conditions and condition groups with AND/OR logic
- **(Pro)** Per-webhook retry settings — override maximum retry attempts at the webhook level
- **(Pro)** Per-webhook backoff strategy — override retry delay behavior per webhook
- **(Pro) Code Glue** — attach short PHP snippets per webhook+trigger to enrich the outgoing payload (pre-dispatch) or react to the response (post-dispatch); supports reading WordPress data, looking up remote IDs from post/user meta, and writing back response data; pairs naturally with dynamic URL templates for round-trip integrations like WooCommerce ↔ HubSpot deal sync — no separate plugin or theme code required
- **(Pro)** License managed directly from the Pro tab in the admin panel

[Learn more and upgrade →](https://wpwebhooks.org/pricing/)

= Contact Form 7 Webhooks =

Webhook Actions by Flow Systems includes built-in integration with Contact Form 7.

When Contact Form 7 is active, form submissions are automatically converted into structured webhook payloads — no custom code required.

Included in each payload:

- Form ID and title
- Submission data (all fields)
- Normalized field structure (no raw CF7 format)
- Request metadata
- Uploaded files (where applicable)

Benefits:

- Send CF7 submissions to n8n, APIs, CRMs, or automation tools
- No need for custom hooks or additional plugins
- Clean JSON payloads ready for external processing
- Works with existing webhook retry, queue, and replay system

This allows you to build reliable form-to-automation pipelines directly from WordPress.

= Action Scheduler Support =

Webhook Actions by Flow Systems now supports Action Scheduler — the same background job system used by WooCommerce.

When available, webhook queue processing automatically switches from WP-Cron to Action Scheduler for improved reliability and scalability.

Benefits:

- More reliable background execution than WP-Cron
- Better handling of high-volume webhook traffic
- Persistent job tracking and recovery
- No configuration required — automatic detection and migration

This makes the plugin suitable for production WooCommerce stores and high-throughput automation pipelines.

= Developer Friendly =

- Works with any WordPress or WooCommerce action
- Full REST API (`/wp-json/fswa/v1/`) usable from any HTTP client — not just the admin panel
- API token authentication with scoped access (`read`, `operational`, `full`)
- Configurable HTTP method, custom headers, and URL query parameters per webhook
- Fully extensible via filters and actions
- Clean namespace and unique prefixes
- Built according to WordPress.org standards
- Supports system cron, WP-Cron, and Action Scheduler (auto-detected)

= Why Choose Webhook Actions by Flow Systems? =

Most WordPress webhook setups fire once, don't retry intelligently, don't provide delivery visibility, and don't expose event identity.

Webhook Actions by Flow Systems provides:

- Persistent queue
- Smart retry logic
- Webhook replay for debugging integrations
- Permanent failure state handling
- Event UUIDs and timestamps
- Full delivery logging and metrics
- Configurable HTTP method, custom headers, and URL query parameters per webhook
- Dynamic URL templates — `{{ field.path }}` placeholders resolved per event against the live payload
- Conditional webhook dispatch with a per-trigger evaluate-on switch (original or transformed payload)
- Per-webhook synchronous execution — optional inline delivery with automatic queue fallback on failure
- Test webhook delivery — send a test event instantly or via queue without triggering real WordPress events
- REST API with token authentication for programmatic access
- Action Scheduler support for reliable background processing (when available)
- Built-in CF7 to webhook support (no extra plugins needed)

Upgrade to [Webhook Actions Pro](https://wpwebhooks.org/pricing/) for unlimited conditions, per-webhook retry and backoff settings, and more.

Built for developers who need production-grade automation reliability.

= Available Filters =

- `fswa_should_dispatch` – Decide if a trigger should dispatch
- `fswa_payload` – Customize webhook payload before dispatch
- `fswa_webhook_payload` – Enrich the outgoing payload just before dispatch (after mapping); args: `$payload`, `$webhookId`, `$trigger`, `$originalPayload`. Used by Pro Code Glue and any custom integration that injects fields (e.g. a remote id looked up from post meta)
- `fswa_webhook_url` – Customize the endpoint URL per delivery; powers dynamic `{{ field.path }}` template expansion. Args: `$url`, `$payload` (post-mapping/post-glue), `$webhook`, `$trigger`, `$originalPayload`
- `fswa_capture_payload` – Modify the payload just before it is stored as the captured example (does not affect the dispatched payload); args: `$payload`, `$webhookId`, `$trigger`
- `fswa_normalize_object` – Normalize a third-party object into an array for payload serialization
- `fswa_headers` – Add or modify HTTP headers sent with the request
- `fswa_require_https` – Toggle HTTPS requirement
- `fswa_max_attempts` – Configure maximum retry attempts
- `fswa_backoff_delay` – Customize retry backoff delay in seconds
- `fswa_queue_batch_size` – Configure batch processing size
- `fswa_http_timeout` – Configure HTTP request timeout
- `fswa_http_connect_timeout` – Configure HTTP connect timeout
- `fswa_http_args` – Customize HTTP request arguments
- `fswa_available_triggers` – Customize available trigger list
- `fswa_webhook_data` – Filter webhook configuration data returned by the REST API

= Available Actions =

- `fswa_success` – Fired after successful webhook delivery
- `fswa_error` – Fired after webhook delivery failure
- `fswa_skipped` – Fired when a webhook dispatch is skipped due to a failed condition
- `fswa_webhook_saved` – Fired after a webhook is created or updated
- `fswa_webhook_response` – Fired after an HTTP response is received (success or error); args: `$webhookId`, `$trigger`, `$responseCode`, `$responseBody`, `$payload`, `$webhook`
- `fswa_glue_post_dispatch` – Fired after each delivery with full request and response context; args: `$webhookId`, `$trigger`, `$responseCode`, `$responseBody`, `$payload`, `$webhook`, `$originalPayload`. Used by Pro Code Glue post-dispatch snippets to write returned data back into WordPress (e.g. store a remote resource id in post meta)

= Admin UX Improvements =

- Option to move the plugin menu under "Tools" for a cleaner admin sidebar
- Instant UI refresh when changing menu location

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/flowsystems-webhook-actions` directory, or install the plugin through the WordPress plugins screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to Webhook Actions in the admin menu.
4. Add your webhook endpoint URL and select the desired WordPress action triggers.

== Frequently Asked Questions ==

= Can I send Contact Form 7 submissions to a webhook? =

Yes. When Contact Form 7 is active, form submissions are automatically available as webhook triggers. You can send them to any external API, automation tool (like n8n), or CRM.

= Do I need an extra plugin for CF7 to webhook? =

No. Contact Form 7 integration is built in. No additional plugins or custom code are required.

= What does the Contact Form 7 webhook payload look like? =

The plugin normalizes CF7 submissions into a clean JSON structure including form metadata, submitted fields, making it easy to consume in external systems.

= Can this handle high webhook volume? =

Yes. The plugin uses a persistent queue with batch processing and supports Action Scheduler for scalable background execution, making it suitable for high-traffic WordPress and WooCommerce sites.

= Does this plugin support Action Scheduler? =

Yes. If Action Scheduler is available (for example via WooCommerce), the plugin will automatically use it for queue processing instead of WP-Cron. No configuration or reactivation is required.

= What is the difference between WP-Cron and Action Scheduler? =

WP-Cron depends on site traffic and can be unreliable on low-traffic sites. Action Scheduler is a dedicated background job system used by WooCommerce that provides more consistent and reliable execution of queued jobs.

Webhook Actions by Flow Systems automatically uses Action Scheduler when available.

= What is a WordPress action? =

An action is a WordPress hook triggered at a specific moment, such as when a user is created, a post is saved, or an order is completed.

= Can I use this plugin with n8n or other automation tools? =

This plugin works seamlessly with n8n webhook triggers and can be used with any automation platform or external API that accepts HTTP webhooks.

= Does this plugin support WooCommerce hooks? =

Yes. Any WooCommerce action can be used as a trigger, as long as the hook is available.

= How does the retry mechanism work? =

Failed webhooks are automatically retried using exponential backoff. The delay increases with each attempt (e.g., 1 minute, 2 minutes, 4 minutes, 8 minutes), up to a maximum delay of 1 hour between retries. By default, 5 attempts are made before marking a job as failed. The retry behavior can be adjusted using available filters.

= Can I replay webhook events? =

Yes. Every webhook delivery is logged with its payload and attempt history.
You can replay successful events and condition-skipped events directly from the logs — useful for debugging integrations, re-running automation workflows, or re-evaluating a skipped event after you've changed the webhook's conditions.

= What is Payload Mapping? =

Payload Mapping allows you to transform the webhook payload before it is sent. You can rename fields, reorganize the structure, or exclude sensitive data. The plugin can store example payloads to help configure mappings.

= Can I include user data in webhook payloads? =

Yes. For user-related triggers (such as `user_register`, `profile_update`, `wp_login`, `wp_logout`), you can enable "Include User Data" to automatically add user information (ID, email, display name, roles, etc.) to the payload.

= Can I access the REST API without a WordPress login? =

Yes. Create an API token from the API Tokens screen in the admin panel and use it in the `X-FSWA-Token` header (or `Authorization: Bearer`) with your requests. Tokens support three scopes — `read`, `operational`, and `full` — so you can grant only the access level each integration needs.

= Is this plugin free? =

Yes. The core plugin is completely free and licensed under GPL. Webhook Actions Pro is an optional paid upgrade that adds unlimited conditions, per-webhook retry and backoff settings, and more. [Learn more →](https://wpwebhooks.org/pricing/)

= Can I use conditions to filter which webhooks fire? =

Yes. Each webhook can have conditions evaluated against the incoming payload before dispatch. Free plan supports one condition with AND match. Pro plan supports unlimited conditions, condition groups, and AND/OR logic per group. Each trigger can choose whether conditions evaluate against the original (pre-mapping) payload or the transformed (post-mapping, post-Code-Glue) payload.

= Can the endpoint URL change per event? =

Yes. The endpoint URL supports dot-notation placeholders like `https://api.example.com/v1/resources/{{ resource_id }}/notes`. Placeholders are resolved at dispatch time against the live payload and `rawurlencode()`'d before substitution. Resolution tries the outgoing (post-mapping) payload first and falls back to the original captured payload, so paths from the captured event keep working even after mapping rewrites top-level fields.

= Can I sync WooCommerce orders to HubSpot, Pipedrive, or another CRM that requires a deal id in the URL? =

Yes. Use two webhooks: the first creates the remote resource on payment completion and stores the returned id in WordPress (e.g. post meta on the order). The second updates the resource on subsequent events using a URL like `https://api.hubapi.com/crm/objects/2026-03/deals/{{ _hs_deal_id }}` and injects the stored id back into the payload. The injection can be done with the `fswa_webhook_payload` filter, or **(Pro)** with no code at all using [Webhook Actions Pro Code Glue](https://wpwebhooks.org/pricing/).

== Screenshots ==

1. Webhooks list view
2. Webhook configuration screen
3. Selecting WordPress action triggers
4. Payload mapping configuration
5. Webhook delivery logs with replay and retry controls
6. Queue status overview
7. Settings configuration screen
8. REST API Tokens configuration screen
9. Conditional webhook dispatch — conditions editor
10. Test webhook drawer — send a test delivery and inspect request details inline

== Changelog ==

= 1.12.2 — 2026-05-14 =
- Fixed "Queue appears stuck" health banner staying visible when delivery logs were left in `pending` state with no live queue row (e.g. legacy rows from older queue-status semantics, or worker crashes between updating queue and log state); the queue processor now reconciles such orphaned pending logs on every run and marks them `permanently_failed`
- Improved trigger conditions UI — renamed the "Evaluate against" toggle to "Evaluate conditions against" and added an inline info tooltip explaining the Original (pre-mapping) vs Transformed (post-mapping) choice
- Removed misleading Pro upgrade badge from the conditions evaluate-against toggle — choosing between original and transformed payload for conditions has always been a free-plan feature

= 1.12.1 — 2026-05-12 =
- Docs: corrected README to reflect that type casting (in both payload mapping and conditions) is a free-plan feature; removed misleading Pro markers
- Docs: added WooCommerce → n8n step-by-step example showing conditional dispatch wired up via a Claude Code agent
- Docs: reformatted `fswa_webhook_url` PHP code sample so it renders as a single code block on the wordpress.org plugin page
- No code changes

= 1.12.0 — 2026-05-12 =
- Added `fswa_webhook_payload` filter — Pro-extensible enrichment of the outgoing payload before dispatch; receives the mapped payload, webhook id, trigger name, and pre-mapping original payload
- Added `fswa_glue_post_dispatch` action — fires after every delivery with response code/body, mapped payload, webhook, and original pre-mapping payload; intended for Pro post-dispatch snippets
- Added `fswa_webhook_url` filter — Pro-extensible URL template expansion; passes the URL, post-glue payload, webhook, trigger, and original pre-mapping payload as fallback for dot-notation token resolution
- Added per-trigger `conditions_evaluate_on` setting (original / transformed) — choose whether conditions evaluate against the pre-mapping payload or the post-mapping/post-glue payload; segmented toggle in the trigger schema panel
- Added dual-resolution for custom headers and URL parameters — when a dot-notation path resolves to null in the post-glue payload, falls back to the pre-mapping original payload; matches existing condition resolution semantics
- Added collapsible Original Payload section in the Mapping editor — inspect the pre-glue/pre-mapping payload while authoring field mappings
- Improved `object_contains` operator — also matches when the value is present at the current array level, not only nested; works for array-typed WooCommerce fields like `meta_data` and `line_items`
- Improved delivery log writes — `request_payload`, `original_payload`, and `mapping_applied` are now refreshed after the `fswa_webhook_payload` filter mutates the payload
- Fixed condition evaluation order — re-evaluates conditions after Code Glue pre-dispatch when `conditions_evaluate_on` is `transformed`
- Fixed pre-glue filter application in synchronous mode — applied exactly once during the inline attempt instead of once before enqueue and again at send

= 1.11.0 — 2026-05-06 =
- Added `array_contains` condition operator — checks whether an array field contains a specified value; works with flat arrays and arrays of objects
- Added `object_contains` condition operator — checks whether an object field contains a specified key (optionally filtered to a specific property within nested objects using a `key=` parameter)
- Added `stringify` type cast — JSON-encodes array and object field values into a string before comparison, enabling string-based operators on complex nested structures
- Improved FieldSelector — split navigate and select actions; added a dedicated "+" button to select non-leaf fields (arrays and objects) directly without drilling further into children
- Improved ConditionsEditor layout — responsive three-row design on small screens (field + delete on top row, cast + operator on second row, value on third); `object_contains` exposes an inline property name input when a key filter is needed
- Delivery log detail messages now include the property key when `object_contains` is matched against a specific property

= 1.10.0 — 2026-05-03 =
- Added per-webhook synchronous execution mode — when enabled, the webhook fires inline during the WordPress request that triggers it, bypassing the queue; a warning dialog explains the performance impact before enabling; dismissal can be stored permanently per-browser
- First synchronous attempt runs blocking in the current request; retryable failures (5xx, transport errors) automatically fall back to the async queue starting at attempt 2 with standard exponential backoff; non-retryable failures (4xx) are marked permanently failed immediately
- Added sync execution toggle to the Webhooks list view — enable or disable per webhook without opening the edit screen
- Added Request Headers and Query Parameters sections to delivery log details — inspect the exact headers and URL parameters sent with each delivery
- Added collapsible Request Payload and Original Payload sections in delivery log details — collapse state is persisted in browser storage so the panel opens in the same state on next visit
- Fixed GET and DELETE webhooks not including custom headers or URL parameters in deliveries
- Added replay support for skipped (condition-failed) log entries — re-evaluate a previously skipped event after changing the webhook's conditions

= 1.9.0 — 2026-05-03 =
- Added configurable HTTP method per webhook — choose GET, POST, PUT, PATCH, or DELETE (default: POST)
- Added custom request headers per webhook — define key/value pairs sent with every delivery; values support dot-notation paths resolved against the outgoing payload
- Added URL query parameters per webhook — appended to the endpoint URL; for GET and DELETE requests, query params are the primary payload transport (no body); a full `?payload=` fallback is used when no params are configured
- Added `fswa_capture_payload` filter — modify or enrich the payload stored as the captured example without affecting what is dispatched; designed for Pro extensions and custom PHP snippets
- Added `fswa_webhook_response` action — fires after every HTTP response is received per webhook; intended for Pro extensions to run custom logic against the response (parse body, trigger follow-up actions, store data)
- Added `request_headers` and `request_url` columns to delivery logs — the exact headers sent and the fully resolved URL (with query params applied) are now stored and visible in the delivery log
- Improved test webhook drawer — defaults to "Captured + Mapping" payload source; result panel now shows HTTP method, fully resolved endpoint URL, sent headers, and request body

= 1.8.0 — 2026-04-28 =
- Added type casting in Conditions — cast field values to number, string, or boolean before comparison; enables greater than / less than on numeric strings (e.g. WooCommerce price "100.50")
- Added type casting in Payload Mapping — cast field values before sending to external APIs
- Added `X-Webhook-Id` request header — sent with every delivery; carries the webhook's stable UUID so downstream systems can identify which webhook configuration triggered the request when multiple webhooks share the same endpoint
- Fixed test webhook result label — now reflects actual HTTP status: 2xx = Success, 3xx = Redirect, 4xx = Client Error, 5xx = Server Error (previously all completed deliveries showed green "Success")

= 1.7.0 — 2026-04-27 =
- Added "Test Webhook" delivery with run-now and queue modes — test webhook delivery without triggering real WordPress events
- Added conditional webhook dispatch — filter events by payload field values before dispatch; free plan includes one condition with AND match
- Added field selector with live preview in the Conditions editor to build conditions from real example payloads
- Fixed attempt history timestamps displayed in browser local time
- Renamed plugin to "Webhook Actions by Flow Systems"
- Added Webhook Actions Pro integration tab for license management

= 1.6.2 — 2026-04-05 =
- Fixed graceful handling of 409 responses when a queue job was already completed in a background process
- Fixed mapping editor not supporting dot-containing keys (e.g. Gravity Forms sub-field IDs like `6.1`)

= 1.6.1 — 2026-03-28 =
- Fixed schema API endpoints for triggers containing forward slashes (e.g. `ivyforms/form/before_submission`) returning 404 on Apache — admin now uses double-encoding to pass through Apache's encoded-slash restriction

= 1.6.0 — 2026-03-28 =
- Added built-in IvyForms integration — automatically normalizes IvyForms field objects and enriches submission payloads for `ivyforms/form/before_submission` and `ivyforms/form/after_submission` hooks
- Added IntegrationLoader to centralize third-party integration registration
- Fixed forward slashes not being recognized in hook names during dynamic trigger discovery
- Fixed percent-encoded slashes in schemas REST route trigger param not being decoded correctly
- Fixed trigger name not being URL-encoded when building schemas API requests from the admin UI

= 1.5.0 — 2026-03-23 =
- Added built-in CF7 to webhook integration — automatically sends CF7 submissions as structured webhook payloads (form id, title, fields, meta, uploaded files)
- Added `fswa_normalize_object` filter for custom third-party object normalization
- Added `get_properties()` fallback in payload normalization to handle objects with private or protected properties
- Improved hook registration to capture all hook arguments by default (PHP_INT_MAX accepted_args)

= 1.4.0 — 2026-03-22 =
- Added Action Scheduler support for queue processing (auto-detected, no configuration required)
- Automatic migration from WP-Cron to Action Scheduler when available
- Added option to move admin menu under Tools for cleaner dashboard navigation
- Added dynamic trigger discovery via static PHP source scan
- Reduced triggers API responses size
- Fixed input focus styles in admin forms

= 1.3.2 — 2026-03-15 =
- Fixed `auth_header` field being exposed to API tokens without `full` scope — read and operational tokens now receive a permission notice instead

= 1.3.1 — 2026-03-15 =
- Fixed log details dialog showing error message from the first attempt instead of the most recent one
- Added [REST API Reference](https://wpwebhooks.org/webhook-wordpress-plugin-api/) link to the plugin description

= 1.3.0 — 2026-03-15 =
- Added API token authentication for the REST API — create tokens with `read`, `operational`, or `full` scope; tokens are SHA-256 hashed at rest and accepted via `X-FSWA-Token` header, `Authorization: Bearer`, or `?api_token=` query param
- Added token expiry support with optional `expires_at`; expired tokens are rejected at auth time and visually flagged in the admin panel
- Added token rotation — issues a new secret while preserving all other token fields; optionally updates expiry in the same request; revived expired tokens auto-extend to +30 days by default
- Added `PATCH /tokens/{id}` endpoint for updating `expires_at` independently of rotation
- Added `fswa_api_tokens` database table (migration 1.3.0)
- Applied scope-based dual auth (`manage_options` session OR valid token) to all existing REST controllers: `read` for GET endpoints, `operational` for toggle/retry/replay, `full` for create/update/delete
- Fixed all admin UI date displays (logs, queue, schema panel) to show times in the user's local timezone instead of raw UTC
- Fixed date range filters (logs, queue) to correctly convert local picker values to UTC before querying
- Improved log details panel — error message, response body, HTTP code, and duration now reflect the most recent attempt history entry rather than the top-level log fields

= 1.2.1 — 2026-03-07 =
- Fixed retry returning 500 when a log has multiple queue jobs (replay + original) — `findByLogId` now returns the most recent job via `ORDER BY id DESC`
- Fixed `forceRetry` rejecting jobs with status `failed` — restored `failed` to the allowed status list alongside `pending` and `permanently_failed`

= 1.2.0 — 2026-03-07 =
- Added persistent delivery stats table (`fswa_stats`) for long-term aggregation
- Added replay button for successful log entries
- Added "Execute Now" button in replay dialog with auto-open log details
- Added full attempt history with response body, accordion UI, and next attempt countdown
- Replaced browser `confirm()` dialogs with modal confirmations
- Fixed queue stats — removed stale `failed` status, added `permanently_failed`
- Fixed retry eligibility check to use log status instead of queue job status
- Fixed "Execute Now" button visibility to only show for pending jobs

= 1.1.1 — 2026-03-01 =
- Fixed `permanently_failed` entries being excluded from total and error delivery statistics in `getStats()`, `getAllTimeStats()`, and `LogArchiver::aggregateStatsBeforeDeletion()`

= 1.1.0 — 2026-02-28 =
- Added event identity: each trigger dispatch generates a shared UUID and timestamp sent as `X-Event-Id` / `X-Event-Timestamp` headers and embedded in the payload under `event.{id,timestamp,version}`
- Added smart retry routing: 5xx and 429 responses trigger an automatic retry with exponential backoff; 4xx and 3xx responses are immediately marked as permanently failed
- Added `permanently_failed` status for non-retryable delivery failures
- Added attempt history: each delivery attempt is recorded as a JSON array on the log entry, visible in the admin timeline view
- Added per-log retry and bulk retry REST endpoints (`POST /logs/{id}/retry`, `POST /logs/bulk-retry`)
- Added `event_uuid` and `target_url` filter parameters to logs and queue REST endpoints
- Added date range filtering (`date_from`, `date_to`) to logs and queue list views with a shadcn-style calendar date/time picker
- Added health observability metrics: average attempts per event, oldest pending age, queue stuck detection, WP-Cron-only warning
- Added `queue.log_id` column linking queue jobs to their log entries
- Updated admin UI: permanently failed badge, attempt timeline, per-row retry button, bulk retry, observability warning banners, new filter inputs
- Updated footer with a review prompt linking to WordPress.org

= 1.0.1 — 2026-02-18 =
- Fixed preview freezing when mapping fields from objects with numeric string keys (e.g. WooCommerce line_items)
- Fixed orphaned pending log entries caused by logPending() silently failing — queue jobs now carry mapping metadata and recover a proper log entry if the original ID was lost
- Enhanced normalizeValue to handle Closure, DateTimeInterface, and Traversable types
- Removed unnecessary WooCommerce hook patterns from trigger exclusions
- Improved log details display with word break for long trigger names and dates

= 1.0.0 — 2026-02-16 =
- Initial release
- Webhook dispatching from WordPress actions
- Background processing with retry mechanism
- Configurable webhook payloads
- Logging of webhook deliveries

== Upgrade Notice ==

= 1.12.2 =
Fixes a stale "Queue appears stuck" health banner caused by orphaned pending log rows; the queue worker now self-reconciles such rows. Clarifies the conditions evaluate-against toggle label and removes a misleading Pro badge. No database changes — no manual steps required.

= 1.11.0 =
Adds two new condition operators (`array_contains`, `object_contains`) and a `stringify` type cast for matching complex array and object fields. Improves the conditions editor layout for mobile screens. No database changes — no manual steps required.

= 1.10.0 =
Adds per-webhook synchronous execution, delivery log details improvements (request headers, query parameters, collapsible payloads), a fix for GET/DELETE webhooks not sending custom headers or URL params, and replay support for condition-skipped log entries. Database migration runs automatically — adds `is_synchronous` to the webhooks table. No manual steps required.

= 1.9.0 =
Adds configurable HTTP method, custom headers, and URL query parameters per webhook. Database migration runs automatically — adds `http_method`, `custom_headers`, `url_params` to the webhooks table and `request_headers`, `request_url` to the logs table. No manual steps required.

= 1.8.0 =
Adds type casting in Conditions and Payload Mapping — cast string values to number, string, or boolean before comparison or before sending to an API. Fixes test webhook result labels to correctly reflect HTTP status codes. No database changes.

= 1.7.0 =
Adds test webhook delivery (run-now or via queue without triggering real events) and conditional dispatch — filter webhooks by payload field values before they fire. No database changes.

= 1.6.2 =
Bug fixes: graceful 409 handling for already-completed queue jobs, and dot-containing keys (e.g. Gravity Forms `6.1`) in the mapping editor. No database changes.

= 1.6.1 =
Fixes schema API endpoints for slash-based trigger names (e.g. IvyForms hooks) on Apache hosting. No database changes.

= 1.6.0 =
Adds built-in IvyForms webhook integration and fixes hook discovery for triggers containing forward slashes. No database changes.

= 1.5.0 =
Adds built-in Contact Form 7 webhook integration.
You can now send CF7 form submissions to external APIs, automation tools, or CRMs with retry, queue, and replay support — no additional plugins or custom code required.

= 1.4.0 =
Adds Action Scheduler support for significantly more reliable and scalable webhook processing.
If Action Scheduler is available (e.g. via WooCommerce), the plugin automatically switches from WP-Cron — no reactivation or setup required.

= 1.3.0 =
Adds a new database table for API tokens. The table is created automatically on update — no manual steps needed.

= 1.1.1 =
Fixes permanently_failed entries being undercounted in delivery statistics. No database changes.

= 1.1.0 =
This release adds new database columns (`event_uuid`, `event_timestamp`, `attempt_history`, `next_attempt_at` on logs; `log_id` on queue). The migration runs automatically on plugin activation or update. No manual steps required.

= 1.0.0 — 2026-02-16 =
Initial stable release.