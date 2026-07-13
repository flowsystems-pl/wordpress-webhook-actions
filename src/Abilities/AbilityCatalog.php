<?php

namespace FlowSystems\WebhookActions\Abilities;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Api\AuthHelper;

/**
 * Declarative catalog of AI Builder / MCP abilities: label, description, scope,
 * confirm policy and JSON-Schema for every operation, plus the callback that runs
 * it (a method on one of {@see AbilityRegistry}'s handler collaborators —
 * ReadAbilities / WriteAbilities / TestAbilities; the registry owns the
 * fswa_ability_definitions filter). Kept separate from the registry so the
 * large, purely-declarative definitions don't crowd the executable logic.
 */
class AbilityCatalog {
  /**
   * All ability definitions keyed by short name. Callbacks are bound to the
   * passed registry's handler collaborators.
   *
   * @return array<string, array<string, mixed>>
   */
  public static function build(AbilityRegistry $r): array {
    $reads  = $r->reads();
    $writes = $r->writes();
    $tests  = $r->tests();

    $definitions = [
      // ---- Discovery / read --------------------------------------------
      'list_triggers' => [
        'label'        => __('List available triggers', 'flowsystems-webhook-actions'),
        'description'  => __('List WordPress do_action hooks discovered on this site (runtime + static scan) that can be used as webhook triggers. The full catalog is LARGE (hundreds of hooks): when hunting for a specific plugin\'s hooks, always pass search (matches hook name or plugin slug, e.g. {"search":"cf7"}) instead of listing everything. Results cap at 200 with a total count.', 'flowsystems-webhook-actions'),
        'category'     => 'webhook-actions',
        'scope'        => AuthHelper::SCOPE_READ,
        'input_schema' => [
          'type'       => 'object',
          'properties' => ['search' => ['type' => 'string', 'description' => 'Case-insensitive substring filter on hook name or source plugin slug.']],
        ],
        'callback'     => [$reads, 'listTriggers'],
      ],
      'list_webhooks' => [
        'label'        => __('List webhooks', 'flowsystems-webhook-actions'),
        'description'  => __('List all configured webhooks with their triggers and status.', 'flowsystems-webhook-actions'),
        'category'     => 'webhook-actions',
        'scope'        => AuthHelper::SCOPE_READ,
        'input_schema' => ['type' => 'object', 'properties' => (object) []],
        'callback'     => [$reads, 'listWebhooks'],
      ],
      'get_webhook' => [
        'label'        => __('Get a webhook', 'flowsystems-webhook-actions'),
        'description'  => __('Get a single webhook by id, including triggers, mapping, conditions, headers and credential assignment.', 'flowsystems-webhook-actions'),
        'category'     => 'webhook-actions',
        'scope'        => AuthHelper::SCOPE_READ,
        'input_schema' => [
          'type'       => 'object',
          'properties' => ['id' => ['type' => 'integer', 'description' => 'Webhook id']],
          'required'   => ['id'],
        ],
        'callback'     => [$reads, 'getWebhook'],
      ],
      'get_trigger_schema' => [
        'label'        => __('Get captured payload + mapping for a trigger', 'flowsystems-webhook-actions'),
        'description'  => __('Return the last captured example payload, field mapping and conditions for a trigger so the agent can map against the real payload shape. Pass webhook_id to read that webhook\'s own capture; omit it to get the latest example for the trigger from any webhook. If the result carries a capture_warning, the capture is stale/opaque (no mappable fields): follow the warning — show the user what the capture contains and ask them to re-fire the event — instead of proposing mappings.', 'flowsystems-webhook-actions'),
        'category'     => 'webhook-actions',
        'scope'        => AuthHelper::SCOPE_READ,
        'input_schema' => [
          'type'       => 'object',
          'properties' => [
            'webhook_id' => ['type' => 'integer', 'description' => 'Optional — omit for a trigger-wide lookup.'],
            'trigger'    => ['type' => 'string'],
          ],
          'required'   => ['trigger'],
        ],
        'callback'     => [$reads, 'getTriggerSchema'],
      ],
      'get_rest_route_schema' => [
        'label'        => __('Get a REST route\'s argument schema (this site)', 'flowsystems-webhook-actions'),
        'description'  => __('Describe a REST API route ON THIS SITE (core or any plugin) from its self-declared schema: every argument with its type, description and whether it is REQUIRED. ALWAYS run this before building an internal automation (a webhook whose endpoint_url points at this site\'s own wp-json) and satisfy every required argument — e.g. POST /wp/v2/users requires username, email AND password, which mapping alone cannot always supply. Pass the route path relative to /wp-json (e.g. "/wp/v2/users") and the HTTP method you plan to use. Internal routes only — it cannot describe external APIs.', 'flowsystems-webhook-actions'),
        'category'     => 'webhook-actions',
        'scope'        => AuthHelper::SCOPE_READ,
        'input_schema' => [
          'type'       => 'object',
          'properties' => [
            'route'  => ['type' => 'string', 'description' => 'Route path relative to /wp-json, e.g. "/wp/v2/users". A full URL to this site\'s REST API is also accepted.'],
            'method' => ['type' => 'string', 'enum' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], 'default' => 'POST'],
          ],
          'required'   => ['route'],
        ],
        'callback'     => [$reads, 'getRestRouteSchema'],
      ],
      'get_logs' => [
        'label'        => __('Get delivery logs', 'flowsystems-webhook-actions'),
        'description'  => __('Read recent delivery logs (optionally for one webhook) to verify what was sent and how the endpoint responded.', 'flowsystems-webhook-actions'),
        'category'     => 'webhook-actions',
        'scope'        => AuthHelper::SCOPE_READ,
        'input_schema' => [
          'type'       => 'object',
          'properties' => [
            'webhook_id' => ['type' => 'integer'],
            'limit'      => ['type' => 'integer', 'default' => 10],
          ],
        ],
        'callback'     => [$reads, 'getLogs'],
      ],
      'list_credentials' => [
        'label'        => __('List credentials', 'flowsystems-webhook-actions'),
        'description'  => __('List vault credentials (names, types and masked hints only — secrets are never returned).', 'flowsystems-webhook-actions'),
        'category'     => 'webhook-actions',
        'scope'        => AuthHelper::SCOPE_FULL,
        'input_schema' => ['type' => 'object', 'properties' => (object) []],
        'callback'     => [$reads, 'listCredentials'],
      ],

      // ---- Build / write -----------------------------------------------
      'create_webhook' => [
        'label'        => __('Create a webhook', 'flowsystems-webhook-actions'),
        'description'  => __('Create a new webhook (always created DISABLED until the user enables it). Provide name, endpoint_url, http_method and triggers.', 'flowsystems-webhook-actions'),
        'category'     => 'webhook-actions',
        'scope'        => AuthHelper::SCOPE_FULL,
        'input_schema' => [
          'type'       => 'object',
          'properties' => [
            'name'               => ['type' => 'string'],
            'endpoint_url'       => ['type' => 'string'],
            'http_method'        => ['type' => 'string', 'enum' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], 'default' => 'POST'],
            'triggers'           => ['type' => 'array', 'items' => ['type' => 'string']],
            'auth_credential_id' => ['type' => 'integer'],
            'custom_headers'     => ['type' => 'object'],
            'url_params'         => ['type' => 'object'],
            'is_synchronous'     => ['type' => 'boolean', 'description' => 'Delivery mode. false = ASYNCHRONOUS (queued and delivered in the background on the next cron tick, with automatic retries; does not slow the triggering request — but nothing is delivered until the queue processor runs). true = SYNCHRONOUS (delivered inline during the triggering request, so it works even when no reliable cron is running, at the cost of a little added latency on that request). Pick based on the DELIVERY MODE line below; when omitted this follows the site default (asynchronous).'],
          ],
          'required'   => ['name', 'endpoint_url'],
        ],
        'callback'     => [$writes, 'createWebhook'],
      ],
      'update_webhook' => [
        'label'            => __('Update a webhook', 'flowsystems-webhook-actions'),
        'description'      => __('Update an existing webhook (endpoint, method, triggers, headers, credential).', 'flowsystems-webhook-actions'),
        'category'         => 'webhook-actions',
        'scope'            => AuthHelper::SCOPE_FULL,
        'requires_confirm' => false,
        'input_schema'     => [
          'type'       => 'object',
          'properties' => [
            'id'                 => ['type' => 'integer'],
            'name'               => ['type' => 'string'],
            'endpoint_url'       => ['type' => 'string'],
            'http_method'        => ['type' => 'string', 'enum' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']],
            'triggers'           => ['type' => 'array', 'items' => ['type' => 'string']],
            'auth_credential_id' => ['type' => 'integer'],
            'custom_headers'     => ['type' => 'object'],
            'url_params'         => ['type' => 'object'],
            'is_synchronous'     => ['type' => 'boolean', 'description' => 'Delivery mode. false = asynchronous (queued, background delivery on the next cron tick, with retries). true = synchronous (delivered inline during the triggering request; works without a reliable cron, adds a little latency). See the DELIVERY MODE line below.'],
          ],
          'required'   => ['id'],
        ],
        'callback'         => [$writes, 'updateWebhook'],
      ],
      'set_mapping' => [
        'label'        => __('Set field mapping', 'flowsystems-webhook-actions'),
        'description'  => __('Set the payload field mapping for a webhook+trigger. field_mapping MUST be an object {"mappings":[{"source":"<dot.path in the captured payload>","target":"<dot.path in the outgoing body>","cast":"number|string|boolean|stringify" (optional)}],"excluded":["<dot.path to drop>"],"includeUnmapped":true|false}. It can only move/rename/exclude EXISTING payload fields — static or constant values are NOT supported (inject those with a pre-dispatch Code Glue snippet instead). The mapping runs BEFORE any pre-dispatch snippet, so snippet output cannot be mapped. Run get_trigger_schema first and take source paths from the real captured payload.', 'flowsystems-webhook-actions'),
        'category'     => 'webhook-actions',
        'scope'        => AuthHelper::SCOPE_FULL,
        'input_schema' => [
          'type'       => 'object',
          'properties' => [
            'webhook_id'    => ['type' => 'integer'],
            'trigger'       => ['type' => 'string'],
            'field_mapping' => ['type' => 'object', 'description' => 'Mapping definition as used by the mapping UI.'],
          ],
          'required'   => ['webhook_id', 'trigger', 'field_mapping'],
        ],
        'callback'     => [$writes, 'setMapping'],
      ],
      'set_conditions' => [
        'label'        => __('Set conditions', 'flowsystems-webhook-actions'),
        'description'  => __('Set conditional-dispatch rules for a webhook+trigger so only matching events leave the site. conditions MUST be an object {"enabled":true,"type":"and"|"or","rules":[{"field":"<dot.path into the captured payload>","operator":"equals|not_equals|contains|not_contains|greater_than|less_than|is_empty|is_not_empty|is_true|is_false|array_contains|object_contains","value":"..."}]}. A rule may add an optional "cast":"number"|"string"|"boolean"|"stringify" to coerce the payload value before comparing (e.g. numeric compare on a string field). A rules item may also be a nested group {"type":"group","match":"and"|"or","rules":[...]} (Pro only; without a Pro license only ONE simple rule with type "and" is allowed). Run get_trigger_schema first and take field paths from the real captured payload (e.g. "args.0.form_id").', 'flowsystems-webhook-actions'),
        'category'     => 'webhook-actions',
        'scope'        => AuthHelper::SCOPE_FULL,
        'input_schema' => [
          'type'       => 'object',
          'properties' => [
            'webhook_id'             => ['type' => 'integer'],
            'trigger'                => ['type' => 'string'],
            'conditions'             => [
              'type'       => 'object',
              'properties' => [
                'enabled' => ['type' => 'boolean', 'default' => true],
                'type'    => ['type' => 'string', 'enum' => ['and', 'or'], 'default' => 'and'],
                'rules'   => [
                  'type'  => 'array',
                  'items' => [
                    'type'       => 'object',
                    'properties' => [
                      'field'    => ['type' => 'string'],
                      'operator' => ['type' => 'string', 'enum' => WriteAbilities::CONDITION_OPERATORS],
                      'value'    => ['type' => 'string'],
                      'cast'     => ['type' => 'string', 'enum' => ['number', 'string', 'boolean', 'stringify']],
                    ],
                    'required'   => ['field', 'operator'],
                  ],
                ],
              ],
              'required'   => ['rules'],
            ],
            'conditions_evaluate_on' => ['type' => 'string', 'enum' => ['original', 'transformed'], 'default' => 'original'],
          ],
          'required'   => ['webhook_id', 'trigger', 'conditions'],
        ],
        'callback'     => [$writes, 'setConditions'],
      ],
      'assign_credential' => [
        'label'        => __('Assign a vault credential to a webhook', 'flowsystems-webhook-actions'),
        'description'  => __('Reference a stored vault credential from a webhook by id (the secret is injected at dispatch time and never exposed).', 'flowsystems-webhook-actions'),
        'category'     => 'webhook-actions',
        'scope'        => AuthHelper::SCOPE_FULL,
        'input_schema' => [
          'type'       => 'object',
          'properties' => [
            'webhook_id'    => ['type' => 'integer'],
            'credential_id' => ['type' => ['integer', 'null']],
          ],
          'required'   => ['webhook_id', 'credential_id'],
        ],
        'callback'     => [$writes, 'assignCredential'],
      ],
      'provision_wp_app_password' => [
        'label'            => __('Create a WordPress Application Password credential', 'flowsystems-webhook-actions'),
        'description'      => __('Mint a WordPress Application Password for the CURRENT signed-in admin and store it as a "basic" vault credential named "WP REST API (internal) — <user>", for authenticating this site\'s own REST API. Use it for INTERNAL automations (endpoint_url on this site\'s wp-json), then reference the new credential id from assign_credential as {{step_N.id}}. Requires confirmation; the secret is written straight to the vault and never exposed. The optional name only labels the Application Password in the user\'s profile.', 'flowsystems-webhook-actions'),
        'category'         => 'webhook-actions',
        'scope'            => AuthHelper::SCOPE_FULL,
        'requires_confirm' => 'always',
        'input_schema'     => [
          'type'       => 'object',
          'properties' => [
            'name' => ['type' => 'string'],
          ],
        ],
        'callback'     => [$writes, 'provisionWpAppPassword'],
      ],
      'create_chain' => [
        'label'        => __('Create a webhook chain', 'flowsystems-webhook-actions'),
        'description'  => __('Create a named chain that wires 2xx completions of one webhook to downstream webhooks.', 'flowsystems-webhook-actions'),
        'category'     => 'webhook-actions',
        'scope'        => AuthHelper::SCOPE_FULL,
        'input_schema' => [
          'type'       => 'object',
          'properties' => [
            'name'        => ['type' => 'string'],
            'description' => ['type' => 'string'],
          ],
          'required'   => ['name'],
        ],
        'callback'     => [$writes, 'createChain'],
      ],
      'create_chain_link' => [
        'label'        => __('Add a chain link', 'flowsystems-webhook-actions'),
        'description'  => __('Add a source→target edge to a chain. Rejected if it would create a cycle across any chain.', 'flowsystems-webhook-actions'),
        'category'     => 'webhook-actions',
        'scope'        => AuthHelper::SCOPE_FULL,
        'input_schema' => [
          'type'       => 'object',
          'properties' => [
            'chain_id'          => ['type' => 'integer'],
            'source_webhook_id' => ['type' => 'integer'],
            'target_webhook_id' => ['type' => 'integer'],
          ],
          'required'   => ['chain_id', 'source_webhook_id', 'target_webhook_id'],
        ],
        'callback'     => [$writes, 'createChainLink'],
      ],

      // ---- Test / validate ---------------------------------------------
      'probe_endpoint' => [
        'label'        => __('Probe a target endpoint', 'flowsystems-webhook-actions'),
        'description'  => __('Make a guarded test HTTP call to validate an endpoint before going live. To probe a webhook you created, pass webhook_id (e.g. {{step_2.id}}) — its URL, credential and method are reused automatically, so never ask the user for the endpoint URL again. Only pass url for an endpoint not tied to a webhook. A probe sends an EMPTY body, so GET, HEAD and POST run without confirmation; only PUT, PATCH and DELETE (which a body-less call can still mutate or delete) require confirmation. Returns status, redacted headers and a truncated, redacted body. The raw secret is never exposed.', 'flowsystems-webhook-actions'),
        'category'         => 'webhook-actions',
        'scope'            => AuthHelper::SCOPE_FULL,
        'requires_confirm' => 'when_destructive_method',
        'input_schema'     => [
          'type'       => 'object',
          'properties' => [
            'webhook_id'         => ['type' => 'integer', 'description' => 'Probe an existing webhook by id; reuses its endpoint URL, credential and method. Prefer this over url when probing a webhook you just created.'],
            'url'                => ['type' => 'string'],
            'method'             => ['type' => 'string', 'enum' => ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'], 'default' => 'GET'],
            'auth_credential_id' => ['type' => 'integer'],
            'headers'            => ['type' => 'object'],
            'body'               => ['type' => 'object'],
            'confirmed'          => ['type' => 'boolean', 'default' => false],
          ],
          'required'   => [],
        ],
        'callback'         => [$tests, 'probeEndpoint'],
      ],
      'test_dispatch' => [
        'label'            => __('Test-dispatch a webhook', 'flowsystems-webhook-actions'),
        'description'      => __('Send a synchronous test delivery for a webhook using a provided or captured payload, and return the HTTP result so the agent can verify the integration end to end. This makes a REAL delivery now, so for an internal REST endpoint it can actually create or modify data (e.g. create a WordPress user); it requires confirmation.', 'flowsystems-webhook-actions'),
        'category'         => 'webhook-actions',
        'scope'            => AuthHelper::SCOPE_FULL,
        'requires_confirm' => 'always',
        'confirm_notice'   => __('This sends a REAL delivery to the endpoint right now — it is not a dry run. If the webhook targets this site’s own REST API (e.g. wp-json/wp/v2/users) it can actually create or modify data, such as creating a WordPress user. Confirm to send it for real.', 'flowsystems-webhook-actions'),
        'input_schema'     => [
          'type'       => 'object',
          'properties' => [
            'webhook_id' => ['type' => 'integer'],
            'trigger'    => ['type' => 'string'],
            'payload'    => ['type' => 'object', 'description' => 'Optional custom payload, sent as-is. When omitted, the captured example is used with the stored field mapping applied — matching real deliveries.'],
          ],
          'required'   => ['webhook_id'],
        ],
        'callback'     => [$tests, 'testDispatch'],
      ],

      // ---- Go-live / destructive (confirm) ------------------------------
      'enable_webhook' => [
        'label'            => __('Enable / disable a webhook', 'flowsystems-webhook-actions'),
        'description'      => __('Enable (go live) or disable a webhook. Enabling requires confirmation.', 'flowsystems-webhook-actions'),
        'category'         => 'webhook-actions',
        'scope'            => AuthHelper::SCOPE_FULL,
        'requires_confirm' => 'always',
        'input_schema'     => [
          'type'       => 'object',
          'properties' => [
            'id'      => ['type' => 'integer'],
            'enabled' => ['type' => 'boolean', 'default' => true],
          ],
          'required'   => ['id'],
        ],
        'callback'         => [$writes, 'enableWebhook'],
      ],
      'delete_webhook' => [
        'label'            => __('Delete a webhook', 'flowsystems-webhook-actions'),
        'description'      => __('Permanently delete a webhook and its triggers / mapping. Requires confirmation.', 'flowsystems-webhook-actions'),
        'category'         => 'webhook-actions',
        'scope'            => AuthHelper::SCOPE_FULL,
        'requires_confirm' => 'always',
        'input_schema'     => [
          'type'       => 'object',
          'properties' => ['id' => ['type' => 'integer']],
          'required'   => ['id'],
        ],
        'callback'         => [$writes, 'deleteWebhook'],
      ],
    ];

    return $definitions;
  }
}
