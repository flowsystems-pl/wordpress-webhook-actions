=== Flow Systems Webhook Actions ===
Contributors: mateuszflowsystems
Tags: webhooks, automation, integration, n8n, api
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Reliable WordPress webhooks with retries, queue, Action Scheduler support, delivery logs, and replayable events for automation workflows (n8n, APIs, integrations).

== Description ==

Flow Systems Webhook Actions is a developer-focused WordPress webhook delivery layer designed for reliable automation workflows.

It adds a persistent queue, automatic retries, and Action Scheduler support for production-grade background processing — so your webhooks don’t get lost when external APIs fail.

Works great with WooCommerce, n8n, Zapier alternatives, and custom APIs.

Includes built-in Contact Form 7 integration — send CF7 form submissions to webhooks instantly with clean, structured payloads. Replace fragile CF7 email workflows with reliable webhook-based automation.


Unlike basic “fire-and-forget” webhook implementations, this plugin ensures:

- Delivery attempts are tracked
- Failures are visible
- Retries are automatic and intelligent
- Events include stable identity metadata for idempotency

Built for production environments where losing events is not acceptable.

👉 Example: [Send Contact Form 7 submissions to a webhook (n8n demo)](https://flowsystems.pl/examples/cf7-to-webhook/)

= Typical Use Cases =

- Send Contact Form 7 submissions to n8n or external APIs
- Build reliable form-to-CRM integrations with retry protection
- Process high-volume WooCommerce webhooks using Action Scheduler
- Send WooCommerce orders to n8n with retry protection
- Sync WordPress users to external CRMs safely
- Trigger backend microservices from WP hooks
- Send event-driven data to internal APIs
- Replace fragile custom `wp_remote_post()` integrations
- Build idempotent WordPress automation pipelines
- Query delivery logs, trigger retries, or manage webhooks programmatically from CI/CD pipelines or external dashboards using API tokens
- Allow AI coding assistants (e.g. Claude Code) to inspect webhook logs and retry failed events automatically
- Use AI agents to monitor webhook delivery health and operate the queue through the REST API

= Event Identity & Idempotency =

Every dispatched webhook includes:

- Unique UUID (v4) per event
- ISO 8601 UTC timestamp
- Embedded `event.id`, `event.timestamp`, `event.version` in the payload
- HTTP headers: `X-Event-Id`, `X-Event-Timestamp`

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

Flow Systems Webhook Actions allows you to replay any webhook event directly from the delivery logs — including successful deliveries.

This makes it easy to:

- Re-run automation workflows
- Debug external integrations
- Recover from temporary endpoint failures
- Test webhook consumers without recreating WordPress events

Each replay uses the original payload and event metadata, ensuring consistent behavior across retries and debugging sessions.

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
- Store example payloads for configuration
- Modify via `fswa_payload` filter

Payloads always include stable event metadata for consistency.

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

Full REST API documentation: [REST API Reference](https://flowsystems.pl/webhook-wordpress-plugin-api/)

= AI Agents and Programmatic Automation =

The REST API makes Flow Systems Webhook Actions accessible to AI-powered tools and coding agents.

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

= Contact Form 7 Webhooks (NEW in 1.5.0) =

Flow Systems Webhook Actions includes built-in integration with Contact Form 7.

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

= Action Scheduler Support (NEW in 1.4.0) =

Flow Systems Webhook Actions now supports Action Scheduler — the same background job system used by WooCommerce.

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
- Fully extensible via filters and actions
- Clean namespace and unique prefixes
- Built according to WordPress.org standards
- Supports system cron, WP-Cron, and Action Scheduler (auto-detected)

= Why Choose Flow Systems Webhook Actions? =

Most WordPress webhook setups fire once, don't retry intelligently, don't provide delivery visibility, and don't expose event identity.

Flow Systems Webhook Actions provides:

- Persistent queue
- Smart retry logic
- Webhook replay for debugging integrations
- Permanent failure state handling
- Event UUIDs and timestamps
- Full delivery logging and metrics
- REST API with token authentication for programmatic access
- Action Scheduler support for reliable background processing (when available)
- Built-in CF7 to webhook support (no extra plugins needed)

Built for developers who need production-grade automation reliability.

= Available Filters =

- `fswa_should_dispatch` – Decide if a trigger should dispatch
- `fswa_payload` – Customize webhook payload
- `fswa_headers` – Add custom HTTP headers
- `fswa_require_https` – Toggle HTTPS requirement
- `fswa_max_attempts` – Configure maximum retry attempts
- `fswa_queue_batch_size` – Configure batch processing size
- `fswa_http_timeout` – Configure HTTP request timeout
- `fswa_http_connect_timeout` – Configure HTTP connect timeout
- `fswa_http_args` – Customize HTTP request arguments
- `fswa_available_triggers` – Customize available trigger list

= Available Actions =

- `fswa_success` – Fired after successful webhook delivery
- `fswa_error` – Fired after webhook delivery failure

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

Flow Systems Webhook Actions automatically uses Action Scheduler when available.

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
You can replay any event directly from the logs, which is useful for debugging integrations or re-running automation workflows.

= What is Payload Mapping? =

Payload Mapping allows you to transform the webhook payload before it is sent. You can rename fields, reorganize the structure, or exclude sensitive data. The plugin can store example payloads to help configure mappings.

= Can I include user data in webhook payloads? =

Yes. For user-related triggers (such as `user_register`, `profile_update`, `wp_login`, `wp_logout`), you can enable "Include User Data" to automatically add user information (ID, email, display name, roles, etc.) to the payload.

= Can I access the REST API without a WordPress login? =

Yes. Create an API token from the API Tokens screen in the admin panel and use it in the `X-FSWA-Token` header (or `Authorization: Bearer`) with your requests. Tokens support three scopes — `read`, `operational`, and `full` — so you can grant only the access level each integration needs.

= Is this plugin free? =

Yes. The plugin is completely free and licensed under GPL.

== Screenshots ==

1. Webhooks list view
2. Webhook configuration screen
3. Selecting WordPress action triggers
4. Payload mapping configuration
5. Webhook delivery logs with replay and retry controls
6. Queue status overview
7. Settings configuration screen
8. REST API Tokens configuration screen

== Changelog ==

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
- Added [REST API Reference](https://flowsystems.pl/webhook-wordpress-plugin-api/) link to the plugin description

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