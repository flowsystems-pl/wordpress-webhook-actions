<?php

namespace FlowSystems\WebhookActions\Integrations;

defined('ABSPATH') || exit;

/**
 * Loads third-party integrations when their plugin is active.
 * Add new integrations here — App.php stays clean.
 */
class IntegrationLoader {

  public function load(): void {
    if (class_exists('WPCF7_ContactForm')) {
      (new CF7Integration())->register();
    }

    if (class_exists('IvyForms\Entity\Field\Field')) {
      (new IvyFormsIntegration())->register();
    }
  }
}
