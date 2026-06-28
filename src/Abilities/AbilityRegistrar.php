<?php

namespace FlowSystems\WebhookActions\Abilities;

defined('ABSPATH') || exit;

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
  private AbilityRegistry $registry;

  public function __construct(?AbilityRegistry $registry = null) {
    $this->registry = $registry ?? new AbilityRegistry();
  }

  /**
   * Hook into the Abilities API init action when available.
   */
  public function init(): void {
    if (!function_exists('wp_register_ability')) {
      return; // Abilities API not present — agent still works via direct execution.
    }

    add_action('wp_abilities_api_init', [$this, 'register']);
  }

  /**
   * Register the category and every ability definition.
   */
  public function register(): void {
    if (function_exists('wp_register_ability_category')) {
      wp_register_ability_category('webhook-actions', [
        'label' => __('Webhook Actions', 'flowsystems-webhook-actions'),
      ]);
    }

    foreach ($this->registry->definitions() as $name => $def) {
      $abilityName = AbilityRegistry::NAMESPACE . '/' . $name;
      $scope       = $def['scope'] ?? 'read';

      wp_register_ability($abilityName, [
        'label'             => $def['label'] ?? $name,
        'description'       => $def['description'] ?? '',
        'category'          => $def['category'] ?? 'webhook-actions',
        'input_schema'      => $def['input_schema'] ?? ['type' => 'object'],
        'output_schema'     => ['type' => 'object'],
        'execute_callback'  => static function (array $input) use ($name) {
          $result = (new AbilityRegistry())->execute($name, $input);
          // wp_register_ability expects the value or a WP_Error — pass either through.
          return $result;
        },
        'permission_callback' => static function () use ($scope) {
          return self::permitted($scope);
        },
        'meta'              => [
          'requires_confirm' => $def['requires_confirm'] ?? false,
        ],
      ]);
    }
  }

  /**
   * Capability gate for ability execution over the Abilities REST surface.
   *
   * Read abilities require a logged-in admin; write abilities require the same.
   * Token-scoped (agent) access for external MCP is mediated by the MCP Adapter /
   * token layer and can be opened up via the filter below without touching code.
   *
   * @param string $scope Required registry scope (read|full).
   */
  private static function permitted(string $scope): bool {
    $allowed = current_user_can('manage_options');

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
