=== Webhook Actions by Flow Systems ===
Contributors: mateuszflowsystems
Tags: webhooks, automation, integration, n8n, api
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.14.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Turn any WordPress do_action into a first-class automation trigger your CRMs, n8n flows, AI agents, and internal services can consume.

== Description ==

Operate WordPress like modern infrastructure — turn any WordPress do_action into a first-class automation trigger your CRMs, n8n flows, AI agents, and internal services can consume.

📖 [Full documentation at wpwebhooks.org/docs/](https://wpwebhooks.org/docs/)

= Core features (free) =

- Persistent delivery queue with smart retry and exponential backoff — powered by WP-Cron, auto-upgrades to Action Scheduler or System Cron when available, **(Pro)** External Cron for guaranteed reliability
- Per-event UUID and ISO 8601 timestamp — enable downstream deduplication
- Delivery logs with full attempt history, request/response inspection, replay, and bulk retry
- Synchronous execution mode — fire inline without queue delay
- Payload mapping — rename, restructure, exclude, and type-cast fields with dot-notation paths
- Conditional dispatch — filter events by payload field values before dispatch
- HTTP method, custom headers, and URL query parameters per webhook
- Dynamic endpoint URLs — `{{ field.path }}` placeholders resolved at dispatch time (free via `fswa_webhook_url` filter)
- Webhook Chains — wire 2xx completions to downstream webhooks with full observability
- Activity History — persistent audit log of every admin and API-token action
- Built-in CF7 and IvyForms integrations — structured payloads, no extra plugins
- Action Scheduler auto-detection — more reliable delivery on high-traffic sites
- Full REST API with scoped API token authentication (`read` / `operational` / `full`)
- Developer extensibility — 16 filters and 7 action hooks ([reference](https://wpwebhooks.org/docs/))

= Pro features =

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
3. Navigate to Webhook Actions in the admin menu.
4. Add your webhook endpoint URL and select the desired WordPress action triggers.

== Frequently Asked Questions ==

= Is this plugin free? =

Yes. The core plugin is completely free and licensed under GPL. Webhook Actions Pro is an optional paid upgrade that adds unlimited conditions, per-webhook retry and backoff settings, Code Glue snippets, External Cron (activated automatically on license activation), and more. [Learn more →](https://wpwebhooks.org/pricing/)

= Does it work with WooCommerce, n8n, Make, Zapier, and AI agents? =

Yes. Any WordPress or WooCommerce action can be a trigger. The plugin delivers to any HTTP endpoint — n8n, Make, Zapier webhook nodes, internal services, or AI agent APIs. Scoped API tokens let Claude Code, Cursor, or any automation tool read logs, retry deliveries, and toggle webhooks without WordPress credentials.

= Do I need extra plugins for Contact Form 7 or IvyForms? =

No. Both integrations are built in. When CF7 or IvyForms is active, submissions are automatically normalized into clean JSON payloads — no additional plugins or custom code required.

= How does retry work? =

5xx and 429 responses retry automatically with exponential backoff (delays of ~30s, 60s, 120s, 240s, 480s, capped at 1 hour). 4xx and 3xx responses are marked `permanently_failed` immediately — bad payloads are not worth retrying. Default maximum is 5 attempts; override with the `fswa_max_attempts` filter or **(Pro)** per-webhook settings.

= Can I access the REST API without a WordPress login? =

Yes. Create a token from the API Tokens screen and pass it as `X-FSWA-Token: <token>` (or `Authorization: Bearer`). Three scopes available — `read`, `operational`, `full` — so you can grant exactly the access each integration needs. Full API reference at [wpwebhooks.org/webhook-wordpress-plugin-api/](https://wpwebhooks.org/webhook-wordpress-plugin-api/)

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
11. Webhook Chains — pick an existing chain or create a new one, then select which upstream webhooks should fire this one on their 2xx response

== Changelog ==

For the full release history see [wpwebhooks.org/changelog/](https://wpwebhooks.org/changelog/)

= 1.14.1 — 2026-06-05 =
- Fixed: "Get Pro" links updated to `/pricing/` page
- Improved: Admin Menu moved to its own settings card
- Improved: External Cron description expanded in README

