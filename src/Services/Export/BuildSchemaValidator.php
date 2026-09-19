<?php

namespace FlowSystems\WebhookActions\Services\Export;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Support\Arr;
use WP_Error;

/**
 * Strict validator for an imported build document against the v1 export schema.
 *
 * Every object is checked for its exact set of keys (unknown keys are rejected),
 * every value is type-/enum-checked, required keys are enforced, and referential
 * integrity is verified (a vault auth ref must resolve to a listed credential; a
 * chain link's UUIDs must resolve to listed webhooks). Provenance is deliberately
 * NOT checked — a hand-written file that conforms exactly to the schema is valid;
 * anything that does not is rejected with a precise, path-anchored message.
 *
 * The document produced by {@see BuildExporter} is the authority for this schema.
 * The single documented extension slot is Pro's per-trigger `code_glue` block,
 * whose key is defined by core (null in free) and whose shape is validated here.
 * Additional top-level keys (e.g. a future `ai_build` block) can be permitted by
 * an extension via the `fswa_import_extra_root_keys` filter.
 */
class BuildSchemaValidator {
  private const HTTP_METHODS   = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
  private const CRED_TYPES     = ['bearer', 'basic', 'api_key', 'custom'];
  private const AUTH_MODES     = ['vault', 'manual', 'none'];
  private const EVALUATE_ON    = ['original', 'transformed'];
  private const EXPORT_KINDS   = ['webhook', 'chain', 'bundle'];

  // Length caps — reject oversize values (DB column limits / resource abuse).
  private const MAX_NAME       = 255;
  private const MAX_URL        = 2048;
  private const MAX_UUID       = 191;
  private const MAX_HEADER     = 8192;
  private const MAX_DESC       = 4000;    // description (matches Webhooks/ChainsController)
  private const MAX_CODE       = 262144;  // 256 KB per snippet

  /**
   * @return true|WP_Error
   */
  public function validate(array $document) {
    // ---- Root ---------------------------------------------------------------
    $extraRoot = array_values(array_filter(
      (array) apply_filters('fswa_import_extra_root_keys', []),
      'is_string'
    ));
    // `ai_build` is a documented provenance block (Build-with-AI transcript). It
    // is display-only and never recreated on import, but must be tolerated so a
    // build exported with a transcript still imports anywhere.
    $err = $this->allowOnly($document, array_merge(['fswa_export', 'credentials', 'webhooks', 'chains', 'ai_build'], $extraRoot), 'root');
    if ($err) return $err;
    if ($e = $this->requireKeys($document, ['fswa_export', 'webhooks'], 'root')) return $e;
    if ($e = $this->validateAiBuild($document['ai_build'] ?? null)) return $e;

    // ---- fswa_export meta ---------------------------------------------------
    $meta = $document['fswa_export'];
    if (!$this->isObject($meta)) return $this->err('fswa_export', __('must be an object.', 'flowsystems-webhook-actions'));
    if ($e = $this->allowOnly($meta, ['version', 'kind', 'exported_at', 'source_site', 'plugin_version'], 'fswa_export')) return $e;
    if (!isset($meta['version']) || !is_int($meta['version'])) {
      return $this->err('fswa_export.version', __('is required and must be an integer.', 'flowsystems-webhook-actions'));
    }
    if ((int) $meta['version'] !== BuildExporter::SCHEMA_VERSION) {
      return $this->err(
        'fswa_export.version',
        sprintf(
          /* translators: 1: found version, 2: supported version */
          __('is %1$d; this plugin imports version %2$d builds.', 'flowsystems-webhook-actions'),
          (int) $meta['version'],
          BuildExporter::SCHEMA_VERSION
        )
      );
    }
    if (isset($meta['kind']) && ($e = $this->enum($meta['kind'], self::EXPORT_KINDS, 'fswa_export.kind'))) return $e;
    foreach (['exported_at', 'source_site'] as $k) {
      if (isset($meta[$k]) && !is_string($meta[$k])) return $this->err("fswa_export.$k", __('must be a string.', 'flowsystems-webhook-actions'));
    }
    if (array_key_exists('plugin_version', $meta) && $meta['plugin_version'] !== null && !is_string($meta['plugin_version'])) {
      return $this->err('fswa_export.plugin_version', __('must be a string or null.', 'flowsystems-webhook-actions'));
    }

    // ---- credentials[] ------------------------------------------------------
    $credRefs = [];
    if (array_key_exists('credentials', $document)) {
      if (!$this->isList($document['credentials'])) return $this->err('credentials', __('must be an array.', 'flowsystems-webhook-actions'));
      foreach ($document['credentials'] as $i => $cred) {
        $err = $this->validateCredential($cred, "credentials[$i]");
        if ($err) return $err;
        $credRefs[(string) $cred['ref']] = true;
      }
    }

    // ---- webhooks[] ---------------------------------------------------------
    if (!$this->isList($document['webhooks'])) return $this->err('webhooks', __('must be an array.', 'flowsystems-webhook-actions'));
    if (count($document['webhooks']) === 0) {
      return $this->err('webhooks', __('contains no webhooks.', 'flowsystems-webhook-actions'));
    }
    $uuids = [];
    foreach ($document['webhooks'] as $i => $webhook) {
      $err = $this->validateWebhook($webhook, $credRefs, "webhooks[$i]");
      if ($err) return $err;
      $uuids[(string) $webhook['uuid']] = true;
    }

    // ---- chains[] -----------------------------------------------------------
    if (array_key_exists('chains', $document)) {
      if (!$this->isList($document['chains'])) return $this->err('chains', __('must be an array.', 'flowsystems-webhook-actions'));
      foreach ($document['chains'] as $i => $chain) {
        $err = $this->validateChain($chain, $uuids, "chains[$i]");
        if ($err) return $err;
      }
    }

    return true;
  }

  /**
   * Validate the optional Build-with-AI provenance block. Known keys are typed;
   * unknown ones are tolerated so a build exported by a newer Pro still imports
   * here (the block is display-only — nothing in it is ever executed or created).
   *
   * @return WP_Error|null
   */
  private function validateAiBuild($aiBuild) {
    if ($aiBuild === null) return null;
    if (!$this->isObject($aiBuild)) return $this->err('ai_build', __('must be an object.', 'flowsystems-webhook-actions'));

    foreach (['conversation_uuid', 'title', 'model', 'transport'] as $key) {
      if (isset($aiBuild[$key]) && !is_string($aiBuild[$key])) {
        return $this->err("ai_build.$key", __('must be a string.', 'flowsystems-webhook-actions'));
      }
    }

    // transcript[] = the conversation; steps[] = the plan the agent applied.
    // Both are lists of string-valued records.
    foreach (['transcript' => ['role', 'content'], 'steps' => ['ability', 'summary', 'status']] as $key => $fields) {
      if (!array_key_exists($key, $aiBuild)) continue;
      if (!$this->isList($aiBuild[$key])) {
        return $this->err("ai_build.$key", __('must be an array.', 'flowsystems-webhook-actions'));
      }
      foreach ($aiBuild[$key] as $i => $entry) {
        if (!$this->isObject($entry)) {
          return $this->err("ai_build.$key" . "[$i]", __('must be an object.', 'flowsystems-webhook-actions'));
        }
        foreach ($fields as $field) {
          if (isset($entry[$field]) && !is_string($entry[$field])) {
            return $this->err("ai_build.$key" . "[$i].$field", __('must be a string.', 'flowsystems-webhook-actions'));
          }
        }
      }
    }

    return null;
  }

  /**
   * @return WP_Error|null
   */
  private function validateCredential($cred, string $path) {
    if (!$this->isObject($cred)) return $this->err($path, __('must be an object.', 'flowsystems-webhook-actions'));
    if ($e = $this->allowOnly($cred, ['ref', 'name', 'type', 'header_name', 'hint'], $path)) return $e;
    if ($e = $this->requireKeys($cred, ['ref', 'name', 'type'], $path)) return $e;
    if ($e = $this->str($cred['ref'], "$path.ref", false)) return $e;
    if ($e = $this->str($cred['name'], "$path.name")) return $e;
    if ($e = $this->enum($cred['type'], self::CRED_TYPES, "$path.type")) return $e;
    foreach (['header_name', 'hint'] as $k) {
      if (isset($cred[$k]) && ($e = $this->str($cred[$k], "$path.$k"))) return $e;
    }
    return null;
  }

  /**
   * @param array<string, true> $credRefs
   * @return WP_Error|null
   */
  private function validateWebhook($webhook, array $credRefs, string $path) {
    if (!$this->isObject($webhook)) return $this->err($path, __('must be an object.', 'flowsystems-webhook-actions'));
    $allowed = ['uuid', 'name', 'description', 'endpoint_url', 'http_method', 'custom_headers', 'url_params', 'is_enabled', 'is_synchronous', 'auth', 'triggers', 'notification_rules'];
    if ($e = $this->allowOnly($webhook, $allowed, $path)) return $e;
    if ($e = $this->requireKeys($webhook, ['uuid', 'name', 'endpoint_url', 'auth', 'triggers'], $path)) return $e;

    if ($e = $this->str($webhook['uuid'], "$path.uuid", false, self::MAX_UUID)) return $e;
    if ($e = $this->str($webhook['name'], "$path.name", true, self::MAX_NAME)) return $e;
    if ($e = $this->str($webhook['endpoint_url'], "$path.endpoint_url", false, self::MAX_URL)) return $e;
    // Only http(s) endpoints — never javascript:, data:, file:, gopher: etc. The
    // server makes real outbound requests to this URL. Templated paths ({{...}})
    // are allowed after the scheme.
    if (!preg_match('#^https?://#i', $webhook['endpoint_url'])) {
      return $this->err("$path.endpoint_url", __('must be an http:// or https:// URL.', 'flowsystems-webhook-actions'));
    }
    if (isset($webhook['description']) && $webhook['description'] !== null && ($e = $this->str($webhook['description'], "$path.description", true, self::MAX_DESC))) return $e;
    if (isset($webhook['http_method']) && ($e = $this->enum($webhook['http_method'], self::HTTP_METHODS, "$path.http_method"))) return $e;
    foreach (['custom_headers', 'url_params'] as $k) {
      if (isset($webhook[$k]) && $webhook[$k] !== null && ($e = $this->headerMap($webhook[$k], "$path.$k"))) return $e;
    }
    foreach (['is_enabled', 'is_synchronous'] as $k) {
      if (isset($webhook[$k]) && ($e = $this->bool($webhook[$k], "$path.$k"))) return $e;
    }

    // auth
    $err = $this->validateAuth($webhook['auth'], $credRefs, "$path.auth");
    if ($err) return $err;

    // notification_rules[] — portable rule definitions; channels are site-specific and never travel.
    if (isset($webhook['notification_rules']) && $webhook['notification_rules'] !== null) {
      if (!$this->isList($webhook['notification_rules'])) return $this->err("$path.notification_rules", __('must be an array.', 'flowsystems-webhook-actions'));
      foreach ($webhook['notification_rules'] as $i => $rule) {
        if (!$this->isObject($rule)) return $this->err("$path.notification_rules[$i]", __('must be an object.', 'flowsystems-webhook-actions'));
        if ($e = $this->allowOnly($rule, ['name', 'event', 'is_enabled', 'filters', 'template', 'throttle_seconds', 'digest', 'channel_names'], "$path.notification_rules[$i]")) return $e;
        if ($e = $this->requireKeys($rule, ['event'], "$path.notification_rules[$i]")) return $e;
        if ($e = $this->enum($rule['event'], \FlowSystems\WebhookActions\Services\Notifications\DeliveryEvents::ALL, "$path.notification_rules[$i].event")) return $e;
      }
    }

    // triggers[]
    if (!$this->isList($webhook['triggers'])) return $this->err("$path.triggers", __('must be an array.', 'flowsystems-webhook-actions'));
    foreach ($webhook['triggers'] as $i => $trigger) {
      $err = $this->validateTrigger($trigger, "$path.triggers[$i]");
      if ($err) return $err;
    }
    return null;
  }

  /**
   * @param array<string, true> $credRefs
   * @return WP_Error|null
   */
  private function validateAuth($auth, array $credRefs, string $path) {
    if (!$this->isObject($auth)) return $this->err($path, __('must be an object.', 'flowsystems-webhook-actions'));
    if ($e = $this->allowOnly($auth, ['mode', 'credential_ref', 'manual_present'], $path)) return $e;
    if ($e = $this->requireKeys($auth, ['mode'], $path)) return $e;
    if ($e = $this->enum($auth['mode'], self::AUTH_MODES, "$path.mode")) return $e;
    if (isset($auth['manual_present']) && ($e = $this->bool($auth['manual_present'], "$path.manual_present"))) return $e;

    $ref = $auth['credential_ref'] ?? null;
    if ($auth['mode'] === 'vault') {
      if (!is_string($ref) || $ref === '') return $this->err("$path.credential_ref", __('is required when mode is "vault".', 'flowsystems-webhook-actions'));
      if (!isset($credRefs[$ref])) return $this->err("$path.credential_ref", __('references a credential that is not listed in credentials[].', 'flowsystems-webhook-actions'));
    } elseif ($ref !== null && $ref !== '' && !is_string($ref)) {
      return $this->err("$path.credential_ref", __('must be a string or null.', 'flowsystems-webhook-actions'));
    }
    return null;
  }

  /**
   * @return WP_Error|null
   */
  private function validateTrigger($trigger, string $path) {
    if (!$this->isObject($trigger)) return $this->err($path, __('must be an object.', 'flowsystems-webhook-actions'));
    if ($e = $this->allowOnly($trigger, ['name', 'schema', 'code_glue'], $path)) return $e;
    if ($e = $this->requireKeys($trigger, ['name'], $path)) return $e;
    if ($e = $this->str($trigger['name'], "$path.name", false)) return $e;

    if (isset($trigger['schema']) && $trigger['schema'] !== null) {
      $err = $this->validateSchema($trigger['schema'], "$path.schema");
      if ($err) return $err;
    }
    if (isset($trigger['code_glue']) && $trigger['code_glue'] !== null) {
      $err = $this->validateCodeGlue($trigger['code_glue'], "$path.code_glue");
      if ($err) return $err;
    }
    return null;
  }

  /**
   * The captured example payload / mapping / conditions are opaque JSON blobs, so
   * only the container's keys and the typed flags are validated, not their inner
   * shape.
   *
   * @return WP_Error|null
   */
  private function validateSchema($schema, string $path) {
    if (!$this->isObject($schema)) return $this->err($path, __('must be an object.', 'flowsystems-webhook-actions'));
    $allowed = ['example_payload', 'field_mapping', 'conditions', 'conditions_evaluate_on', 'include_user_data', 'use_shared_example'];
    if ($e = $this->allowOnly($schema, $allowed, $path)) return $e;
    if (isset($schema['conditions_evaluate_on']) && ($e = $this->enum($schema['conditions_evaluate_on'], self::EVALUATE_ON, "$path.conditions_evaluate_on"))) return $e;
    foreach (['include_user_data', 'use_shared_example'] as $k) {
      if (isset($schema[$k]) && ($e = $this->bool($schema[$k], "$path.$k"))) return $e;
    }
    return null;
  }

  /**
   * @return WP_Error|null
   */
  private function validateCodeGlue($glue, string $path) {
    if (!$this->isObject($glue)) return $this->err($path, __('must be an object.', 'flowsystems-webhook-actions'));
    if ($e = $this->allowOnly($glue, ['pre', 'post'], $path)) return $e;
    foreach (['pre', 'post'] as $stage) {
      if (isset($glue[$stage]) && $glue[$stage] !== null) {
        $err = $this->validateSnippet($glue[$stage], "$path.$stage");
        if ($err) return $err;
      }
    }
    return null;
  }

  /**
   * @return WP_Error|null
   */
  private function validateSnippet($snippet, string $path) {
    if (!$this->isObject($snippet)) return $this->err($path, __('must be an object.', 'flowsystems-webhook-actions'));
    if ($e = $this->allowOnly($snippet, ['name', 'tags', 'code', 'enabled'], $path)) return $e;
    if ($e = $this->requireKeys($snippet, ['name', 'code'], $path)) return $e;
    if ($e = $this->str($snippet['name'], "$path.name", true, self::MAX_NAME)) return $e;
    if ($e = $this->str($snippet['code'], "$path.code", true, self::MAX_CODE)) return $e;
    if (isset($snippet['enabled']) && ($e = $this->bool($snippet['enabled'], "$path.enabled"))) return $e;
    if (isset($snippet['tags'])) {
      if (!$this->isList($snippet['tags'])) return $this->err("$path.tags", __('must be an array of strings.', 'flowsystems-webhook-actions'));
      foreach ($snippet['tags'] as $i => $tag) {
        if (!is_string($tag)) return $this->err("$path.tags[$i]", __('must be a string.', 'flowsystems-webhook-actions'));
      }
    }
    return null;
  }

  /**
   * @param array<string, true> $uuids
   * @return WP_Error|null
   */
  private function validateChain($chain, array $uuids, string $path) {
    if (!$this->isObject($chain)) return $this->err($path, __('must be an object.', 'flowsystems-webhook-actions'));
    if ($e = $this->allowOnly($chain, ['name', 'description', 'links'], $path)) return $e;
    if ($e = $this->requireKeys($chain, ['name', 'links'], $path)) return $e;
    if ($e = $this->str($chain['name'], "$path.name")) return $e;
    if (isset($chain['description']) && $chain['description'] !== null && ($e = $this->str($chain['description'], "$path.description", true, self::MAX_DESC))) return $e;

    if (!$this->isList($chain['links'])) return $this->err("$path.links", __('must be an array.', 'flowsystems-webhook-actions'));
    foreach ($chain['links'] as $i => $link) {
      $lp = "$path.links[$i]";
      if (!$this->isObject($link)) return $this->err($lp, __('must be an object.', 'flowsystems-webhook-actions'));
      if ($e = $this->allowOnly($link, ['source_uuid', 'target_uuid'], $lp)) return $e;
      if ($e = $this->requireKeys($link, ['source_uuid', 'target_uuid'], $lp)) return $e;
      foreach (['source_uuid', 'target_uuid'] as $k) {
        if ($e = $this->str($link[$k], "$lp.$k", false)) return $e;
        if (!isset($uuids[(string) $link[$k]])) {
          return $this->err("$lp.$k", __('references a webhook UUID not present in webhooks[].', 'flowsystems-webhook-actions'));
        }
      }
    }
    return null;
  }

  /**
   * custom_headers / url_params are lists of {key, value} pairs. Keys are limited
   * to a header/param token ([A-Za-z0-9-_]) — which structurally forbids CR/LF
   * header injection — and values must be CR/LF-free strings.
   *
   * @return WP_Error|null
   */
  private function headerMap($list, string $path) {
    if (!$this->isList($list)) return $this->err($path, __('must be an array of {key, value} pairs.', 'flowsystems-webhook-actions'));
    foreach ($list as $i => $pair) {
      $pp = "{$path}[{$i}]";
      if (!$this->isObject($pair)) return $this->err($pp, __('must be a {key, value} object.', 'flowsystems-webhook-actions'));
      if ($e = $this->allowOnly($pair, ['key', 'value'], $pp)) return $e;
      if ($e = $this->requireKeys($pair, ['key', 'value'], $pp)) return $e;
      if ($e = $this->str($pair['key'], "$pp.key", false, self::MAX_HEADER)) return $e;
      if (!preg_match('/^[A-Za-z0-9\-_]+$/', $pair['key'])) {
        return $this->err("$pp.key", __('may only contain letters, numbers, hyphens and underscores.', 'flowsystems-webhook-actions'));
      }
      if ($e = $this->str($pair['value'], "$pp.value", true, self::MAX_HEADER)) return $e;
      if (preg_match('/[\r\n]/', $pair['value'])) {
        return $this->err("$pp.value", __('must not contain line breaks.', 'flowsystems-webhook-actions'));
      }
    }
    return null;
  }

  // ---- primitives -----------------------------------------------------------

  /** @return WP_Error|null */
  private function requireKeys(array $node, array $required, string $path) {
    foreach ($required as $key) {
      if (!array_key_exists($key, $node)) {
        return $this->err($path, sprintf(/* translators: %s: key name */ __('is missing required key "%s".', 'flowsystems-webhook-actions'), $key));
      }
    }
    return null;
  }

  /** @return WP_Error|null */
  private function allowOnly(array $node, array $allowed, string $path) {
    $unknown = array_diff(array_keys($node), $allowed);
    if (!empty($unknown)) {
      return $this->err($path, sprintf(/* translators: %s: comma-separated key names */ __('has unexpected key(s): %s.', 'flowsystems-webhook-actions'), implode(', ', $unknown)));
    }
    return null;
  }

  /** @return WP_Error|null */
  private function str($val, string $path, bool $allowEmpty = true, int $maxLen = self::MAX_NAME) {
    if (!is_string($val)) return $this->err($path, __('must be a string.', 'flowsystems-webhook-actions'));
    if (!$allowEmpty && $val === '') return $this->err($path, __('must not be empty.', 'flowsystems-webhook-actions'));
    if ($maxLen > 0 && strlen($val) > $maxLen) {
      return $this->err($path, sprintf(/* translators: %d: max length */ __('exceeds the maximum length of %d bytes.', 'flowsystems-webhook-actions'), $maxLen));
    }
    return null;
  }

  /** @return WP_Error|null */
  private function bool($val, string $path) {
    return is_bool($val) ? null : $this->err($path, __('must be a boolean.', 'flowsystems-webhook-actions'));
  }

  /** @return WP_Error|null */
  private function enum($val, array $allowed, string $path) {
    if (!is_string($val) || !in_array($val, $allowed, true)) {
      return $this->err($path, sprintf(/* translators: %s: comma-separated allowed values */ __('must be one of: %s.', 'flowsystems-webhook-actions'), implode(', ', $allowed)));
    }
    return null;
  }

  private function err(string $path, string $message): WP_Error {
    return new WP_Error(
      'fswa_import_invalid',
      /* translators: 1: JSON path, 2: reason */
      sprintf(__('Invalid import file — %1$s %2$s', 'flowsystems-webhook-actions'), $path, $message),
      ['status' => 400]
    );
  }

  /** A JSON object decodes to a PHP associative (non-list) array — or an empty array. */
  private function isObject($v): bool {
    return is_array($v) && ($v === [] || !$this->isList($v));
  }

  /** A JSON array decodes to a PHP list (sequential integer keys from 0). */
  private function isList($v): bool {
    return Arr::isList($v);
  }
}
