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

  public function registerRoutes(): void {
    register_rest_route($this->namespace, '/pro/status', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [$this, 'getStatus'],
        'permission_callback' => [$this, 'permissionsCheck'],
      ],
    ]);
  }

  public function permissionsCheck(WP_REST_Request $request): bool|WP_Error {
    return AuthHelper::dualAuth($request, AuthHelper::SCOPE_READ);
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
