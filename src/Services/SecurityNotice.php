<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

/**
 * The one-time admin notice for the 3.4.3 security release.
 *
 * Shown to administrators on a site that was updated from an earlier version,
 * until one of them dismisses it. It says what was exposed, what the update
 * did on this site (the counts are recorded by migration 2.6.0) and what is
 * left for the site owner to do. A new install never sees it.
 */
class SecurityNotice {
  public const OPTION = 'fswa_security_notice_343';

  private const DISMISS_ARG = 'fswa_dismiss_security_notice';

  /**
   * Record what the upgrade found, which also switches the notice on.
   *
   * @param array<string, mixed> $facts archive_files, trace_files, zips, leftover, triggers
   */
  public static function raise(array $facts): void {
    update_option(self::OPTION, $facts, false);
  }

  public function register(): void {
    add_action('admin_init', [$this, 'maybeDismiss']);
    add_action('admin_notices', [$this, 'render']);
  }

  public function maybeDismiss(): void {
    if (!isset($_GET[self::DISMISS_ARG]) || !current_user_can('manage_options')) {
      return;
    }

    check_admin_referer(self::DISMISS_ARG);
    delete_option(self::OPTION);

    wp_safe_redirect(remove_query_arg([self::DISMISS_ARG, '_wpnonce']));
    exit;
  }

  public function render(): void {
    if (!current_user_can('manage_options')) {
      return;
    }

    $facts = get_option(self::OPTION, null);
    if (!is_array($facts)) {
      return;
    }

    $archiveFiles = (int) ($facts['archive_files'] ?? 0);
    $traceFiles   = (int) ($facts['trace_files'] ?? 0);
    $zips         = (int) ($facts['zips'] ?? 0);
    $triggers     = array_filter((array) ($facts['triggers'] ?? []), 'is_string');

    $lines = [];

    $lines[] = __('Until this version, archived delivery logs and the AI Builder trace log were kept in fixed folders under wp-content/uploads, protected only by an .htaccess file. Web servers that do not read .htaccess (nginx, Caddy, IIS) served those files to anyone who knew or guessed the address. Download ZIPs of the archive were left in a folder that was reachable on Apache too.', 'flowsystems-webhook-actions');

    if ($archiveFiles + $traceFiles + $zips > 0) {
      $lines[] = sprintf(
        /* translators: 1: number of archive files, 2: number of AI trace files, 3: number of ZIP files. */
        __('On this site the update moved %1$d archive files and %2$d AI trace files to a folder with a random name, and deleted %3$d archive ZIPs. If this site runs on one of those servers, treat what was in those files (the data your webhooks sent) as possibly read by others.', 'flowsystems-webhook-actions'),
        $archiveFiles,
        $traceFiles,
        $zips
      );
    } else {
      $lines[] = __('On this site the update found no such files.', 'flowsystems-webhook-actions');
    }

    if (!empty($facts['leftover'])) {
      $lines[] = __('Some old files could not be moved. Delete the folders wp-content/uploads/fswa-logs and wp-content/uploads/fswa-ai-logs by hand.', 'flowsystems-webhook-actions');
    }

    $lines[] = __('Earlier versions also stored the password of a new account, and could send it to the endpoint, when a webhook was attached to a trigger that carries one, such as user_register or woocommerce_created_customer. Passwords are no longer stored or sent: the field stays in the payload with an empty (null) value. Passwords already stored in captured examples, delivery logs and archive files are being removed in the background.', 'flowsystems-webhook-actions');

    if ($triggers !== []) {
      $lines[] = sprintf(
        /* translators: %s: comma-separated list of trigger names. */
        __('Webhooks on this site use these triggers: %s. Ask the users who registered or changed a password while those webhooks were active to choose a new password.', 'flowsystems-webhook-actions'),
        implode(', ', $triggers)
      );
    }

    $dismissUrl = wp_nonce_url(add_query_arg(self::DISMISS_ARG, '1'), self::DISMISS_ARG);

    echo '<div class="notice notice-warning"><p><strong>'
      . esc_html__('Webhook Actions 3.4.3 is a security update. Please read this once.', 'flowsystems-webhook-actions')
      . '</strong></p>';

    foreach ($lines as $line) {
      echo '<p>' . esc_html($line) . '</p>';
    }

    printf(
      '<p><a href="%1$s" target="_blank" rel="noopener">%2$s</a> &nbsp;|&nbsp; <a href="%3$s">%4$s</a></p></div>',
      esc_url('https://wpwebhooks.org/changelog/'),
      esc_html__('Full details in the changelog', 'flowsystems-webhook-actions'),
      esc_url($dismissUrl),
      esc_html__('I have read this, dismiss', 'flowsystems-webhook-actions')
    );
  }
}
