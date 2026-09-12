=== Webhook Actions - build automations and integrations with AI help ===
Contributors: mateuszflowsystems
Tags: webhooks, automation, zapier, n8n, ai
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 3.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Describe an integration and the AI builds it — no API key needed. Outgoing webhooks from any WordPress or WooCommerce action, queued and retried.

== Description ==

**Describe the integration you want. The AI builds it.** Webhook Actions ships with **Build with AI** — an in-admin agent that turns a plain-language request like *"When a Contact Form 7 form is submitted, send it as JSON to my n8n webhook"* into a working, tested automation. The agent proposes a plan you can review and edit, then creates the webhook, works from a real example payload — your site's own capture, or the Payload Library for an event your site has never fired — maps the fields, sets dispatch conditions, probes your endpoint, and sends a test delivery. Nothing goes live without your confirmation — new webhooks are always created disabled, and you can undo the last change with one click.

📖 [Full documentation at wpwebhooks.org/docs/](https://wpwebhooks.org/docs/)
▶️ [Try it in your browser — no install, no signup, no API key](https://playground.wordpress.net/?blueprint-url=https://wpwebhooks.org/blueprint.json)

= What you can connect =

**Sources — anything in WordPress that fires an action.** Webhook Actions turns any `do_action` into a trigger, so form submissions, WooCommerce orders, user registrations, post publishes and your own custom plugin events can all start an automation. That covers Elementor Forms, WPForms, Forminator, Fluent Forms, Gravity Forms, WooCommerce and your own code — there is no per-plugin add-on to hunt down. Contact Form 7 and IvyForms go one step further with built-in support: their submissions are normalized into clean JSON payloads automatically.

**Destinations — any HTTP endpoint.** The plugin sends outgoing webhooks to anything that accepts a request: an n8n, Make (formerly Integromat), Zapier or Pabbly webhook node, a Slack or Discord incoming-webhook URL for order and form notifications, Airtable, Google Sheets, Mailchimp, HubSpot, Salesforce, Notion, your CRM, an internal microservice, or an AI agent API. There are no bundled per-service connectors and none are needed — point a webhook at a URL, map the fields, and send. Every delivery is queued, retried on failure and logged with full request and response history.

That makes it a no-code way to sync WordPress data outward: describe what you want in chat and let the AI build it, or wire it up by hand in the admin UI. No PHP required either way.

= No API key needed to start =

- **55 free credits, no key and no signup** — claimed automatically on your first prompt. There is no button to press, no account to create and no card; the plugin sends nothing but your site address. That is about five agent turns, or roughly two complete automations built end to end
- **Try it without installing anything** — [Live Preview](https://playground.wordpress.net/?blueprint-url=https://wpwebhooks.org/blueprint.json) (also the button at the top of this page) boots a throwaway WordPress in your browser and runs the real agent on those credits
- **WordPress connectors** — if your site already has an AI provider connected (Settings → Connectors), the builder uses it directly and the plugin stores no keys
- **My own keys** — connect Anthropic, OpenAI or Google in the builder; keys are encrypted in the Credentials Vault and never returned over the API. A free Google AI Studio key gives you Gemini at no cost: [step-by-step tutorial](https://wpwebhooks.org/docs/get-google-ai-studio-api-key/)
- **Your own key always wins** — once a provider of yours is connected, the free credits are never spent
- **Automatic fallback** — if a provider is rate-limited mid-build, the agent switches to another connected provider and keeps going

= What the AI works from =

The agent doesn't guess — it works from real payloads. It maps fields against actually captured payloads, edits existing webhooks by name or id instead of duplicating them, validates endpoints with a guarded probe (SSRF-protected, secrets always redacted), and verifies the result with a real test delivery. Every operation is also published as a WordPress Ability, so external AI tools (Claude Code, Cursor) can drive the same toolset over the Model Context Protocol (MCP) with scoped API tokens.

= The Payload Library =

Mapping fields needs an example payload, and until a trigger fires on your site there is nothing to map against — which is worst on the events that are hardest to produce on demand: a refunded order, a cancelled subscription, a deleted user.

The Payload Library closes that gap with hundreds of hook payloads captured on our own test sites — WordPress core, WooCommerce, ACF and the major form plugins — so the agent can work from the real shape of an event your site has never fired. Your own capture always wins the moment the event really fires, and a reference payload is always labelled as one: the trigger panel shows a "WP Webhooks Payload Library" badge naming the plugin build it came off, and every build made from one ends with a test delivery before the webhook can go live.

What a reference payload cannot know is your own keys. Fields inside containers your site defines — a form's fields, post or order meta, ACF — are never mapped from it; the agent pauses, names the paths, and asks you to fire the event once. Lookups run while Build with AI is on WP Webhooks AI (the free trial or Pro credits) and cost no credits.

= The engine underneath (free) =

- Turn any WordPress do_action into a first-class automation trigger your CRMs, n8n flows, AI agents, and internal services can consume — every dispatch is an outgoing webhook you fully control
- Persistent delivery queue with smart retry and exponential backoff — powered by WP-Cron, auto-upgrades to Action Scheduler or System Cron when available, **(Pro)** External Cron for guaranteed reliability
- Per-event UUID and ISO 8601 timestamp — enable downstream deduplication
- Delivery logs with full attempt history, request/response inspection, replay, and bulk retry
- Code Glue — attach PHP snippets to any webhook+trigger to reshape the payload before dispatch or run side effects after the response, with a preview that runs your code against a real captured payload first. Build with AI can draft, test-run and assign them for you
- Per-webhook retry limit and backoff strategy (exponential, linear or fixed), each falling back to a site-wide default
- Synchronous execution mode — fire inline without queue delay
- Payload mapping — rename, restructure, exclude, and type-cast fields with dot-notation paths
- Conditional dispatch — filter events by payload field values before dispatch, so a Slack notification or a CRM sync only fires when it should. Build as many rules as you need and group them with AND/OR matching, by hand or by describing them to Build with AI
- HTTP method, custom headers, and URL query parameters per webhook
- Dynamic endpoint URLs — `{{ field.path }}` placeholders resolved against the payload at dispatch time, or the `fswa_webhook_url` filter if you would rather do it in PHP
- Webhook Chains — wire 2xx completions to downstream webhooks with full observability
- Import & Export — move webhooks and chains between sites as portable JSON (triggers, field mapping, conditions and Code Glue snippets included), with strict validation and a per-item result summary on import
- Markdown descriptions — document what each webhook and chain does inline, with a Write/Preview toggle while editing
- Credentials Vault — store reusable auth secrets (Bearer, Basic, API key, custom) encrypted at rest; reference them from webhooks instead of pasting raw Authorization headers. Secrets are write-only over the API — never returned, only a masked hint
- Activity History — persistent audit log of every admin and API-token action
- Built-in CF7 and IvyForms integrations — structured payloads, no extra plugins
- Action Scheduler auto-detection — more reliable delivery on high-traffic sites
- Fully translatable — the entire admin interface and all server-side strings are internationalized; ships with Polish, Simplified Chinese, and Dutch, and is compatible with WPML and Polylang String Translation
- Full REST API with scoped API token authentication (`read` / `operational` / `full` / `agent`) — the `agent` scope grants full write access for AI assistants while never exposing stored secrets
- Developer extensibility — 16 filters and 7 action hooks ([reference](https://wpwebhooks.org/docs/))

= Pro features =

- AI credits included — Build with AI runs through the hosted WP Webhooks AI service on every Pro plan: no API keys to create, no provider accounts, a monthly credit allowance that renews automatically, and a live credits counter in the builder. Your own keys and WordPress connectors stay available any time
- External Cron — replace unreliable visitor-triggered WP-Cron with a managed external pinger, provisioned automatically on license activation. Two modes: plugin queue endpoint (down to 20 s interval, configurable batch size) or WP-Cron endpoint (60 s, covers all WordPress background work). No server crontab or external dashboard — controlled entirely from wp-admin, with a live heartbeat chart and inline error alerts

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

No key, and nothing to sign up for. Your first prompt automatically claims **55 free credits** — no account, no card, and the only thing the plugin sends is your site address. That is about five agent turns, or roughly two complete automations built end to end, which is enough to find out whether this works for you before committing anything.

When the credits run out you have three ways to carry on, and two of them are free. If your site has an AI provider connected in WordPress (Settings → Connectors), the builder uses it with no extra setup. Otherwise bring your own Anthropic, OpenAI or Google key — a free Google AI Studio key gives you Gemini at no cost. [Here's how to get one in two minutes →](https://wpwebhooks.org/docs/get-google-ai-studio-api-key/) Or move to Pro, which includes a hosted credit pool and no keys at all. Whichever you pick, a site with its own key never spends the free credits.

= Is my data safe with the AI Builder? =

Yes. Your provider API keys are encrypted in the Credentials Vault and never returned over the API. Stored webhook credentials are never sent to the AI model, and captured payload values whose field names look sensitive (passwords, tokens, keys) are redacted before any prompt is built. The agent's changes run locally in the plugin — the model only proposes the plan.

= Is this plugin free? =

Yes. The core plugin is completely free and licensed under GPL. Webhook Actions Pro is an optional paid upgrade that adds two things, both of which run on our infrastructure rather than yours: included AI credits for Build with AI (hosted, no API keys needed) and External Cron (activated automatically on license activation). Everything the plugin does — Code Glue, unlimited AND/OR conditions, per-webhook retry and backoff, dynamic URL templates, and the AI agent that can build all of them for you — is free as of 3.0.0. [Learn more →](https://wpwebhooks.org/pricing/)

= Does it work with WooCommerce, n8n, Make, Zapier, and AI agents? =

Yes. Any WordPress or WooCommerce action can be a trigger. The plugin delivers to any HTTP endpoint — n8n, Make, Zapier webhook nodes, internal services, or AI agent APIs. Scoped API tokens let Claude Code, Cursor, or any automation tool read logs, retry deliveries, and toggle webhooks without WordPress credentials.

= Is this a Zapier or Make alternative? =

For the WordPress half of the job, yes — and it is worth being precise about which half. Zapier, Make and Pabbly are multi-app orchestrators with large connector catalogues and a visual canvas. If your workflow joins several SaaS products that have nothing to do with WordPress, that is what they are for, and this plugin is not an alternative to it.

What it does replace is the metered hop out of WordPress. Rather than spending a task every time an order or a form entry leaves your site, your own server sends it — queued, retried with exponential backoff, and logged with full request and response history. Webhook Chains let one delivery trigger the next on a 2xx response, so a multi-step flow can stay on your own infrastructure. There is no connector catalogue here: you point a webhook at a URL and map the fields.

Plenty of sites run both — this plugin as the unmetered, self-hosted exit from WordPress, with a Zapier or Make node on the far end when a workflow genuinely needs their catalogue.

= Does it work with Elementor Forms, WPForms, Forminator, Fluent Forms or Gravity Forms? =

Yes. These plugins fire their own WordPress actions when a form is submitted, and Webhook Actions can use any of them as a trigger — so you can send an Elementor Forms or WPForms submission straight to a webhook without a dedicated add-on. Pick the plugin's submit action in the trigger list, capture a real submission to see the payload, then map the fields you want. Contact Form 7 and IvyForms additionally ship with built-in normalization; the others use the generic trigger path.

= Can I send WordPress data to Slack, Google Sheets, Airtable or a CRM? =

Yes — any destination that accepts an HTTP request works. For Slack or Discord, paste the incoming-webhook URL they give you and map the payload into the message field. For Google Sheets, Airtable, Mailchimp, HubSpot or Salesforce, either call their API directly or point the webhook at an n8n, Make or Zapier node and let it do the last hop. Nothing here needs a per-service connector, because the plugin speaks plain HTTP.

= Do I need extra plugins for Contact Form 7 or IvyForms? =

No. Both integrations are built in. When CF7 or IvyForms is active, submissions are automatically normalized into clean JSON payloads — no additional plugins or custom code required.

= How does retry work? =

The dispatcher retries 5xx and 429 responses automatically with exponential backoff. The delay before attempt N is `base_delay × 2^N`, capped at the maximum delay — so on the defaults (exponential, 30s base, 1 hour cap, 5 attempts) a failing delivery is retried after 60s, 120s, 240s and 480s, and is marked `permanently_failed` roughly 15 minutes after the first attempt. The 1 hour cap only comes into play if you raise the attempt limit or the base delay. 4xx and 3xx responses are marked `permanently_failed` immediately — bad payloads are not worth retrying. Override the attempt limit per webhook in the UI, or globally with the `fswa_max_attempts` filter; the backoff strategy (exponential, linear or fixed) and its base and maximum delays are per-webhook settings too, each falling back to a site-wide default.

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

= 3.2.0 — 2026-09-09 =
- Added: the WP Webhooks API Docs Library. When a build targets a named service — HubSpot, Airtable, Slack, Notion, Mailchimp, SendGrid, Stripe, Telegram, Pipedrive, Trello, Discord and more — WP Webhooks AI reads that service's current API reference on our server (endpoint, auth header, body envelope, request-level flags, common errors) and builds from it instead of from memory. Available when Build with AI runs on WP Webhooks AI (Pro credits or the free trial); the reference is injected server-side and costs about one credit per turn
- Added: every reply built from a reference card carries a "WP Webhooks API Docs Library" pill naming the service, the operation and the date the reference was verified, linking to the vendor's documentation. A card the library researched on its own is marked auto-researched
- Added: a service the library has not documented yet is researched on the spot from the vendor's own docs and saved for everyone. The chat shows "Reading …'s API reference for the first time" while it waits — usually under a minute — and no credits are spent on the wait. If the reference is not ready in time the build goes ahead on the model's own knowledge and says so
- Changed: the hosted transports announce what they can do to the API (`features`), so a plugin that cannot wait for research is never held
- Changed: Build with AI now adds a credential step for any destination that needs a token — HubSpot, Airtable, Slack, an n8n header — and lets you pick or create it in the plan review, instead of asking in chat whether you have one

= 3.1.1 — 2026-09-10 =
- Fixed: hooks whose name a plugin builds at runtime were offered as webhook triggers even when they are filters. Advanced Custom Fields is the clearest case — `acf/update_value/type=select` and `acf/validate_field/type=text` were listed, and a webhook on a filter takes the handler's empty return as the filtered value, so choosing one silently destroyed the field being saved. Discovery now recognises a filter name assembled from fragments, and a hook nested under a confirmed filter, and refuses both. 282 such names were being offered on a site running 20 plugins; no real trigger was lost.
- Fixed: the default retry backoff schedule was documented incorrectly.
