<?php

namespace FlowSystems\WebhookActions\Controllers;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\App;
use FlowSystems\WebhookActions\Api\WebhooksController;
use FlowSystems\WebhookActions\Api\LogsController;
use FlowSystems\WebhookActions\Api\TriggersController;
use FlowSystems\WebhookActions\Api\SettingsController;
use FlowSystems\WebhookActions\Api\QueueController;
use FlowSystems\WebhookActions\Api\HealthController;
use FlowSystems\WebhookActions\Api\SchemasController;
use FlowSystems\WebhookActions\Api\ApiTokensController;
use FlowSystems\WebhookActions\Api\ProStatusController;
use FlowSystems\WebhookActions\Api\ChainsController;
use FlowSystems\WebhookActions\Api\ActivityLogController;
use FlowSystems\WebhookActions\Api\CredentialsController;
use FlowSystems\WebhookActions\Api\AgentController;

class AdminController {
  public function __construct() {
    add_action('admin_menu', [$this, 'addMenuPage']);
    add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    add_action('rest_api_init', [$this, 'registerRestRoutes']);
    add_action('admin_notices', [$this, 'showMigrationNotice']);
  }

  /**
   * Add admin menu page
   */
  public function addMenuPage(): void {
    if (get_option('fswa_menu_under_tools', false)) {
      add_submenu_page(
        'tools.php',
        __('Webhook Actions', 'flowsystems-webhook-actions'),
        __('Webhook Actions', 'flowsystems-webhook-actions'),
        'manage_options',
        'fswa-webhook-actions',
        [$this, 'renderPage']
      );
    } else {
      add_menu_page(
        __('Webhook Actions', 'flowsystems-webhook-actions'),
        __('Webhook Actions', 'flowsystems-webhook-actions'),
        'manage_options',
        'fswa-webhook-actions',
        [$this, 'renderPage'],
        'dashicons-rest-api',
        80
      );
    }
  }


  /**
   * Render the admin page (Vue SPA mount point)
   */
  public function renderPage(): void {
    echo '<div id="fswa-app"></div>';
  }

  /**
   * Enqueue admin assets
   *
   * @param string $hook
   */
  public function enqueueAssets(string $hook): void {
    if (strpos($hook, 'fswa-webhook-actions') === false) {
      return;
    }

    $distPath = App::$path . '/admin/dist';
    $distUrl = App::$url . '/admin/dist';

    $manifestPath = $distPath . '/.vite/manifest.json';

    if (file_exists($manifestPath)) {
      $manifest = json_decode(file_get_contents($manifestPath), true);

      $mainEntry = $manifest['src/main.js'] ?? $manifest['index.html'] ?? null;

      if ($mainEntry) {
        if (!empty($mainEntry['css'])) {
          foreach ($mainEntry['css'] as $index => $cssFile) {
            wp_enqueue_style(
              'fswa-admin-' . $index,
              $distUrl . '/' . $cssFile,
              [],
              App::VERSION
            );
          }
        } elseif (!empty($manifest['style.css']['file'])) {
          wp_enqueue_style(
            'fswa-admin',
            $distUrl . '/' . $manifest['style.css']['file'],
            [],
            App::VERSION
          );
        }

        wp_enqueue_script(
          'fswa-admin',
          $distUrl . '/' . $mainEntry['file'],
          ['wp-i18n'],
          App::VERSION,
          true
        );

        add_filter('script_loader_tag', function ($tag, $handle) {
          if ($handle === 'fswa-admin') {
            return str_replace(' src', ' type="module" src', $tag);
          }
          return $tag;
        }, 10, 2);
      }
    } else {
      $devUrl = 'http://localhost:5173';

      wp_enqueue_script(
        'fswa-vite-client',
        $devUrl . '/@vite/client',
        [],
        App::VERSION,
        false
      );

      wp_enqueue_script(
        'fswa-admin',
        $devUrl . '/src/main.js',
        ['fswa-vite-client', 'wp-i18n'],
        App::VERSION,
        true
      );

      add_filter('script_loader_tag', function ($tag, $handle) {
        if (in_array($handle, ['fswa-vite-client', 'fswa-admin'])) {
          return str_replace(' src', ' type="module" src', $tag);
        }
        return $tag;
      }, 10, 2);
    }

    wp_localize_script('fswa-admin', 'fswaSettings', [
      'restUrl' => rest_url('fswa/v1/'),
      'nonce' => wp_create_nonce('wp_rest'),
      'adminUrl' => admin_url(),
      'pluginUrl' => App::$url,
    ]);

    // Load JS translations. wp_set_script_translations() resolves a filename
    // built from md5() of the script's URL path, which is environment-dependent
    // and changes per build. We ship a single stable, handle-based JSON
    // (…-{locale}-fswa-admin.json) and redirect the loader to it below.
    wp_set_script_translations('fswa-admin', 'flowsystems-webhook-actions', App::$path . '/languages');

    add_filter('load_script_translation_file', function ($file, $handle, $domain) {
      if ($handle === 'fswa-admin' && $domain === 'flowsystems-webhook-actions') {
        $candidate = App::$path . '/languages/flowsystems-webhook-actions-'
          . determine_locale() . '-fswa-admin.json';
        if (is_readable($candidate)) {
          return $candidate;
        }
      }
      return $file;
    }, 10, 3);

    wp_add_inline_style('wp-admin', '
            #fswa-app {
                min-height: 500px;
                background: #fff;
                margin: 20px 20px 20px 0;
                padding: 20px;
                border: 1px solid #c3c4c7;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
            }
            #fswa-app:empty::before {
                content: "Loading...";
                display: block;
                text-align: center;
                padding: 40px;
                color: #666;
            }
        ');
  }

  /**
   * Register REST API routes
   */
  public function registerRestRoutes(): void {
    (new WebhooksController())->registerRoutes();
    (new LogsController())->registerRoutes();
    (new TriggersController())->registerRoutes();
    (new SettingsController())->registerRoutes();
    (new QueueController())->registerRoutes();
    (new HealthController())->registerRoutes();
    (new SchemasController())->registerRoutes();
    (new ApiTokensController())->registerRoutes();
    (new ProStatusController())->registerRoutes();
    (new ChainsController())->registerRoutes();
    (new ActivityLogController())->registerRoutes();
    (new CredentialsController())->registerRoutes();
    (new AgentController())->registerRoutes();
  }

  /**
   * Show migration notice
   */
  public function showMigrationNotice(): void {
    if (get_transient('fswa_migration_notice')) {
      delete_transient('fswa_migration_notice');
?>
      <div class="notice notice-success is-dismissible">
        <p>
          <?php esc_html_e('Webhook Actions by Flow Systems: Your webhooks have been migrated to the new database format.', 'flowsystems-webhook-actions'); ?>
        </p>
      </div>
<?php
    }
  }
}
