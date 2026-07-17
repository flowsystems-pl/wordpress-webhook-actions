=== Webhook Actions - build automations and integrations with AI help ===
Contributors: mateuszflowsystems
Tags: ai, webhooks, automation, integration, n8n
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 2.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Describe what you want in chat — the built-in AI agent plans, builds, and tests your WordPress webhooks, integrations, and automations.

== Description ==

**Describe the integration you want. The AI builds it.** Webhook Actions ships with **Build with AI** — an in-admin agent that turns a plain-language request like *"When a Contact Form 7 form is submitted, send it as JSON to my n8n webhook"* into a working, tested automation. The agent proposes a plan you can review and edit, then creates the webhook, captures a real example payload from your site, maps the fields, sets dispatch conditions, probes your endpoint, and sends a test delivery. Nothing goes live without your confirmation — new webhooks are always created disabled, and you can undo the last change with one click.

📖 [Full documentation at wpwebhooks.org/docs/](https://wpwebhooks.org/docs/)

= Bring your own AI — free options included =

- **WordPress 7.0 AI Client** — if your site already has an AI provider connected (Settings → Connectors), the builder uses it directly; the plugin stores no keys
- **Your own API key** — connect Anthropic, OpenAI, or Google in the builder; keys are encrypted in the Credentials Vault and never returned over the API
- **Free to run** — a free Google AI Studio key gives you Gemini at no cost: [step-by-step tutorial](https://wpwebhooks.org/docs/get-google-ai-studio-api-key/)
- **Automatic fallback** — if a provider is rate-limited mid-build, the agent switches to another connected provider and keeps going

= What the AI works from =

The agent doesn't guess — it works from your site's real data. It maps fields against actually captured payloads, edits existing webhooks by name or id instead of duplicating them, validates endpoints with a guarded probe (SSRF-protected, secrets always redacted), and verifies the result with a real test delivery. Every operation is also published as a WordPress Ability, so external AI tools (Claude Code, Cursor) can drive the same toolset over MCP with scoped API tokens.

= The engine underneath (free) =

- Turn any WordPress do_action into a first-class automation trigger your CRMs, n8n flows, AI agents, and internal services can consume
- Persistent delivery queue with smart retry and exponential backoff — powered by WP-Cron, auto-upgrades to Action Scheduler or System Cron when available, **(Pro)** External Cron for guaranteed reliability
- Per-event UUID and ISO 8601 timestamp — enable downstream deduplication
- Delivery logs with full attempt history, request/response inspection, replay, and bulk retry
- Synchronous execution mode — fire inline without queue delay
- Payload mapping — rename, restructure, exclude, and type-cast fields with dot-notation paths
- Conditional dispatch — filter events by payload field values before dispatch
- HTTP method, custom headers, and URL query parameters per webhook
- Dynamic endpoint URLs — `{{ field.path }}` placeholders resolved at dispatch time (free via `fswa_webhook_url` filter)
- Webhook Chains — wire 2xx completions to downstream webhooks with full observability
- Credentials Vault — store reusable auth secrets (Bearer, Basic, API key, custom) encrypted at rest; reference them from webhooks instead of pasting raw Authorization headers. Secrets are write-only over the API — never returned, only a masked hint
- Activity History — persistent audit log of every admin and API-token action
- Built-in CF7 and IvyForms integrations — structured payloads, no extra plugins
- Action Scheduler auto-detection — more reliable delivery on high-traffic sites
- Fully translatable — the entire admin interface and all server-side strings are internationalized; ships with Polish, Simplified Chinese, and Dutch, and is compatible with WPML and Polylang String Translation
- Full REST API with scoped API token authentication (`read` / `operational` / `full` / `agent`) — the `agent` scope grants full write access for AI assistants while never exposing stored secrets
- Developer extensibility — 16 filters and 7 action hooks ([reference](https://wpwebhooks.org/docs/))

= Pro features =

- AI writes Code Glue for you — the agent drafts PHP snippets, test-runs them against your real captured payloads, and assigns them to webhooks (with your confirmation) for pre-dispatch payload enrichment or post-dispatch side effects
- AI sets advanced conditions — with Pro the agent can propose multi-rule AND/OR condition groups instead of a single rule
- Code Glue — attach PHP snippets to any webhook+trigger (pre-dispatch payload enrichment, post-dispatch side effects)
- External Cron — replace unreliable visitor-triggered WP-Cron with a managed external pinger, provisioned automatically on license activation. Two modes: plugin queue endpoint (down to 20 s interval, configurable batch size) or WP-Cron endpoint (60 s, covers all WordPress background work). No server crontab or external dashboard — controlled entirely from wp-admin, with a live heartbeat chart and inline error alerts
- Unlimited conditions per trigger with AND/OR groups
- Per-webhook retry limit and backoff strategy overrides
- Dynamic URL templates — `{{ }}` syntax with no custom PHP required

[See pricing and upgrade →](https://wpwebhooks.org/pricing/)

= Examples =

- [Send Contact Form 7 submissions to a webhook (n8n demo)](https://wpwebhooks.org/examples/cf7-to-webhook/)
- [Send Gravity Forms Submissions to n8n](https://wpwebhooks.org/examples/gravity-forms-webhooks/)
- [Send IvyForms submissions to a webhook (n8n demo)](https://wpwebhooks.org/examples/ivyforms-to-webhook/)
- [WooCommerce orders to n8n on completion — wired up with a Claude Code agent](https://wpwebhooks.org/examples/woocommerce-order-webhook-claude-code/)
- [WooCommerce to HubSpot integration — sync orders, contacts, and deals with no custom code](https://wpwebhooks.org/examples/hubspot-woocommerce-integration/)

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/flowsystems-webhook-actions` directory, or install the plugin through the WordPress plugins screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to Webhook Actions in the admin menu — it opens on **Build with AI**.
4. Connect an AI provider (or use your WordPress 7.0 AI connectors) and describe the integration you want — or skip the AI and configure webhooks manually under the Webhooks tab.

== Frequently Asked Questions ==

= Does the AI Builder need an API key? Is it free to use? =

The AI Builder needs a model to talk to, and you have free options. If your WordPress 7.0 site already has an AI provider connected (Settings → Connectors), the builder uses it with no extra setup. Otherwise connect your own Anthropic, OpenAI, or Google key — a free Google AI Studio key gives you Gemini at no cost. [Here's how to get one in two minutes →](https://wpwebhooks.org/docs/get-google-ai-studio-api-key/)

= Is my data safe with the AI Builder? =

Yes. Your provider API keys are encrypted in the Credentials Vault and never returned over the API. Stored webhook credentials are never sent to the AI model, and captured payload values whose field names look sensitive (passwords, tokens, keys) are redacted before any prompt is built. The agent's changes run locally in the plugin — the model only proposes the plan.

= Is this plugin free? =

Yes. The core plugin is completely free and licensed under GPL. Webhook Actions Pro is an optional paid upgrade that adds unlimited conditions, per-webhook retry and backoff settings, Code Glue snippets, External Cron (activated automatically on license activation), and more. [Learn more →](https://wpwebhooks.org/pricing/)

= Does it work with WooCommerce, n8n, Make, Zapier, and AI agents? =

Yes. Any WordPress or WooCommerce action can be a trigger. The plugin delivers to any HTTP endpoint — n8n, Make, Zapier webhook nodes, internal services, or AI agent APIs. Scoped API tokens let Claude Code, Cursor, or any automation tool read logs, retry deliveries, and toggle webhooks without WordPress credentials.

= Do I need extra plugins for Contact Form 7 or IvyForms? =

No. Both integrations are built in. When CF7 or IvyForms is active, submissions are automatically normalized into clean JSON payloads — no additional plugins or custom code required.

= How does retry work? =

5xx and 429 responses retry automatically with exponential backoff (delays of ~30s, 60s, 120s, 240s, 480s, capped at 1 hour). 4xx and 3xx responses are marked `permanently_failed` immediately — bad payloads are not worth retrying. Default maximum is 5 attempts; override with the `fswa_max_attempts` filter or **(Pro)** per-webhook settings.

= Can I access the REST API without a WordPress login? =

Yes. Create a token from the API Tokens screen and pass it as `X-FSWA-Token: <token>` (or `Authorization: Bearer`). Four scopes available — `read`, `operational`, `full`, and `agent` (full write access for AI assistants that never exposes stored secrets) — so you can grant exactly the access each integration needs. Full API reference at [wpwebhooks.org/webhook-wordpress-plugin-api/](https://wpwebhooks.org/webhook-wordpress-plugin-api/)

== Screenshots ==

1. Build with AI — describe the integration in chat; the agent plans, builds, and tests it step by step, with progress in the sidebar and one-click enable when the build completes
2. Webhooks list view
3. Webhook configuration screen
4. Selecting WordPress action triggers
5. Payload mapping configuration
6. Webhook delivery logs with replay and retry controls
7. Queue status overview
8. Settings configuration screen
9. REST API Tokens configuration screen
10. Conditional webhook dispatch — conditions editor
11. Test webhook drawer — send a test delivery and inspect request details inline
12. Webhook Chains — pick an existing chain or create a new one, then select which upstream webhooks should fire this one on their 2xx response
13. Credentials Vault — store reusable authentication secrets (Bearer, Basic, API key, custom) encrypted at rest and reference them from webhooks instead of pasting raw Authorization headers

== Changelog ==

For the full release history see [wpwebhooks.org/changelog/](https://wpwebhooks.org/changelog/)

= 2.2.1 =
- Fixed: Build with AI no longer loses a turn when the AI provider returns a JSON reply cut off just before its final closing brace (seen in the field with Gemini's JSON mode) — a reply missing only its closing brackets is now completed and parsed, while a reply that lost real content is still rejected rather than guessed at
- Improved: the AI Dev Trace now records the provider's reported finish reason for every model call, so a truncated reply is distinguishable from a token-limit stop at a glance
