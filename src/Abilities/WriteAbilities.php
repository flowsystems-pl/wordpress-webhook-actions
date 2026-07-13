<?php

namespace FlowSystems\WebhookActions\Abilities;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Repositories\WebhookRepository;
use FlowSystems\WebhookActions\Repositories\SchemaRepository;
use FlowSystems\WebhookActions\Repositories\CredentialRepository;
use FlowSystems\WebhookActions\Repositories\ChainRepository;
use FlowSystems\WebhookActions\Repositories\ChainLinkRepository;
use FlowSystems\WebhookActions\Services\WpAppPasswordService;
use WP_Error;

/**
 * Write-scoped ability handlers — the agent's PLAN steps (create/update/assign,
 * chains, go-live and delete). Every handler here only ever runs after the user
 * has reviewed the plan (and confirmed, where the catalog requires it); the
 * safety gates themselves live in PlanExecutor and the ability definitions.
 */
class WriteAbilities {
  use AbilityErrors;

  /** Operators understood by ConditionEvaluator and the conditions editor. */
  public const CONDITION_OPERATORS = [
    'equals', 'not_equals', 'contains', 'not_contains', 'greater_than', 'less_than',
    'is_empty', 'is_not_empty', 'is_true', 'is_false', 'array_contains', 'object_contains',
  ];

  /** Common foreign spellings mapped onto canonical operators. */
  private const CONDITION_OPERATOR_ALIASES = [
    'eq' => 'equals', '=' => 'equals', '==' => 'equals', 'equal' => 'equals', 'is' => 'equals',
    'neq' => 'not_equals', '!=' => 'not_equals', 'not_equal' => 'not_equals', 'is_not' => 'not_equals',
    'includes' => 'contains', 'has' => 'contains', 'like' => 'contains',
    'not_includes' => 'not_contains', 'excludes' => 'not_contains', 'not_like' => 'not_contains',
    'gt' => 'greater_than', '>' => 'greater_than', 'greater' => 'greater_than',
    'lt' => 'less_than', '<' => 'less_than', 'less' => 'less_than',
    'empty' => 'is_empty', 'not_empty' => 'is_not_empty',
    'true' => 'is_true', 'false' => 'is_false',
  ];

  /** Operators whose rule is meaningless without a comparison value. */
  private const CONDITION_OPERATORS_NEED_VALUE = [
    'equals', 'not_equals', 'contains', 'not_contains', 'greater_than', 'less_than',
    'array_contains', 'object_contains',
  ];

  public function createWebhook(array $input): array|WP_Error {
    $name = sanitize_text_field((string) ($input['name'] ?? ''));
    $url  = esc_url_raw((string) ($input['endpoint_url'] ?? ''));
    if ($name === '' || $url === '') {
      return $this->invalid(__('name and endpoint_url are required.', 'flowsystems-webhook-actions'));
    }

    $repo = new WebhookRepository();
    $id   = $repo->create([
      'name'               => $name,
      'endpoint_url'       => $url,
      'http_method'        => strtoupper((string) ($input['http_method'] ?? 'POST')),
      'triggers'           => array_map('sanitize_text_field', (array) ($input['triggers'] ?? [])),
      'auth_credential_id' => isset($input['auth_credential_id']) ? (int) $input['auth_credential_id'] : null,
      'custom_headers'     => $input['custom_headers'] ?? null,
      'url_params'         => $input['url_params'] ?? null,
      'is_synchronous'     => isset($input['is_synchronous']) ? (int) (bool) $input['is_synchronous'] : 0,
      // Always created disabled — the agent must explicitly enable (with confirm).
      'is_enabled'         => 0,
    ]);

    if (!$id) {
      return new WP_Error('fswa_create_failed', __('Failed to create webhook.', 'flowsystems-webhook-actions'), ['status' => 500]);
    }

    return ['webhook' => $repo->find((int) $id), 'created_disabled' => true];
  }

  public function updateWebhook(array $input): array|WP_Error {
    $id   = (int) ($input['id'] ?? 0);
    $repo = new WebhookRepository();
    if (!$repo->find($id)) {
      return $this->notFound();
    }

    $data = [];
    foreach (['name', 'endpoint_url', 'http_method', 'triggers', 'auth_credential_id', 'custom_headers', 'url_params', 'is_synchronous'] as $field) {
      if (array_key_exists($field, $input)) {
        $data[$field] = $input[$field];
      }
    }
    if (isset($data['name'])) {
      $data['name'] = sanitize_text_field((string) $data['name']);
    }
    if (isset($data['endpoint_url'])) {
      $data['endpoint_url'] = esc_url_raw((string) $data['endpoint_url']);
    }
    if (isset($data['triggers'])) {
      $data['triggers'] = array_map('sanitize_text_field', (array) $data['triggers']);
    }

    if (!$repo->update($id, $data)) {
      return new WP_Error('fswa_update_failed', __('Failed to update webhook.', 'flowsystems-webhook-actions'), ['status' => 500]);
    }

    return ['webhook' => $repo->find($id)];
  }

  public function setMapping(array $input): array|WP_Error {
    $webhookId = (int) ($input['webhook_id'] ?? 0);
    $trigger   = (string) ($input['trigger'] ?? '');
    if ($webhookId <= 0 || $trigger === '' || !isset($input['field_mapping'])) {
      return $this->invalid(__('webhook_id, trigger and field_mapping are required.', 'flowsystems-webhook-actions'));
    }
    $schemaId = (new SchemaRepository())->upsert($webhookId, $trigger, ['field_mapping' => $input['field_mapping']]);
    if (!$schemaId) {
      return new WP_Error('fswa_mapping_failed', __('Failed to save mapping.', 'flowsystems-webhook-actions'), ['status' => 500]);
    }
    return ['schema_id' => (int) $schemaId];
  }

  public function setConditions(array $input): array|WP_Error {
    $webhookId = (int) ($input['webhook_id'] ?? 0);
    $trigger   = (string) ($input['trigger'] ?? '');
    if ($webhookId <= 0 || $trigger === '' || !isset($input['conditions'])) {
      return $this->invalid(__('webhook_id, trigger and conditions are required.', 'flowsystems-webhook-actions'));
    }
    // Callers (especially LLMs) get the shape wrong in creative ways; normalize
    // to the canonical envelope or refuse — never store a shape the evaluator
    // and the conditions editor can't read.
    $conditions = $this->normalizeConditions($input['conditions']);
    if (is_wp_error($conditions)) {
      return $conditions;
    }

    // Mirror the REST endpoint's free-tier limits (SchemasController::updateSchema):
    // groups and more than one rule are Pro; free is locked to 'and'.
    $proActive = class_exists('FlowSystems\WebhookActions\Pro\License\LicenseManager')
      && (new \FlowSystems\WebhookActions\Pro\License\LicenseManager())->isActive();
    if (!$proActive && !empty($conditions['rules'])) {
      foreach ($conditions['rules'] as $rule) {
        if (($rule['type'] ?? '') === 'group') {
          return new WP_Error('fswa_pro_required', __('Condition groups require a Pro license — propose a single simple rule instead.', 'flowsystems-webhook-actions'), ['status' => 403]);
        }
      }
      if (count($conditions['rules']) > 1) {
        return new WP_Error('fswa_pro_required', __('More than 1 condition requires a Pro license — propose a single simple rule instead.', 'flowsystems-webhook-actions'), ['status' => 403]);
      }
      $conditions['type'] = 'and';
    }

    $schemaId = (new SchemaRepository())->upsert($webhookId, $trigger, [
      'conditions'             => $conditions,
      'conditions_evaluate_on' => $input['conditions_evaluate_on'] ?? 'original',
    ]);
    if (!$schemaId) {
      return new WP_Error('fswa_conditions_failed', __('Failed to save conditions.', 'flowsystems-webhook-actions'), ['status' => 500]);
    }
    return ['schema_id' => (int) $schemaId];
  }

  public function assignCredential(array $input): array|WP_Error {
    $webhookId    = (int) ($input['webhook_id'] ?? 0);
    $repo         = new WebhookRepository();
    if (!$repo->find($webhookId)) {
      return $this->notFound();
    }
    $credentialId = array_key_exists('credential_id', $input) && $input['credential_id'] !== null
      ? (int) $input['credential_id']
      : null;

    if ($credentialId !== null && !(new CredentialRepository())->find($credentialId)) {
      return $this->invalid(__('Credential not found.', 'flowsystems-webhook-actions'));
    }

    $repo->update($webhookId, ['auth_credential_id' => $credentialId]);
    return ['webhook_id' => $webhookId, 'auth_credential_id' => $credentialId];
  }

  public function provisionWpAppPassword(array $input): array|WP_Error {
    $created = (new WpAppPasswordService())->provisionForCurrentUser((string) ($input['name'] ?? ''));
    if (is_wp_error($created)) {
      return $created;
    }
    // Shape the result so {{step_N.id}} resolves to the new credential id.
    return ['credential' => $created, 'id' => (int) ($created['id'] ?? 0)];
  }

  public function createChain(array $input): array|WP_Error {
    $name = sanitize_text_field((string) ($input['name'] ?? ''));
    if ($name === '') {
      return $this->invalid(__('Chain name is required.', 'flowsystems-webhook-actions'));
    }
    $repo = new ChainRepository();
    if ($repo->findByName($name)) {
      return new WP_Error('fswa_duplicate_chain', __('A chain with this name already exists.', 'flowsystems-webhook-actions'), ['status' => 409]);
    }
    $id = $repo->create(['name' => $name, 'description' => sanitize_text_field((string) ($input['description'] ?? ''))]);
    if (!$id) {
      return new WP_Error('fswa_chain_failed', __('Failed to create chain.', 'flowsystems-webhook-actions'), ['status' => 500]);
    }
    return ['chain' => $repo->find((int) $id)];
  }

  public function createChainLink(array $input): array|WP_Error {
    $chainId = (int) ($input['chain_id'] ?? 0);
    $source  = (int) ($input['source_webhook_id'] ?? 0);
    $target  = (int) ($input['target_webhook_id'] ?? 0);
    if ($chainId <= 0 || $source <= 0 || $target <= 0) {
      return $this->invalid(__('chain_id, source_webhook_id and target_webhook_id are required.', 'flowsystems-webhook-actions'));
    }
    $links = new ChainLinkRepository();
    if ($links->wouldCreateCycle($source, $target)) {
      return new WP_Error('fswa_chain_cycle', __('That link would create a cycle across an existing chain.', 'flowsystems-webhook-actions'), ['status' => 409]);
    }
    $id = $links->create($chainId, $source, $target);
    if (!$id) {
      return new WP_Error('fswa_link_failed', __('Failed to create chain link.', 'flowsystems-webhook-actions'), ['status' => 500]);
    }
    return ['link' => $links->find((int) $id)];
  }

  // ===================================================================
  // Go-live / destructive
  // ===================================================================

  public function enableWebhook(array $input): array|WP_Error {
    $id      = (int) ($input['id'] ?? 0);
    $repo    = new WebhookRepository();
    if (!$repo->find($id)) {
      return $this->notFound();
    }
    $enabled = array_key_exists('enabled', $input) ? (bool) $input['enabled'] : true;
    $repo->setEnabled($id, $enabled);
    return ['id' => $id, 'is_enabled' => $enabled];
  }

  public function deleteWebhook(array $input): array|WP_Error {
    $id   = (int) ($input['id'] ?? 0);
    $repo = new WebhookRepository();
    if (!$repo->find($id)) {
      return $this->notFound();
    }
    if (!$repo->delete($id)) {
      return new WP_Error('fswa_delete_failed', __('Failed to delete webhook.', 'flowsystems-webhook-actions'), ['status' => 500]);
    }
    return ['deleted' => true, 'id' => $id];
  }

  // ===================================================================
  // Condition normalization
  // ===================================================================

  /**
   * Coerce caller-supplied conditions into the canonical envelope
   * {enabled, type: and|or, rules: [{field, operator, value} | group]} —
   * accepting a bare rule list and common key/operator aliases — or explain
   * exactly what is wrong so an agent can correct itself.
   */
  private function normalizeConditions(mixed $raw): array|WP_Error {
    if (is_string($raw)) {
      $decoded = json_decode($raw, true);
      if (is_array($decoded)) {
        $raw = $decoded;
      }
    }
    if (!is_array($raw)) {
      return $this->invalid(__('conditions must be an object {enabled, type, rules: [...]}.', 'flowsystems-webhook-actions'));
    }

    // A bare list of rules → wrap in the canonical envelope.
    if ($raw === [] || array_keys($raw) === range(0, count($raw) - 1)) {
      $raw = ['enabled' => true, 'type' => 'and', 'rules' => $raw];
    }

    if (!isset($raw['rules']) || !is_array($raw['rules'])) {
      return $this->invalid(__('conditions.rules must be an array of rule objects.', 'flowsystems-webhook-actions'));
    }

    $type  = strtolower((string) ($raw['type'] ?? $raw['logic'] ?? $raw['match'] ?? 'and'));
    $rules = [];
    foreach ($raw['rules'] as $item) {
      if (is_array($item) && (($item['type'] ?? '') === 'group')) {
        $match = strtolower((string) ($item['match'] ?? 'and'));
        $sub   = [];
        foreach ((array) ($item['rules'] ?? []) as $r) {
          $n = $this->normalizeConditionRule($r);
          if (is_wp_error($n)) {
            return $n;
          }
          $sub[] = $n;
        }
        $rules[] = ['type' => 'group', 'match' => in_array($match, ['or', 'any'], true) ? 'or' : 'and', 'rules' => $sub];
        continue;
      }
      $n = $this->normalizeConditionRule($item);
      if (is_wp_error($n)) {
        return $n;
      }
      $rules[] = $n;
    }

    return [
      'enabled' => array_key_exists('enabled', $raw) ? (bool) $raw['enabled'] : true,
      'type'    => in_array($type, ['or', 'any'], true) ? 'or' : 'and',
      'rules'   => $rules,
    ];
  }

  private function normalizeConditionRule(mixed $rule): array|WP_Error {
    if (!is_array($rule)) {
      return $this->invalid(__('Each condition rule must be an object {field, operator, value}.', 'flowsystems-webhook-actions'));
    }

    $field    = (string) ($rule['field'] ?? $rule['key'] ?? $rule['path'] ?? '');
    $operator = strtolower(trim((string) ($rule['operator'] ?? $rule['compare'] ?? $rule['op'] ?? 'equals')));
    $operator = self::CONDITION_OPERATOR_ALIASES[$operator] ?? $operator;

    if ($field === '') {
      return $this->invalid(__('A condition rule is missing "field" — a dot-path into the trigger payload (use get_trigger_schema to see the available fields).', 'flowsystems-webhook-actions'));
    }
    if (!in_array($operator, self::CONDITION_OPERATORS, true)) {
      return $this->invalid(sprintf(
        /* translators: 1: the rejected operator, 2: list of valid operators */
        __('Unknown condition operator "%1$s". Valid operators: %2$s.', 'flowsystems-webhook-actions'),
        $operator,
        implode(', ', self::CONDITION_OPERATORS)
      ));
    }

    $value = $rule['value'] ?? '';
    if (($value === '' || $value === null) && in_array($operator, self::CONDITION_OPERATORS_NEED_VALUE, true)) {
      return $this->invalid(sprintf(
        /* translators: 1: rule field path, 2: operator */
        __('The condition rule on "%1$s" (%2$s) has an empty value — ask the user for the value before setting this condition.', 'flowsystems-webhook-actions'),
        $field,
        $operator
      ));
    }

    $normalized = ['field' => $field, 'operator' => $operator, 'value' => $value];
    if (!empty($rule['cast']) && in_array($rule['cast'], ['number', 'string', 'boolean', 'stringify'], true)) {
      $normalized['cast'] = $rule['cast'];
    }
    // object_contains may carry a separate key filter; keep it only when it
    // wasn't already consumed as the field alias.
    if ($operator === 'object_contains' && isset($rule['field'], $rule['key'])) {
      $normalized['key'] = (string) $rule['key'];
    }
    return $normalized;
  }
}
