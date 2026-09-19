<?php

namespace FlowSystems\WebhookActions\Services\Export;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Repositories\WebhookRepository;
use FlowSystems\WebhookActions\Repositories\SchemaRepository;
use FlowSystems\WebhookActions\Repositories\CredentialRepository;
use FlowSystems\WebhookActions\Repositories\ChainRepository;
use FlowSystems\WebhookActions\Repositories\ChainLinkRepository;

/**
 * Assembles a portable "build" JSON document from webhooks and/or chains:
 * webhook rows + their triggers + per-trigger captured schema (example payload,
 * field mapping, conditions) + chain edges (by webhook UUID) + referenced vault
 * credential *metadata* (NEVER the secret — the vault is write-only by design).
 *
 * The document is the keystone reused by import, the website builds collection,
 * and Pro publishing. Pro attaches Code Glue per trigger via the
 * `fswa_export_trigger` filter.
 */
class BuildExporter {
  private WebhookRepository $webhooks;
  private SchemaRepository $schemas;
  private CredentialRepository $credentials;
  private ChainRepository $chains;
  private ChainLinkRepository $links;

  public const SCHEMA_VERSION = 1;

  public function __construct() {
    $this->webhooks    = new WebhookRepository();
    $this->schemas     = new SchemaRepository();
    $this->credentials = new CredentialRepository();
    $this->chains      = new ChainRepository();
    $this->links       = new ChainLinkRepository();
  }

  /**
   * Build the export document.
   *
   * @param int[]                $webhookIds Explicit webhook IDs to export.
   * @param int[]                $chainIds   Chain IDs to export (member webhooks auto-included).
   * @param bool                 $all        Export every webhook and chain on the site.
   * @param array<string, mixed> $options    Caller intent passed to extensions (e.g.
   *                                         `include_ai_transcript`, `conversation_id`).
   * @return array The portable document.
   */
  public function export(array $webhookIds = [], array $chainIds = [], bool $all = false, array $options = []): array {
    $webhookIds = array_map('intval', $webhookIds);
    $chainIds   = array_map('intval', $chainIds);

    if ($all) {
      $webhookIds = array_map(static fn(array $w): int => (int) $w['id'], $this->webhooks->getAll());
      $chainIds   = array_map(static fn(array $c): int => (int) $c['id'], $this->chains->getAll());
    }

    // A chain is only portable if its member webhooks travel with it.
    $membersByChain = $this->chains->getMembersByChain();
    foreach ($chainIds as $chainId) {
      foreach ($membersByChain[$chainId] ?? [] as $memberId) {
        $webhookIds[] = (int) $memberId;
      }
    }
    $webhookIds = array_values(array_unique(array_filter($webhookIds)));

    // Captured example payloads hold whatever a real visitor submitted, so they
    // are redacted unless the caller explicitly asks for their own raw values
    // back (a private, same-site backup). Publishing never asks.
    $redactExamples = empty($options['keep_captured_values']);

    $credentials = [];
    $webhookDocs = [];
    foreach ($webhookIds as $webhookId) {
      $doc = $this->exportWebhook($webhookId, $credentials, $redactExamples);
      if ($doc !== null) {
        $webhookDocs[] = $doc;
      }
    }

    $chainDocs = [];
    foreach (array_values(array_unique(array_filter($chainIds))) as $chainId) {
      $doc = $this->exportChain($chainId);
      if ($doc !== null) {
        $chainDocs[] = $doc;
      }
    }

    $doc = [
      'fswa_export' => [
        'version'        => self::SCHEMA_VERSION,
        'kind'           => $this->resolveKind($webhookDocs, $chainDocs),
        'exported_at'    => gmdate('c'),
        'source_site'    => home_url(),
        'plugin_version' => defined('FSWA_VERSION') ? FSWA_VERSION : null,
      ],
      'credentials' => array_values($credentials),
      'webhooks'    => $webhookDocs,
      'chains'      => $chainDocs,
    ];

    /**
     * Let extensions attach top-level blocks to the export document — e.g. Pro
     * bolting on an `ai_build` block (Build-with-AI transcript + model) when the
     * exported objects trace back to an AI Builder conversation. Mirrors the
     * per-trigger {@see 'fswa_export_trigger'} filter but for the whole document.
     *
     * @param array $doc        The full export document.
     * @param int[] $webhookIds The webhook IDs included in this export.
     * @param int[] $chainIds   The chain IDs included in this export.
     * @param array $options    Caller intent (e.g. `include_ai_transcript`,
     *                          `conversation_id`). Added in 2.5.0 — existing
     *                          three-argument callbacks keep working.
     */
    $doc = apply_filters('fswa_export_doc', $doc, $webhookIds, $chainIds, $options);

    // Last step, deliberately: a build meant for public sharing must have this
    // site's address stripped out of extension blocks too (a Build-with-AI
    // transcript quotes endpoint URLs back at the user).
    if (!empty($options['anonymize_site_url'])) {
      $doc = (new BuildAnonymizer())->anonymize($doc);
    }

    return $doc;
  }

  /**
   * @param array<string, array> $credentials Accumulator keyed by ref, mutated in place.
   */
  private function exportWebhook(int $webhookId, array &$credentials, bool $redactExamples = true): ?array {
    $webhook = $this->webhooks->find($webhookId);
    if ($webhook === null) {
      return null;
    }

    $auth = $this->exportAuth($webhook, $credentials);

    $triggerDocs = [];
    foreach ((array) ($webhook['triggers'] ?? []) as $triggerName) {
      // Synthetic chain-link triggers are derived from chain edges, not portable.
      if (strncmp((string) $triggerName, 'fswa_chain_link:', 16) === 0) {
        continue;
      }
      $triggerDocs[] = $this->exportTrigger($webhookId, (string) $triggerName, $redactExamples);
    }

    return [
      'uuid'           => $webhook['webhook_uuid'] ?? null,
      'name'           => $webhook['name'] ?? '',
      'description'    => $webhook['description'] ?? null,
      'endpoint_url'   => $webhook['endpoint_url'] ?? '',
      'http_method'    => $webhook['http_method'] ?? 'POST',
      'custom_headers' => $webhook['custom_headers'] ?? [],
      'url_params'     => $webhook['url_params'] ?? [],
      'is_enabled'     => (bool) ($webhook['is_enabled'] ?? true),
      'is_synchronous' => (bool) ($webhook['is_synchronous'] ?? false),
      'auth'           => $auth,
      'triggers'       => $triggerDocs,
      'notification_rules' => $this->exportNotificationRules($webhookId),
    ];
  }

  /**
   * The webhook's own notification rules, with channels named rather than
   * referenced — a channel holds secrets and belongs to one site, so an import
   * re-attaches rules to whatever channel carries the same name, or leaves
   * them disabled for the user to pick one.
   *
   * @return array<int, array<string, mixed>>
   */
  private function exportNotificationRules(int $webhookId): array {
    $channels = [];
    foreach ((new \FlowSystems\WebhookActions\Repositories\NotificationChannelRepository())->getAll() as $channel) {
      $channels[(int) $channel['id']] = (string) $channel['name'];
    }

    $out = [];
    foreach ((new \FlowSystems\WebhookActions\Repositories\NotificationRuleRepository())->getByWebhook($webhookId) as $rule) {
      $names = [];
      foreach ($rule['channel_ids'] as $id) {
        if (isset($channels[$id])) {
          $names[] = $channels[$id];
        }
      }
      $out[] = [
        'name'             => $rule['name'],
        'event'            => $rule['event'],
        'is_enabled'       => (bool) $rule['is_enabled'],
        'filters'          => $rule['filters'] ?: (object) [],
        'template'         => $rule['template'],
        'throttle_seconds' => $rule['throttle_seconds'],
        'digest'           => $rule['digest'],
        'channel_names'    => $names,
      ];
    }

    return $out;
  }

  /**
   * Resolve a webhook's auth into a portable, secret-free descriptor and register
   * any referenced credential's metadata into the shared credentials list.
   *
   * @param array<string, array> $credentials Accumulator keyed by ref.
   */
  private function exportAuth(array $webhook, array &$credentials): array {
    $credentialId = (int) ($webhook['auth_credential_id'] ?? 0);
    if ($credentialId > 0) {
      $ref = 'c' . $credentialId;
      if (!isset($credentials[$ref])) {
        $cred = $this->credentials->find($credentialId);
        if ($cred !== null) {
          $credentials[$ref] = [
            'ref'         => $ref,
            'name'        => $cred['name'] ?? '',
            'type'        => $cred['type'] ?? 'bearer',
            'header_name' => $cred['header_name'] ?? 'Authorization',
            'hint'        => $cred['hint'] ?? '',
          ];
        }
      }
      if (isset($credentials[$ref])) {
        return ['mode' => 'vault', 'credential_ref' => $ref, 'manual_present' => false];
      }
    }

    // A legacy plaintext auth header is a secret; we never export its value, only
    // the fact that one exists, so import can prompt for a vault credential.
    $manualPresent = !empty($webhook['auth_header']);

    return [
      'mode'           => $manualPresent ? 'manual' : 'none',
      'credential_ref' => null,
      'manual_present' => $manualPresent,
    ];
  }

  private function exportTrigger(int $webhookId, string $triggerName, bool $redactExamples = true): array {
    $schema = $this->schemas->findByWebhookAndTrigger($webhookId, $triggerName);

    $schemaDoc = null;
    if ($schema !== null) {
      $example = $schema['example_payload'] ?? null;
      if ($redactExamples && $example !== null) {
        $example = (new PayloadRedactor())->redact($example);
      }

      $schemaDoc = [
        'example_payload'        => $example,
        'field_mapping'          => $schema['field_mapping'] ?? null,
        'conditions'             => $schema['conditions'] ?? null,
        'conditions_evaluate_on' => $schema['conditions_evaluate_on'] ?? 'original',
        'include_user_data'      => (bool) ($schema['include_user_data'] ?? false),
        'use_shared_example'     => (bool) ($schema['use_shared_example'] ?? true),
      ];
    }

    $doc = [
      'name'      => $triggerName,
      'schema'    => $schemaDoc,
      'code_glue' => null,
    ];

    /**
     * Let extensions (Pro Code Glue) attach per-trigger data to the export doc.
     *
     * @param array  $doc         The trigger document (name, schema, code_glue).
     * @param int    $webhookId   The webhook being exported.
     * @param string $triggerName The trigger name.
     */
    return apply_filters('fswa_export_trigger', $doc, $webhookId, $triggerName);
  }

  private function exportChain(int $chainId): ?array {
    $chain = $this->chains->find($chainId);
    if ($chain === null) {
      return null;
    }

    $links = $this->links->findByChain($chainId);

    $webhookIds = [];
    foreach ($links as $link) {
      $webhookIds[] = (int) $link['source_webhook_id'];
      $webhookIds[] = (int) $link['target_webhook_id'];
    }
    $uuidMap = $this->webhooks->getUuidMap(array_values(array_unique($webhookIds)));

    $linkDocs = [];
    foreach ($links as $link) {
      $sourceUuid = $uuidMap[(int) $link['source_webhook_id']] ?? null;
      $targetUuid = $uuidMap[(int) $link['target_webhook_id']] ?? null;
      if ($sourceUuid === null || $targetUuid === null) {
        continue;
      }
      $linkDocs[] = ['source_uuid' => $sourceUuid, 'target_uuid' => $targetUuid];
    }

    return [
      'name'        => $chain['name'] ?? '',
      'description' => $chain['description'] ?? null,
      'links'       => $linkDocs,
    ];
  }

  private function resolveKind(array $webhookDocs, array $chainDocs): string {
    if (!empty($chainDocs) && empty($webhookDocs)) {
      return 'chain';
    }
    if (!empty($chainDocs)) {
      return 'bundle';
    }
    return 'webhook';
  }
}
