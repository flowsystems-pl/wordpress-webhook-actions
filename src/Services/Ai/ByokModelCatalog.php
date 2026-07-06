<?php

namespace FlowSystems\WebhookActions\Services\Ai;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Repositories\CredentialRepository;
use FlowSystems\WebhookActions\Services\CredentialCipher;
use WP_Error;

/**
 * Lists text models for a bring-your-own-key provider by calling that provider's
 * own "list models" REST endpoint with the vault-stored key, then applying the
 * same ModelCuration rules as the WordPress AI Client path. Results are cached
 * per credential (keyed by a hash of the key) so a key change refetches.
 *
 * This gives the BYO path the same provider/model picker experience as sites
 * that use the WordPress 7.0 AI Client, without needing that client at all.
 */
class ByokModelCatalog {
  private const CACHE_PREFIX = 'fswa_byok_models_';
  private const CACHE_TTL    = HOUR_IN_SECONDS;

  /**
   * Curated model list for a provider whose key lives in the given vault
   * credential. Returns [] when the key is missing or the provider is unknown.
   *
   * @return array<int, array{id:string,name:string,recommended:bool}>
   */
  public function models(string $provider, int $credentialId): array {
    $key = $this->resolveKey($credentialId);
    if ($key === '') {
      return [];
    }

    $cacheKey = self::CACHE_PREFIX . $provider . '_' . substr(md5($key), 0, 12);
    $cached   = get_transient($cacheKey);
    if (is_array($cached)) {
      return $cached;
    }

    $raw = match ($provider) {
      'anthropic' => $this->fetchAnthropic($key),
      'openai'    => $this->fetchOpenAi($key),
      'google'    => $this->fetchGoogle($key),
      default     => [],
    };

    $models = [];
    foreach ($raw as $item) {
      $entry = ModelCuration::entry((string) ($item['id'] ?? ''), (string) ($item['name'] ?? ''));
      if ($entry !== null) {
        $models[] = $entry;
      }
    }

    // Recommended first, original order preserved within each group.
    usort($models, fn($a, $b) => ($b['recommended'] <=> $a['recommended']));

    // Only cache a non-empty result, so a transient auth/network blip retries.
    if ($models) {
      set_transient($cacheKey, $models, self::CACHE_TTL);
    }

    return $models;
  }

  /**
   * Anthropic: GET /v1/models → { data: [ { id, display_name } ] }.
   *
   * @return array<int, array{id:string,name:string}>
   */
  private function fetchAnthropic(string $key): array {
    $data = $this->getJson('https://api.anthropic.com/v1/models?limit=1000', [
      'x-api-key'         => $key,
      'anthropic-version' => '2023-06-01',
    ]);
    if (is_wp_error($data)) {
      return [];
    }

    $out = [];
    foreach ((array) ($data['data'] ?? []) as $model) {
      $out[] = [
        'id'   => (string) ($model['id'] ?? ''),
        'name' => (string) ($model['display_name'] ?? $model['id'] ?? ''),
      ];
    }
    return $out;
  }

  /**
   * OpenAI: GET /v1/models → { data: [ { id } ] } (no display names).
   *
   * @return array<int, array{id:string,name:string}>
   */
  private function fetchOpenAi(string $key): array {
    $data = $this->getJson('https://api.openai.com/v1/models', [
      'Authorization' => 'Bearer ' . $key,
    ]);
    if (is_wp_error($data)) {
      return [];
    }

    $out = [];
    foreach ((array) ($data['data'] ?? []) as $model) {
      $id    = (string) ($model['id'] ?? '');
      $out[] = ['id' => $id, 'name' => $id];
    }
    return $out;
  }

  /**
   * Google: GET /v1beta/models → { models: [ { name: "models/…", displayName,
   * supportedGenerationMethods } ] }. Only keep models that can generateContent.
   *
   * @return array<int, array{id:string,name:string}>
   */
  private function fetchGoogle(string $key): array {
    $data = $this->getJson('https://generativelanguage.googleapis.com/v1beta/models?pageSize=1000', [
      'x-goog-api-key' => $key,
    ]);
    if (is_wp_error($data)) {
      return [];
    }

    $out = [];
    foreach ((array) ($data['models'] ?? []) as $model) {
      $methods = (array) ($model['supportedGenerationMethods'] ?? []);
      if (!in_array('generateContent', $methods, true)) {
        continue;
      }
      $id    = preg_replace('#^models/#', '', (string) ($model['name'] ?? ''));
      $out[] = [
        'id'   => $id,
        'name' => (string) ($model['displayName'] ?? $id),
      ];
    }
    return $out;
  }

  /**
   * GET a JSON endpoint, returning the decoded body or a WP_Error.
   *
   * @param array<string, string> $headers
   * @return array<string, mixed>|WP_Error
   */
  private function getJson(string $url, array $headers) {
    $response = wp_remote_get($url, ['timeout' => 20, 'headers' => $headers]);
    if (is_wp_error($response)) {
      return $response;
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 300) {
      return new WP_Error('fswa_byok_models_http', 'HTTP ' . $code, ['status' => $code]);
    }
    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    return is_array($data) ? $data : new WP_Error('fswa_byok_models_parse', 'Invalid JSON');
  }

  /**
   * Decrypt the vault-stored key for a credential, or '' when unavailable.
   */
  private function resolveKey(int $credentialId): string {
    if ($credentialId <= 0) {
      return '';
    }
    $row = (new CredentialRepository())->findWithSecret($credentialId);
    if (!$row) {
      return '';
    }
    $key = (new CredentialCipher())->decrypt((string) ($row['secret_ciphertext'] ?? ''));
    return is_string($key) ? $key : '';
  }
}
