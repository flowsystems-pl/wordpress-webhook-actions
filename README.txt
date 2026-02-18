=== Flow Systems Webhook Actions ===
Contributors: mateuszflowsystems
Tags: webhook, woocommerce, automation, hooks, n8n
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

WordPress webhook plugin for developers. Trigger HTTP webhooks from any WordPress or WooCommerce action with async retries and payload mapping.

== Description ==

Flow Systems Webhook Actions is a WordPress webhook plugin that lets you trigger HTTP webhooks from any WordPress or WooCommerce action (`do_action`).

Instead of writing custom integration code, you can configure webhook endpoints directly from the admin panel and send structured JSON payloads to automation tools or external APIs.

Webhooks are dispatched asynchronously with background processing, retry logic, and delivery logging to ensure reliable and non-blocking execution.

= Typical use cases =

- Send WooCommerce order data to n8n
- Sync new WordPress users to a CRM
- Trigger Slack notifications when a post is published
- Send form submissions to an external API
- Automate membership or subscription workflows
- Connect WordPress events to internal backend systems

= Webhook Triggering =

- Trigger webhooks from any WordPress action (`do_action`)
- Support for core, custom, and WooCommerce hooks
- JSON payload including hook name, arguments, timestamp, and site URL
- Configurable webhook URL and optional Authorization header
- HTTPS enforcement by default (configurable via filter)

= Queue System =

- Asynchronous background processing via WP-Cron
- Non-blocking execution to avoid slowing down user requests
- Automatic retry with exponential backoff

= Payload Mapping =

- Transform payload structure before dispatch
- Rename fields using dot notation
- Exclude selected fields from webhook payload
- Restructure payload to match external API requirements
- Store example payloads to assist configuration

= Logging =

- Log webhook delivery attempts
- Store HTTP status codes and response bodies
- View delivery history per webhook
- Automatic cleanup based on retention settings

= Developer Friendly =

- Internal REST endpoints used by the admin interface
- Extensible via WordPress filters and actions
- Clean namespace and unique prefixes to avoid conflicts
- Built following WordPress.org coding standards

= Why choose Flow Systems Webhook Actions? =

- Works with any WordPress or WooCommerce action
- Reliable background dispatch with retry logic
- Payload mapping for adapting data to external systems
- Transparent logging and delivery tracking
- Designed for automation builders and developers

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

= Can I use this plugin with n8n? =

Yes. This plugin works seamlessly with n8n webhook triggers and is designed with automation workflows in mind.

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

= 1.0.0 =
Initial stable release.