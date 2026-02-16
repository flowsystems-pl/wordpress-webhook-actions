<?php

/**
 * Uninstall Webhook Actions
 *
 * @package FlowSystems\WebhookActions
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

require_once __DIR__ . '/vendor/autoload.php';

FlowSystems\WebhookActions\Activation::uninstall();
