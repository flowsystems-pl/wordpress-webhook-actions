<?php

namespace FlowSystems\WebhookActions\Abilities;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Api\AuthHelper;
use WP_Error;
use WP_REST_Request;

/**
 * Exposes the AbilityRegistry to the WordPress Abilities API (WP 6.9+/7.0).
 *
 * This is purely additive: the AgentOrchestrator always calls AbilityRegistry
 * directly, so the AI Builder works on any supported WordPress version. When the
 * Abilities API is present, we ALSO register each ability under the
 * `flowsystems-webhook-actions/*` namespace so external MCP clients (Claude Code,
 * Cursor) and other AI tooling can discover and invoke the exact same toolset via
 * the /wp-abilities/v1/ REST surface and the MCP Adapter.
 */
class AbilityRegistrar {
  /**
   * Option gating whether write-scoped abilities reach the Abilities/MCP surface.
   *
   * ON by default. Connecting an AI tool to a webhook builder is a request for it
   * to build webhooks — shipping this off would make the headline feature look
   * broken rather than safe. Reaching these abilities already costs an MCP server,
   * an admin OAuth consent and a `manage_options`-or-scoped-token check, so the
   * line worth drawing is not read-vs-write but routine-vs-destructive, which
   * `requires_confirm` draws on every path. This stays as a lock-down switch for
   * admins who want a connected agent held to read-only.
   */
  public const OPTION_EXPOSE_WRITES = 'fswa_mcp_expose_writes';

  private AbilityRegistry $registry;

  /**
   * The REST request currently being dispatched, captured for token auth.
   *
   * The Abilities API calls `permission_callback` with no request argument, but
   * our API tokens travel in headers — so we keep hold of the live request as it
   * passes through the REST server.
   */
  private static ?WP_REST_Request $request = null;

  public function __construct(?AbilityRegistry $registry = null) {
    $this->registry = $registry ?? new AbilityRegistry();
  }

  /**
   * Hook into the Abilities API init actions when available.
   *
   * Categories and abilities have SEPARATE init hooks — core rejects a category
   * registered from inside `wp_abilities_api_init`.
   */
  public function init(): void {
    if (!function_exists('wp_register_ability')) {
      return; // Abilities API not present — agent still works via direct execution.
    }

    add_action('wp_abilities_api_categories_init', [$this, 'registerCategory']);
    add_action('wp_abilities_api_init', [$this, 'register']);
    add_filter('rest_pre_dispatch', [$this, 'captureRequest'], 10, 3);
  }

  /**
   * Remember the in-flight REST request so permission checks can read its headers.
   *
   * Passes $result through untouched — this is a listener, not a short-circuit.
   *
   * @param mixed            $result  Pre-dispatch result (always returned as-is).
   * @param mixed            $server  REST server (unused).
   * @param WP_REST_Request  $request The request being dispatched.
   * @return mixed
   */
  public function captureRequest($result, $server, $request) {
    if ($request instanceof WP_REST_Request) {
      self::$request = $request;
    }
    return $result;
  }

  /**
   * Register the ability category.
   *
   * Must run on `wp_abilities_api_categories_init`; core emits a _doing_it_wrong
   * and drops the category anywhere else.
   */
  public function registerCategory(): void {
    if (function_exists('wp_register_ability_category')) {
      wp_register_ability_category('webhook-actions', [
        'label'       => __('Webhook Actions', 'flowsystems-webhook-actions'),
        // Required by core — a category without a description is silently dropped,
        // taking every ability assigned to it with it.
        'description' => __('Create and manage outgoing webhooks: triggers, field mappings, conditions, chains, credentials and delivery logs.', 'flowsystems-webhook-actions'),
      ]);
    }
  }

  /**
   * Register every ability definition.
   */
  public function register(): void {
    $exposeWrites = self::writesExposed();

    foreach ($this->registry->definitions() as $name => $def) {
      $abilityName = AbilityRegistry::NAMESPACE . '/' . self::publicSlug($name);
      $scope       = $def['scope'] ?? 'read';
      $isRead      = $scope === AuthHelper::SCOPE_READ;
      $confirm     = $def['requires_confirm'] ?? false;

      wp_register_ability($abilityName, [
        'label'             => $def['label'] ?? $name,
        'description'       => $def['description'] ?? '',
        'category'          => $def['category'] ?? 'webhook-actions',
        'input_schema'      => self::inputSchema($def['input_schema'] ?? ['type' => 'object']),
        'output_schema'     => ['type' => 'object'],
        'execute_callback'  => function (array $input) use ($name) {
          // The plan gate in PlanExecutor only guards the AI Builder. Anything
          // arriving here came straight off the Abilities/MCP surface with no
          // gate at all, so destructive abilities must carry an explicit
          // `confirmed` before they run.
          if ($this->registry->requiresConfirmation($name, $input) && empty($input['confirmed'])) {
            return new WP_Error(
              'fswa_confirmation_required',
              sprintf(
                /* translators: %s: ability name */
                __('"%s" changes or deletes live data. Re-send the same call with "confirmed": true once a human has approved it.', 'flowsystems-webhook-actions'),
                $name
              ),
              ['status' => 428]
            );
          }

          // Everything returned here leaves the site: to an MCP client, or through
          // a hosted bridge like WPVibe to someone else's server first. Delivery
          // logs carry the raw hook arguments — `user_pass` hashes on
          // profile_update, tokens in form posts — so results get the same scrub
          // the AI Builder applies before its own model sees a read result.
          $result = $this->registry->execute($name, $input);
          return is_array($result) ? $this->registry->redactResult($name, $result) : $result;
        },
        'permission_callback' => static function () use ($scope) {
          return self::permitted($scope);
        },
        'meta'              => [
          // Core ability annotations. These are honest metadata about each
          // ability, and core's /run controller uses `readonly` to decide that
          // a read is fetched with GET rather than POSTed.
          //
          // They do NOT currently reach MCP clients as per-tool hints: the MCP
          // Adapter's server publishes a fixed trio of meta-tools and routes
          // everything through execute-ability, so a client sees one tool, not
          // 26. That is exactly why the confirm gate above has to live here
          // rather than being delegated to the client's own approval UI.
          // `destructive` mirrors that gate: one rule, one place to change it.
          'annotations'      => [
            'readonly'   => $isRead,
            'destructive' => !$isRead && $confirm !== false,
            'idempotent' => $isRead,
          ],
          'requires_confirm' => $confirm,
          // Without this the ability registers but stays invisible: `public` seeds
          // `show_in_rest`, and both the /wp-abilities/v1/ list and run controllers
          // hard-filter on `show_in_rest` — so MCP clients never see it either.
          'public'           => $isRead || $exposeWrites,
          // `public` alone is NOT enough for MCP in the wild. WooCommerce and
          // IvyForms each bundle their own older `wordpress/mcp-adapter` in
          // vendor/ (0.1.0 and 0.5.0 as of 2026-08), and whichever copy the
          // autoloader resolves first serves the whole request. The older ones
          // only honour the nested flag, so on a WooCommerce site `meta.public`
          // is silently ignored and every ability reads as "mcp.public!=true".
          // Declaring both is what WooCommerce's own abilities do.
          'mcp'              => [
            'public' => $isRead || $exposeWrites,
            'type'   => 'tool',
          ],
        ],
      ]);
    }
  }

  /**
   * Give an input schema a default, so a zero-argument call validates.
   *
   * A client calling `list_webhooks` sends no arguments, and without a `default`
   * the absent input never normalises to an object — validation then rejects it
   * with "input is not of type object" and every argument-less ability is
   * unusable over MCP. Supplying the default also turns the error for a genuinely
   * incomplete call into "missing required property", which is the useful one.
   *
   * @param array<string, mixed> $schema
   * @return array<string, mixed>
   */
  private static function inputSchema(array $schema): array {
    if (!array_key_exists('default', $schema)) {
      // An empty ARRAY, not (object) [] — core hands the default straight to the
      // execute callback, which is typed `array $input`, so an object default
      // makes every zero-argument call die on a TypeError instead of running.
      $schema['default'] = [];
    }
    return $schema;
  }

  /**
   * Map an internal registry key to a valid Abilities API slug.
   *
   * Core validates names against `/^[a-z0-9-]+\/[a-z0-9-]+$/` — underscores are
   * rejected outright. The registry's own keys (and the AI Builder's tool names)
   * stay snake_case; only the public Abilities/MCP surface uses dashes.
   */
  private static function publicSlug(string $name): string {
    return str_replace('_', '-', $name);
  }

  /**
   * Whether write-scoped abilities are exposed on the Abilities/MCP surface.
   *
   * Defaults to true — see OPTION_EXPOSE_WRITES. Turning it off holds every
   * connected agent to reads, without disturbing the AI Builder, which never
   * goes through the Abilities API.
   */
  public static function writesExposed(): bool {
    $enabled = (bool) get_option(self::OPTION_EXPOSE_WRITES, true);

    /**
     * Filter whether write-scoped abilities reach the Abilities/MCP surface.
     *
     * @param bool $enabled Whether writes are exposed.
     */
    return (bool) apply_filters('fswa_mcp_expose_writes', $enabled);
  }

  /**
   * Capability gate for ability execution over the Abilities REST surface.
   *
   * An admin session always passes. Otherwise the request is checked for one of
   * the plugin's own API tokens, honouring its scope exactly as the REST
   * controllers do via AuthHelper — so a `read`-scoped token cannot reach a
   * write ability, and the `agent` token works over MCP as the readme promises.
   *
   * @param string $scope Required registry scope (read|full).
   */
  private static function permitted(string $scope): bool {
    $allowed = current_user_can('manage_options');

    if (!$allowed && self::$request instanceof WP_REST_Request) {
      $allowed = AuthHelper::requestHasScope(self::$request, $scope);
    }

    /**
     * Filter whether the current request may invoke a Webhook Actions ability of
     * the given scope through the Abilities API.
     *
     * @param bool   $allowed Whether access is granted.
     * @param string $scope   Required scope (read|full).
     */
    return (bool) apply_filters('fswa_ability_permitted', $allowed, $scope);
  }
}
