<?php

namespace FlowSystems\WebhookActions\Services\Export;

defined('ABSPATH') || exit;

use WP_Error;
use FlowSystems\WebhookActions\Repositories\WebhookRepository;
use FlowSystems\WebhookActions\Repositories\SchemaRepository;
use FlowSystems\WebhookActions\Repositories\ChainRepository;
use FlowSystems\WebhookActions\Repositories\ChainLinkRepository;

/**
 * Restores a portable build document produced by {@see BuildExporter} onto this
 * site. Auth is never carried as a secret: each referenced credential must be
 * resolved by the caller to a local vault credential id (existing or freshly
 * created via CredentialsController) and passed in the credential map.
 *
 * Pro restores Code Glue per trigger by listening on `fswa_import_trigger`.
 */
class BuildImporter {
  private WebhookRepository $webhooks;
  private SchemaRepository $schemas;
  private ChainRepository $chains;
  private ChainLinkRepository $links;
  private BuildSchemaValidator $validator;

  public function __construct() {
    $this->webhooks  = new WebhookRepository();
    $this->schemas   = new SchemaRepository();
    $this->chains    = new ChainRepository();
    $this->links     = new ChainLinkRepository();
    $this->validator = new BuildSchemaValidator();
  }

  /**
   * Validate a document and report what the UI must resolve before import:
   * the credentials that need a local mapping, and any UUID collisions.
   *
   * @return array|WP_Error
   */
  public function analyze(array $document) {
    $error = $this->validate($document);
    if ($error instanceof WP_Error) {
      return $error;
    }

    $needed = [];
    foreach ($document['webhooks'] as $webhook) {
      $auth = $webhook['auth'] ?? [];
      $ref  = $auth['credential_ref'] ?? null;
      $needsAuth = ($auth['mode'] ?? 'none') === 'vault' || !empty($auth['manual_present']);
      if (!$needsAuth) {
        continue;
      }
      // A vault ref points at a credentials[] entry; a manual header has no ref
      // but still needs a credential chosen, keyed by the webhook uuid.
      $key = $ref ?? ('manual:' . ($webhook['uuid'] ?? $webhook['name'] ?? ''));
      if (isset($needed[$key])) {
        continue;
      }
      $needed[$key] = $this->credentialMeta($document, $ref, $webhook);
    }

    $collisions = [];
    foreach ($document['webhooks'] as $webhook) {
      $uuid = $webhook['uuid'] ?? null;
      if ($uuid && $this->webhooks->findByUuid($uuid) !== null) {
        $collisions[] = $uuid;
      }
    }

    return [
      'credentials_needed' => array_values($needed),
      'collisions'         => $collisions,
      'counts'             => [
        'webhooks' => count($document['webhooks']),
        'chains'   => count($document['chains'] ?? []),
      ],
    ];
  }

  /**
   * Import the document.
   *
   * @param array               $document      Portable build document.
   * @param array<string, int>  $credentialMap ref/key => local credential id (0 = no auth).
   * @param array               $options       ['on_collision' => 'copy'|'skip'].
   * @return array|WP_Error Summary of what was created.
   */
  public function import(array $document, array $credentialMap, array $options = []) {
    $error = $this->validate($document);
    if ($error instanceof WP_Error) {
      return $error;
    }

    $onCollision = ($options['on_collision'] ?? 'copy') === 'skip' ? 'skip' : 'copy';

    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query('START TRANSACTION');

    try {
      $uuidToNewId = [];
      $created     = [
        'webhooks'      => 0,
        'chains'        => 0,
        'links'         => 0,
        'skipped'       => 0,
        'webhook_items' => [], // [{id, name}] of created webhooks, for the UI to link to.
        'chain_items'   => [], // [{id, name}] of created chains.
        'problems'      => [], // Anything that could not be imported, in words.
      ];

      foreach ($document['webhooks'] as $webhook) {
        $newId = $this->importWebhook($webhook, $credentialMap, $onCollision, $created);
        $uuid  = (string) ($webhook['uuid'] ?? '');
        if ($uuid === '') {
          continue;
        }

        if ($newId > 0) {
          $uuidToNewId[$uuid] = $newId;
          continue;
        }

        // Skipped as a duplicate. The webhook is already here, so a chain in
        // this document should wire to THAT one rather than lose the hop —
        // "skip the duplicates" means do not create a second copy, not drop
        // the chain that references it.
        $existing = $this->webhooks->findByUuid($uuid);
        if ($existing !== null) {
          $uuidToNewId[$uuid] = (int) $existing['id'];
        }
      }

      foreach ($document['chains'] ?? [] as $chain) {
        $this->importChain($chain, $uuidToNewId, $created);
      }

      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
      $wpdb->query('COMMIT');

      return $created;
    } catch (\Throwable $e) {
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
      $wpdb->query('ROLLBACK');
      return new WP_Error('fswa_import_failed', $e->getMessage(), ['status' => 500]);
    }
  }

  /**
   * @param array<string, int> $credentialMap
   * @param array              $created Mutated in place.
   */
  private function importWebhook(array $webhook, array $credentialMap, string $onCollision, array &$created): int {
    $uuid      = $webhook['uuid'] ?? null;
    $collision = $uuid !== null && $this->webhooks->findByUuid($uuid) !== null;

    if ($collision && $onCollision === 'skip') {
      $created['skipped']++;
      return 0;
    }

    $auth = $webhook['auth'] ?? [];
    $ref  = $auth['credential_ref'] ?? ('manual:' . ($uuid ?? $webhook['name'] ?? ''));
    $credentialId = (int) ($credentialMap[$ref] ?? ($credentialMap[$auth['credential_ref'] ?? ''] ?? 0));

    $data = [
      'name'           => $webhook['name'] ?? '',
      'description'    => $webhook['description'] ?? null,
      'endpoint_url'   => $webhook['endpoint_url'] ?? '',
      'http_method'    => $webhook['http_method'] ?? 'POST',
      'custom_headers' => $webhook['custom_headers'] ?? [],
      'url_params'     => $webhook['url_params'] ?? [],
      // Secrets are never imported: manual auth becomes a chosen vault credential.
      'auth_header'        => null,
      'auth_credential_id' => $credentialId ?: null,
      'is_enabled'         => (bool) ($webhook['is_enabled'] ?? true),
      'is_synchronous'     => (bool) ($webhook['is_synchronous'] ?? false),
      'triggers'           => array_map(
        static fn(array $t): string => (string) ($t['name'] ?? ''),
        $webhook['triggers'] ?? []
      ),
    ];

    // Preserve identity across sites, but on collision let a fresh UUID be minted.
    if ($uuid !== null && !$collision) {
      $data['webhook_uuid'] = $uuid;
    }

    $newId = $this->webhooks->create($data);
    if (!$newId) {
      throw new \RuntimeException('Failed to create imported webhook: ' . esc_html((string) ($webhook['name'] ?? '')));
    }
    $newId = (int) $newId;
    $created['webhooks']++;
    $created['webhook_items'][] = ['id' => $newId, 'name' => $data['name']];

    foreach ($webhook['triggers'] ?? [] as $trigger) {
      $this->importTrigger($newId, $trigger);
    }

    $this->importNotificationRules($newId, (string) $data['name'], $webhook['notification_rules'] ?? [], $created);

    return $newId;
  }

  /**
   * Re-create the webhook's notification rules. A channel is matched by name;
   * a rule whose channels are all missing is imported DISABLED and reported in
   * problems[] so the user can pick a channel and switch it on.
   *
   * @param array<int, array<string, mixed>> $rules
   */
  private function importNotificationRules(int $webhookId, string $webhookName, array $rules, array &$created): void {
    if (empty($rules)) {
      return;
    }
    $byName = [];
    foreach ((new \FlowSystems\WebhookActions\Repositories\NotificationChannelRepository())->getAll() as $channel) {
      $byName[strtolower((string) $channel['name'])] = (int) $channel['id'];
    }
    $repo = new \FlowSystems\WebhookActions\Repositories\NotificationRuleRepository();

    foreach ($rules as $rule) {
      if (!is_array($rule) || empty($rule['event'])) {
        continue;
      }
      $channelIds = [];
      foreach ((array) ($rule['channel_names'] ?? []) as $name) {
        $key = strtolower((string) $name);
        if (isset($byName[$key])) {
          $channelIds[] = $byName[$key];
        }
      }
      $enabled = (bool) ($rule['is_enabled'] ?? true);
      if (empty($channelIds)) {
        $enabled = false;
        $created['problems'][] = sprintf(
          /* translators: 1: rule name or event, 2: webhook name */
          __('The notification rule "%1$s" on "%2$s" was imported switched off: none of its channels exist on this site. Pick a channel in the webhook\'s Notifications section and enable it.', 'flowsystems-webhook-actions'),
          (string) ($rule['name'] ?: $rule['event']),
          $webhookName
        );
      }
      $input = \FlowSystems\WebhookActions\Services\Notifications\RuleInput::fromRequest([
        'webhook_id'       => $webhookId,
        'name'             => (string) ($rule['name'] ?? ''),
        'event'            => (string) $rule['event'],
        'is_enabled'       => $enabled,
        'filters'          => is_array($rule['filters'] ?? null) ? $rule['filters'] : [],
        'channel_ids'      => $channelIds ?: [0],
        'template'         => $rule['template'] ?? null,
        'throttle_seconds' => $rule['throttle_seconds'] ?? null,
        'digest'           => $rule['digest'] ?? null,
      ], true);
      if (is_wp_error($input)) {
        $created['problems'][] = sprintf(
          /* translators: 1: webhook name, 2: error */
          __('A notification rule on "%1$s" was left out: %2$s', 'flowsystems-webhook-actions'),
          $webhookName,
          $input->get_error_message()
        );
        continue;
      }
      $input['channel_ids'] = $channelIds;
      $repo->create($input);
    }
  }

  private function importTrigger(int $webhookId, array $trigger): void {
    $triggerName = (string) ($trigger['name'] ?? '');
    if ($triggerName === '') {
      return;
    }

    $schema = $trigger['schema'] ?? null;
    if (is_array($schema)) {
      $this->schemas->upsert($webhookId, $triggerName, [
        'example_payload'        => $schema['example_payload'] ?? null,
        'field_mapping'          => $schema['field_mapping'] ?? null,
        'conditions'             => $schema['conditions'] ?? null,
        'conditions_evaluate_on' => $schema['conditions_evaluate_on'] ?? 'original',
        'include_user_data'      => (bool) ($schema['include_user_data'] ?? false),
        'use_shared_example'     => (bool) ($schema['use_shared_example'] ?? true),
      ]);
    }

    /**
     * Let extensions (Pro Code Glue) restore per-trigger data on import.
     *
     * @param int    $webhookId   The freshly created webhook id.
     * @param string $triggerName The trigger name.
     * @param array  $trigger     The trigger document (name, schema, code_glue).
     */
    do_action('fswa_import_trigger', $webhookId, $triggerName, $trigger);
  }

  /**
   * @param array<string, int> $uuidToNewId
   * @param array              $created Mutated in place.
   */
  private function importChain(array $chain, array $uuidToNewId, array &$created): void {
    $name = (string) ($chain['name'] ?? '');
    if ($name === '') {
      $created['problems'][] = __('A chain in this file has no name and was not imported.', 'flowsystems-webhook-actions');
      return;
    }

    $name = $this->freeChainName($name);

    $chainId = $this->chains->create([
      'name'        => $name,
      'description' => $chain['description'] ?? null,
    ]);
    if (!$chainId) {
      /* translators: %s: chain name. */
      $created['problems'][] = sprintf(__('The chain "%s" could not be created.', 'flowsystems-webhook-actions'), $name);
      return;
    }
    $chainId = (int) $chainId;
    $created['chains']++;
    $created['chain_items'][] = ['id' => $chainId, 'name' => $name];

    foreach ($chain['links'] ?? [] as $link) {
      $sourceId = (int) ($uuidToNewId[$link['source_uuid'] ?? ''] ?? 0);
      $targetId = (int) ($uuidToNewId[$link['target_uuid'] ?? ''] ?? 0);

      // A hop is the whole point of a chain, so losing one is worth saying out
      // loud rather than quietly counting one link fewer.
      if ($sourceId <= 0 || $targetId <= 0) {
        /* translators: %s: chain name. */
        $created['problems'][] = sprintf(__('A hop of "%s" points at a webhook that is not in this file, and was left out.', 'flowsystems-webhook-actions'), $name);
        continue;
      }
      if ($this->links->wouldCreateCycle($sourceId, $targetId)) {
        /* translators: %s: chain name. */
        $created['problems'][] = sprintf(__('A hop of "%s" was left out because it would have made the chain loop back on itself.', 'flowsystems-webhook-actions'), $name);
        continue;
      }
      if ($this->links->create($chainId, $sourceId, $targetId)) {
        $created['links']++;
      }
    }
  }

  /**
   * A chain name nothing else is using.
   *
   * Chain names are UNIQUE in the database and a chain carries no uuid, so a
   * re-import has to rename rather than match. The old suffix was the UTC
   * minute, which is not unique at all: importing the same file twice inside
   * one minute produced the same name, the insert failed, and the chain went
   * missing without a word. A counter is appended until the name is genuinely
   * free.
   */
  private function freeChainName(string $name): string {
    if ($this->chains->findByName($name) === null) {
      return $name;
    }

    $base = $name . ' (' . gmdate('Y-m-d H:i') . ')';
    if ($this->chains->findByName($base) === null) {
      return $base;
    }

    for ($n = 2; $n <= 50; $n++) {
      $candidate = $base . ' ' . $n;
      if ($this->chains->findByName($candidate) === null) {
        return $candidate;
      }
    }

    // Fifty same-minute imports of one build is not a real scenario; fall back
    // to something unique rather than failing the insert.
    return $base . ' ' . wp_generate_uuid4();
  }

  private function credentialMeta(array $document, ?string $ref, array $webhook): array {
    if ($ref !== null) {
      foreach ($document['credentials'] ?? [] as $cred) {
        if (($cred['ref'] ?? null) === $ref) {
          return $cred;
        }
      }
    }
    // Manual auth header — no metadata beyond the webhook it belongs to.
    return [
      'ref'         => 'manual:' . ($webhook['uuid'] ?? $webhook['name'] ?? ''),
      'name'        => sprintf('%s (auth)', $webhook['name'] ?? 'Imported'),
      'type'        => 'bearer',
      'header_name' => 'Authorization',
      'hint'        => '',
      'manual'      => true,
    ];
  }

  /**
   * Strictly validate the whole document against the v1 schema before any read
   * or write. Delegates to {@see BuildSchemaValidator}.
   *
   * @return true|WP_Error
   */
  private function validate(array $document) {
    return $this->validator->validate($document);
  }
}
