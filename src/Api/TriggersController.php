<?php

namespace FlowSystems\WebhookActions\Api;

defined('ABSPATH') || exit;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Response;

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

  /**
   * Check permissions
   */
  public function getItemsPermissionsCheck($request): bool {
    return current_user_can('manage_options');
  }

  /**
   * Get available triggers
   */
  public function getItems($request): WP_REST_Response {
    $triggers = $this->getAvailableTriggers();

    return rest_ensure_response($triggers);
  }

  /**
   * Get list of available WordPress triggers
   *
   * @return array
   */
  private function getAvailableTriggers(): array {
    $suggested = $this->getSuggestedTriggers();

    $registered = $this->getRegisteredHooks();

    $suggestedNames = array_column($suggested, 'name');
    $triggers = $suggested;

    foreach ($registered as $hook) {
      if (!in_array($hook['name'], $suggestedNames)) {
        $triggers[] = $hook;
      }
    }

    /**
     * Filter the list of available webhook triggers.
     *
     * @param array $triggers Array of trigger definitions with name, label, category, and description
     */
    $triggers = apply_filters('fswa_available_triggers', $triggers);

    $grouped = [];
    foreach ($triggers as $trigger) {
      $category = $trigger['category'] ?? 'other';
      if (!isset($grouped[$category])) {
        $grouped[$category] = [];
      }
      $grouped[$category][] = $trigger;
    }

    return [
      'triggers' => $triggers,
      'grouped' => $grouped,
      'categories' => $this->getCategories(),
      'allowCustom' => true,
    ];
  }

  /**
   * Get suggested/common triggers with descriptions
   *
   * @return array
   */
  private function getSuggestedTriggers(): array {
    return [
      // User triggers
      [
        'name' => 'user_register',
        'label' => __('User registered', 'flowsystems-webhook-actions'),
        'category' => 'users',
        'description' => __('Fires after a new user is registered.', 'flowsystems-webhook-actions'),
      ],
      [
        'name' => 'profile_update',
        'label' => __('Profile updated', 'flowsystems-webhook-actions'),
        'category' => 'users',
        'description' => __('Fires after a user profile is updated.', 'flowsystems-webhook-actions'),
      ],
      [
        'name' => 'delete_user',
        'label' => __('User deleted', 'flowsystems-webhook-actions'),
        'category' => 'users',
        'description' => __('Fires before a user is deleted.', 'flowsystems-webhook-actions'),
      ],
      [
        'name' => 'wp_login',
        'label' => __('User login', 'flowsystems-webhook-actions'),
        'category' => 'users',
        'description' => __('Fires after a user logs in.', 'flowsystems-webhook-actions'),
      ],
      [
        'name' => 'wp_logout',
        'label' => __('User logout', 'flowsystems-webhook-actions'),
        'category' => 'users',
        'description' => __('Fires after a user logs out.', 'flowsystems-webhook-actions'),
      ],
      [
        'name' => 'password_reset',
        'label' => __('Password reset', 'flowsystems-webhook-actions'),
        'category' => 'users',
        'description' => __('Fires after a password reset.', 'flowsystems-webhook-actions'),
      ],
      [
        'name' => 'set_user_role',
        'label' => __('User role changed', 'flowsystems-webhook-actions'),
        'category' => 'users',
        'description' => __('Fires after a user role is changed.', 'flowsystems-webhook-actions'),
      ],

      // Post triggers
      [
        'name' => 'publish_post',
        'label' => __('Post published', 'flowsystems-webhook-actions'),
        'category' => 'posts',
        'description' => __('Fires when a post is published.', 'flowsystems-webhook-actions'),
      ],
      [
        'name' => 'save_post',
        'label' => __('Post saved', 'flowsystems-webhook-actions'),
        'category' => 'posts',
        'description' => __('Fires when a post is saved.', 'flowsystems-webhook-actions'),
      ],
      [
        'name' => 'edit_post',
        'label' => __('Post edited', 'flowsystems-webhook-actions'),
        'category' => 'posts',
        'description' => __('Fires after a post is edited.', 'flowsystems-webhook-actions'),
      ],
      [
        'name' => 'delete_post',
        'label' => __('Post deleted', 'flowsystems-webhook-actions'),
        'category' => 'posts',
        'description' => __('Fires before a post is deleted.', 'flowsystems-webhook-actions'),
      ],
      [
        'name' => 'trashed_post',
        'label' => __('Post trashed', 'flowsystems-webhook-actions'),
        'category' => 'posts',
        'description' => __('Fires when a post is moved to trash.', 'flowsystems-webhook-actions'),
      ],

      // Page triggers
      [
        'name' => 'publish_page',
        'label' => __('Page published', 'flowsystems-webhook-actions'),
        'category' => 'pages',
        'description' => __('Fires when a page is published.', 'flowsystems-webhook-actions'),
      ],

      // Comment triggers
      [
        'name' => 'comment_post',
        'label' => __('Comment posted', 'flowsystems-webhook-actions'),
        'category' => 'comments',
        'description' => __('Fires after a comment is posted.', 'flowsystems-webhook-actions'),
      ],
      [
        'name' => 'edit_comment',
        'label' => __('Comment edited', 'flowsystems-webhook-actions'),
        'category' => 'comments',
        'description' => __('Fires after a comment is edited.', 'flowsystems-webhook-actions'),
      ],
      [
        'name' => 'delete_comment',
        'label' => __('Comment deleted', 'flowsystems-webhook-actions'),
        'category' => 'comments',
        'description' => __('Fires before a comment is deleted.', 'flowsystems-webhook-actions'),
      ],
      [
        'name' => 'wp_set_comment_status',
        'label' => __('Comment status changed', 'flowsystems-webhook-actions'),
        'category' => 'comments',
        'description' => __('Fires when comment status is changed.', 'flowsystems-webhook-actions'),
      ],
      [
        'name' => 'spammed_comment',
        'label' => __('Comment marked as spam', 'flowsystems-webhook-actions'),
        'category' => 'comments',
        'description' => __('Fires when a comment is marked as spam.', 'flowsystems-webhook-actions'),
      ],

      // Taxonomy triggers
      [
        'name' => 'created_term',
        'label' => __('Term created', 'flowsystems-webhook-actions'),
        'category' => 'taxonomy',
        'description' => __('Fires after a term is created.', 'flowsystems-webhook-actions'),
      ],
      [
        'name' => 'edited_term',
        'label' => __('Term edited', 'flowsystems-webhook-actions'),
        'category' => 'taxonomy',
        'description' => __('Fires after a term is edited.', 'flowsystems-webhook-actions'),
      ],
      [
        'name' => 'delete_term',
        'label' => __('Term deleted', 'flowsystems-webhook-actions'),
        'category' => 'taxonomy',
        'description' => __('Fires before a term is deleted.', 'flowsystems-webhook-actions'),
      ],

      // Media triggers
      [
        'name' => 'add_attachment',
        'label' => __('Media uploaded', 'flowsystems-webhook-actions'),
        'category' => 'media',
        'description' => __('Fires after an attachment is added.', 'flowsystems-webhook-actions'),
      ],
      [
        'name' => 'edit_attachment',
        'label' => __('Media edited', 'flowsystems-webhook-actions'),
        'category' => 'media',
        'description' => __('Fires when an attachment is edited.', 'flowsystems-webhook-actions'),
      ],
      [
        'name' => 'delete_attachment',
        'label' => __('Media deleted', 'flowsystems-webhook-actions'),
        'category' => 'media',
        'description' => __('Fires before an attachment is deleted.', 'flowsystems-webhook-actions'),
      ],

      // Plugin triggers
      [
        'name' => 'activated_plugin',
        'label' => __('Plugin activated', 'flowsystems-webhook-actions'),
        'category' => 'plugins',
        'description' => __('Fires after a plugin is activated.', 'flowsystems-webhook-actions'),
      ],
      [
        'name' => 'deactivated_plugin',
        'label' => __('Plugin deactivated', 'flowsystems-webhook-actions'),
        'category' => 'plugins',
        'description' => __('Fires after a plugin is deactivated.', 'flowsystems-webhook-actions'),
      ],

      // Options triggers
      [
        'name' => 'updated_option',
        'label' => __('Option updated', 'flowsystems-webhook-actions'),
        'category' => 'options',
        'description' => __('Fires after an option is updated.', 'flowsystems-webhook-actions'),
      ],
    ];
  }

  /**
   * Get registered hooks from WordPress
   *
   * @return array
   */
  private function getRegisteredHooks(): array {
    global $wp_filter;

    $hooks = [];
    $excluded = $this->getExcludedHookPatterns();
    $suggestedNames = array_column($this->getSuggestedTriggers(), 'name');

    foreach (array_keys($wp_filter) as $hookName) {
      // Skip if already in suggested
      if (in_array($hookName, $suggestedNames)) {
        continue;
      }

      // Skip excluded patterns
      if ($this->isExcludedHook($hookName, $excluded)) {
        continue;
      }

      $hooks[] = [
        'name' => $hookName,
        'label' => $this->formatHookLabel($hookName),
        'category' => $this->detectHookCategory($hookName),
        'description' => '',
        'isRegistered' => true,
      ];
    }

    // Sort alphabetically
    usort($hooks, fn($a, $b) => strcmp($a['name'], $b['name']));

    return $hooks;
  }

  /**
   * Get patterns for hooks to exclude
   *
   * @return array
   */
  private function getExcludedHookPatterns(): array {
    return [
      // Internal WordPress hooks
      '/^_/',
      '/^admin_/',
      '/^wp_ajax/',
      '/^rest_api/',
      '/^oembed/',
      '/^customize_/',
      '/^wp_head$/',
      '/^wp_footer$/',
      '/^wp_enqueue/',
      '/^admin_enqueue/',
      '/^login_/',
      '/^register_/',
      '/^widgets_/',
      '/^sidebar/',
      '/^dynamic_sidebar/',
      '/^get_header/',
      '/^get_footer/',
      '/^get_sidebar/',
      '/^template_/',
      '/^the_content$/',
      '/^the_title$/',
      '/^the_excerpt$/',
      '/^body_class$/',
      '/^post_class$/',
      '/^comment_class$/',
      '/^nav_menu/',
      '/^wp_nav_menu/',
      '/^pre_get/',
      '/^posts_/',
      '/^query$/',
      '/^parse_/',
      '/^sanitize_/',
      '/^clean_/',
      '/^check_/',
      '/^is_/',
      '/^load-/',
      '/^print_/',
      '/^show_/',
      '/^display_/',
      '/^render_/',
      '/^do_/',
      '/^doing_/',
      '/^current_/',
      '/^get_/',
      '/^set_/',
      '/^update_/',
      '/^add_/',
      '/^remove_/',
      '/^has_/',
      '/^can_/',
      '/^wp_/',
      '/^woocommerce_before/',
      '/^woocommerce_after/',
      '/^woocommerce_checkout_/',
      '/^woocommerce_cart_/',
      // Filter hooks (usually not useful as triggers)
      '/_filter$/',
      '/_filters$/',
    ];
  }

  /**
   * Check if hook matches excluded patterns
   *
   * @param string $hookName
   * @param array $patterns
   * @return bool
   */
  private function isExcludedHook(string $hookName, array $patterns): bool {
    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $hookName)) {
        return true;
      }
    }
    return false;
  }

  /**
   * Format hook name as human-readable label
   *
   * @param string $hookName
   * @return string
   */
  private function formatHookLabel(string $hookName): string {
    $label = str_replace(['_', '-'], ' ', $hookName);
    return ucfirst($label);
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
