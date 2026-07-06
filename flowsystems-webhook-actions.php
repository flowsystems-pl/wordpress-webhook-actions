<?php
/*
 * Plugin Name: Webhook Actions - build automations and integrations with AI help
 * Plugin URI: https://wpwebhooks.org/wordpress-webhook-plugin
 * Description: Describe what you want in chat — the built-in AI agent plans, builds, and tests your WordPress webhooks, integrations, and automations.
 * Version: 2.0.0
 * Author: Mateusz Skorupa
 * Author URI: https://flowsystems.pl
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: flowsystems-webhook-actions
 * Domain Path: /languages
 */

use FlowSystems\WebhookActions\App;
use FlowSystems\WebhookActions\Activation;

defined('ABSPATH') || exit;

define('FSWA_VERSION', '2.0.0');
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
