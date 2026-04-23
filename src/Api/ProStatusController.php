<?php

namespace FlowSystems\WebhookActions\Api;

defined('ABSPATH') || exit;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class ProStatusController extends WP_REST_Controller {
  protected $namespace = 'fswa/v1';

  private const PRO_PLUGIN_FILE = 'flowsystems-webhook-actions-pro/flowsystems-webhook-actions-pro.php';

  public function registerRoutes(): void {
    register_rest_route($this->namespace, '/pro/status', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [$this, 'getStatus'],
        'permission_callback' => [$this, 'permissionsCheck'],
      ],
    ]);

    register_rest_route($this->namespace, '/pro/activate-plugin', [
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [$this, 'activatePlugin'],
        'permission_callback' => [$this, 'fullPermissionsCheck'],
      ],
    ]);
  }

  public function fullPermissionsCheck(WP_REST_Request $request): bool|WP_Error {
    return AuthHelper::dualAuth($request, AuthHelper::SCOPE_FULL);
  }

  public function permissionsCheck(WP_REST_Request $request): bool|WP_Error {
    return AuthHelper::dualAuth($request, AuthHelper::SCOPE_READ);
  }

  public function activatePlugin(WP_REST_Request $request): WP_REST_Response|WP_Error {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';

    if (!file_exists(WP_PLUGIN_DIR . '/' . self::PRO_PLUGIN_FILE)) {
      return new WP_Error('plugin_not_found', __('Pro plugin not found.', 'flowsystems-webhook-actions'), ['status' => 404]);
    }

    if (is_plugin_active(self::PRO_PLUGIN_FILE)) {
      return new WP_REST_Response(['activated' => true]);
    }

    $result = activate_plugin(self::PRO_PLUGIN_FILE);

    if (is_wp_error($result)) {
      return new WP_Error('activation_failed', $result->get_error_message(), ['status' => 500]);
    }

    return new WP_REST_Response(['activated' => true]);
  }

  public function getStatus(WP_REST_Request $request): WP_REST_Response {
    $proFile = WP_PLUGIN_DIR . '/flowsystems-webhook-actions-pro/flowsystems-webhook-actions-pro.php';

    if (!file_exists($proFile)) {
      return new WP_REST_Response(['state' => 'upsell', 'license' => null]);
    }

    if (!class_exists('FlowSystems\WebhookActions\Pro\License\LicenseManager')) {
      return new WP_REST_Response(['state' => 'inactive', 'license' => null]);
    }

    $manager = new \FlowSystems\WebhookActions\Pro\License\LicenseManager();
    $active  = $manager->isActive();
    $data    = $manager->getData();

    return new WP_REST_Response([
      'state'   => $active ? 'active' : 'activate',
      'license' => $data ?: null,
    ]);
  }
}
