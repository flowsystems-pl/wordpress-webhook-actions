<?php

namespace FlowSystems\WebhookActions\Api;

defined('ABSPATH') || exit;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Response;
use WP_Error;
use FlowSystems\WebhookActions\Api\AuthHelper;
use FlowSystems\WebhookActions\Services\HookDiscoveryService;

class TriggersController extends WP_REST_Controller {
  protected $namespace = 'fswa/v1';
  protected $rest_base = 'triggers';

  /**
   * Register routes
   */
  public function registerRoutes(): void {
    register_rest_route($this->namespace, '/' . $this->rest_base, [
      'methods' => WP_REST_Server::READABLE,
      'callback' => [$this, 'getItems'],
      'permission_callback' => [$this, 'getItemsPermissionsCheck'],
    ]);
  }

  public function getItemsPermissionsCheck($request): bool|WP_Error {
    return AuthHelper::dualAuth($request, AuthHelper::SCOPE_READ);
  }

  /**
   * Get available triggers
   */
  public function getItems($request): WP_REST_Response {
    $triggers = $this->getAvailableTriggers();

    return rest_ensure_response($triggers);
  }

  /**
   * Get list of available WordPress triggers.
   *
   * All filtering (excluded internal hooks, known-filter safety exclusion,
   * static+runtime merge) lives in HookDiscoveryService::discoverAllTriggerable()
   * — the same method the AI list_triggers ability consumes — so the manual
   * picker and the AI can never again disagree about which hooks exist. This
   * method only adds presentation: grouping into human-facing categories.
   *
   * @return array
   */
  private function getAvailableTriggers(): array {
    $categories = $this->getCategories();
    $grouped = [];

    foreach ((new HookDiscoveryService())->discoverAllTriggerable() as $hookName => $slug) {
      // A resolved slug (real or prefix-inferred plugin/theme) always wins
      // over the generic keyword guess; only fall back to keyword categories
      // ("users", "posts", ...) when nothing could be attributed at all.
      if ($slug !== null) {
        $category = str_replace('-', '_', $slug);
        if (!isset($categories[$category])) {
          $categories[$category] = ucwords(str_replace(['-', '_'], ' ', $slug));
        }
      } else {
        $category = $this->detectHookCategory($hookName);
      }

      $grouped[$category][$hookName] = true;
    }

    // Convert sets to sorted arrays
    foreach ($grouped as $category => &$names) {
      $names = array_keys($names);
      sort($names);
    }
    unset($names);

    // Sort categories: static order first, then dynamic alpha
    $staticOrder = array_keys($this->getCategories());
    uksort($grouped, function (string $a, string $b) use ($staticOrder): int {
      $aIdx = array_search($a, $staticOrder, true);
      $bIdx = array_search($b, $staticOrder, true);
      if ($aIdx === false && $bIdx === false) return strcmp($a, $b);
      if ($aIdx === false) return 1;
      if ($bIdx === false) return -1;
      return $aIdx - $bIdx;
    });

    /**
     * Filter the grouped hook list. Array of [ category => [hookName, ...] ].
     *
     * @param array $grouped
     */
    $grouped = apply_filters('fswa_available_triggers', $grouped);

    return [
      'grouped' => $grouped,
      'categories' => $categories,
      'allowCustom' => true,
    ];
  }

  /**
   * Detect category based on hook name
   *
   * @param string $hookName
   * @return string
   */
  private function detectHookCategory(string $hookName): string {
    $patterns = [
      'woocommerce' => '/^woocommerce/',
      'users' => '/user|login|logout|password|role|profile/',
      'posts' => '/post|publish/',
      'pages' => '/page/',
      'comments' => '/comment/',
      'taxonomy' => '/term|tax|category|tag/',
      'media' => '/attachment|media|upload|image/',
      'plugins' => '/plugin/',
      'options' => '/option/',
    ];

    foreach ($patterns as $category => $pattern) {
      if (preg_match($pattern, $hookName)) {
        return $category;
      }
    }

    return 'other';
  }

  /**
   * Get trigger categories
   *
   * @return array
   */
  private function getCategories(): array {
    return [
      'wordpress' => __('WordPress', 'flowsystems-webhook-actions'),
      'users' => __('Users', 'flowsystems-webhook-actions'),
      'posts' => __('Posts', 'flowsystems-webhook-actions'),
      'pages' => __('Pages', 'flowsystems-webhook-actions'),
      'comments' => __('Comments', 'flowsystems-webhook-actions'),
      'taxonomy' => __('Taxonomy', 'flowsystems-webhook-actions'),
      'media' => __('Media', 'flowsystems-webhook-actions'),
      'plugins' => __('Plugins', 'flowsystems-webhook-actions'),
      'options' => __('Options', 'flowsystems-webhook-actions'),
      'woocommerce' => __('WooCommerce', 'flowsystems-webhook-actions'),
      'other' => __('Other', 'flowsystems-webhook-actions'),
    ];
  }
}
