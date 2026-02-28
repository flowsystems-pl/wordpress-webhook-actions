=== Flow Systems Webhook Actions ===
Contributors: mateuszflowsystems
Tags: webhook, woocommerce, automation, n8n, integration
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Production-safe WordPress webhooks with retries, event IDs, queue processing, and full delivery observability.

== Description ==

Flow Systems Webhook Actions is a developer-focused WordPress webhook delivery layer designed for reliable automation workflows.

Trigger HTTP webhooks from any WordPress or WooCommerce action (`do_action`) and dispatch them asynchronously through a persistent queue with smart retries, event identity, and full delivery visibility.

Unlike basic “fire-and-forget” webhook implementations, this plugin ensures:

- Delivery attempts are tracked
- Failures are visible
- Retries are automatic and intelligent
- Events include stable identity metadata for idempotency

Built for production environments where losing events is not acceptable.

= Typical Use Cases =

- Send WooCommerce orders to n8n with retry protection
- Sync WordPress users to external CRMs safely
- Trigger backend microservices from WP hooks
- Send event-driven data to internal APIs
- Replace fragile custom `wp_remote_post()` integrations
- Build idempotent WordPress automation pipelines

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

= Delivery Observability =

Operational visibility built into the admin panel:

Status states: `pending`, `processing`, `success`, `failed` (retrying), `permanently_failed`

- Attempt timeline per event
- HTTP status codes and response bodies
- Manual retry (single or bulk)

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

= Developer Friendly =

- Works with any WordPress or WooCommerce action
- Internal REST endpoints power the admin interface
- Fully extensible via filters and actions
- Clean namespace and unique prefixes
- Built according to WordPress.org standards
- Supports system cron for improved reliability

= Why Choose Flow Systems Webhook Actions? =

Most WordPress webhook setups fire once, don't retry intelligently, don't provide delivery visibility, and don't expose event identity.

Flow Systems Webhook Actions provides:

- Persistent queue
- Smart retry logic
- Permanent failure state handling
- Event UUIDs and timestamps
- Full delivery logging and metrics

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

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/flowsystems-webhook-actions` directory, or install the plugin through the WordPress plugins screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to Webhook Actions in the admin menu.
4. Add your webhook endpoint URL and select the desired WordPress action triggers.

== Frequently Asked Questions ==

= What is a WordPress action? =

An action is a WordPress hook triggered at a specific moment, such as when a user is created, a post is saved, or an order is completed.

= Can I use this plugin with n8n or other automation tools? =

This plugin works seamlessly with n8n webhook triggers and can be used with any automation platform or external API that accepts HTTP webhooks.

= Does this plugin support WooCommerce hooks? =

Yes. Any WooCommerce action can be used as a trigger, as long as the hook is available.

= How does the retry mechanism work? =

Failed webhooks are automatically retried using exponential backoff. The delay increases with each attempt (e.g., 1 minute, 2 minutes, 4 minutes, 8 minutes), up to a maximum delay of 1 hour between retries. By default, 5 attempts are made before marking a job as failed. The retry behavior can be adjusted using available filters.

= What is Payload Mapping? =

Payload Mapping allows you to transform the webhook payload before it is sent. You can rename fields, reorganize the structure, or exclude sensitive data. The plugin can store example payloads to help configure mappings.

= Can I include user data in webhook payloads? =

Yes. For user-related triggers (such as `user_register`, `profile_update`, `wp_login`, `wp_logout`), you can enable "Include User Data" to automatically add user information (ID, email, display name, roles, etc.) to the payload.

= Is this plugin free? =

Yes. The plugin is completely free and licensed under GPL.

== Screenshots ==

1. Webhooks list view
2. Webhook configuration screen
3. Selecting WordPress action triggers
4. Payload mapping configuration
5. Webhook delivery logs
6. Queue status overview
7. Settings configuration screen

== Changelog ==

= 1.1.0 =
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

= 1.0.1 =
- Fixed preview freezing when mapping fields from objects with numeric string keys (e.g. WooCommerce line_items)
- Fixed orphaned pending log entries caused by logPending() silently failing — queue jobs now carry mapping metadata and recover a proper log entry if the original ID was lost
- Enhanced normalizeValue to handle Closure, DateTimeInterface, and Traversable types
- Removed unnecessary WooCommerce hook patterns from trigger exclusions
- Improved log details display with word break for long trigger names and dates

= 1.0.0 =
- Initial release
- Webhook dispatching from WordPress actions
- Background processing with retry mechanism
- Configurable webhook payloads
- Logging of webhook deliveries

== Upgrade Notice ==

= 1.1.0 =
This release adds new database columns (`event_uuid`, `event_timestamp`, `attempt_history`, `next_attempt_at` on logs; `log_id` on queue). The migration runs automatically on plugin activation or update. No manual steps required.

= 1.0.0 =
Initial stable release.