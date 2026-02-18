<?php
/*
 * Plugin Name: Flow Systems Webhook Actions
 * Plugin URI: https://flowsystems.pl/wordpress-webhook-actions
 * Description: Trigger HTTP webhooks from WordPress actions (do_action). Easily connect WordPress with n8n, Zapier, Make, or custom workflows.
 * Version: 1.0.1
 * Author: Mateusz Skorupa
 * Author URI: https://flowsystems.pl
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: flowsystems-webhook-actions
 */

use FlowSystems\WebhookActions\App;
use FlowSystems\WebhookActions\Activation;

defined('ABSPATH') || exit;

define('FSWA_VERSION', '1.0.1');
define('FSWA_FILE', __FILE__);

require_once __DIR__ . '/vendor/autoload.php';

register_activation_hook(__FILE__, [Activation::class, 'activate']);
register_deactivation_hook(__FILE__, [Activation::class, 'deactivate']);

add_action('plugins_loaded', function () {
  if (!class_exists('FlowSystems\WebhookActions\App')) {
    return;
  }

  App::instance();
});
