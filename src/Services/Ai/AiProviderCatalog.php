<?php

namespace FlowSystems\WebhookActions\Services\Ai;

defined('ABSPATH') || exit;

use WordPress\AiClient\AiClient;

/**
 * Enumerates the AI providers configured in the WordPress 7.0 AI Client and the
 * text-generation models each one exposes.
 *
 * Model lists are fetched live from each provider (their ListModels endpoint),
 * so results are cached briefly to avoid an API round-trip on every page load.
 * The whole class no-ops gracefully when the AI Client is absent (older WP), in
 * which case the BYO-key path is the only option and this returns an empty list.
 */
class AiProviderCatalog {
  /** Transient cache key prefix; one entry per provider id. */
  private const CACHE_PREFIX = 'fswa_ai_models_';
  private const CACHE_TTL    = HOUR_IN_SECONDS;

  /** Human labels override the SDK's terse provider names where useful. */
  private const LABELS = [
    'google'    => 'Google Gemini',
    'openai'    => 'OpenAI',
    'anthropic' => 'Anthropic (Claude)',
  ];

  /**
   * Capabilities that mark a model as specialised for non-text output (image,
   * audio, video, embeddings). The agent only needs instruction-following text
   * generation, so any model advertising one of these is unsuitable.
   */
  private const EXCLUDE_CAPABILITIES = [
    'image_generation',
    'text_to_speech_conversion',
    'speech_generation',
    'music_generation',
    'video_generation',
    'embedding_generation',
  ];

  /**
   * Whether the WordPress AI Client registry is available to introspect.
   */
  public static function isSupported(): bool {
    return class_exists(AiClient::class) && function_exists('wp_ai_client_prompt');
  }

  /**
   * Configured providers with their text-generation models.
   *
   * @return array<int, array{id:string,label:string,configured:bool,models:array<int,array{id:string,name:string}>}>
   */
  public function providers(): array {
    if (!self::isSupported()) {
      return [];
    }

    try {
      $registry = AiClient::defaultRegistry();
    } catch (\Throwable $e) {
      return [];
    }

    $out = [];
    foreach ($this->providerIds($registry) as $id) {
      if (!$registry->isProviderConfigured($id)) {
        continue;
      }
      $out[] = [
        'id'         => $id,
        'label'      => $this->label($registry, $id),
        'configured' => true,
        'models'     => $this->models($registry, $id),
      ];
    }

    return $out;
  }

  /**
   * Ordered list of model ids to try for text generation, preferred first.
   *
   * The user's chosen model leads; then ONE representative model from each other
   * configured provider follows as a fallback. We deliberately jump across
   * providers rather than exhausting one provider's whole catalogue, so a runtime
   * failure (e.g. a quota 429, which is usually per-project and shared across a
   * provider's models) is retried somewhere it can actually succeed.
   *
   * @param string|null $preferredModel The user-selected model id, if any.
   * @return array<int, string>
   */
  public function textModelCandidates(?string $preferredModel = null): array {
    $providers         = $this->providers();
    $preferredProvider = $this->providerOfModel($providers, $preferredModel);

    $candidates = [];
    if ($preferredModel) {
      $candidates[] = $preferredModel;
    }

    foreach ($providers as $provider) {
      // The preferred provider is already represented by the chosen model.
      if ($provider['id'] === $preferredProvider) {
        continue;
      }
      if (!empty($provider['models'])) {
        $candidates[] = $provider['models'][0]['id'];
      }
    }

    return array_values(array_unique($candidates));
  }

  /**
   * Public lookup: which configured provider id owns a given model id, if any.
   */
  public function providerForModel(?string $modelId): ?string {
    return $this->providerOfModel($this->providers(), $modelId);
  }

  /**
   * Find which configured provider owns a given model id.
   */
  private function providerOfModel(array $providers, ?string $modelId): ?string {
    if (!$modelId) {
      return null;
    }
    foreach ($providers as $provider) {
      foreach ($provider['models'] as $model) {
        if ($model['id'] === $modelId) {
          return $provider['id'];
        }
      }
    }
    return null;
  }

  /**
   * Clear the cached model lists (called after a connector change, if needed).
   */
  public function flush(): void {
    if (!self::isSupported()) {
      return;
    }
    try {
      foreach ($this->providerIds(AiClient::defaultRegistry()) as $id) {
        delete_transient(self::CACHE_PREFIX . $id);
      }
    } catch (\Throwable $e) {
      // Nothing to flush.
    }
  }

  /**
   * Normalised list of registered provider ids (the registry may yield enums).
   *
   * @return array<int, string>
   */
  private function providerIds($registry): array {
    $ids = [];
    foreach ((array) $registry->getRegisteredProviderIds() as $id) {
      $ids[] = is_object($id) && property_exists($id, 'value') ? (string) $id->value : (string) $id;
    }
    return $ids;
  }

  private function label($registry, string $id): string {
    if (isset(self::LABELS[$id])) {
      return self::LABELS[$id];
    }
    try {
      $meta = $registry->getProviderClassName($id)::metadata();
      if (method_exists($meta, 'getName')) {
        return (string) $meta->getName();
      }
    } catch (\Throwable $e) {
      // Fall through to the raw id.
    }
    return ucfirst($id);
  }

  /**
   * Text-generation models for a provider, cached per provider.
   *
   * @return array<int, array{id:string,name:string}>
   */
  private function models($registry, string $id): array {
    $cached = get_transient(self::CACHE_PREFIX . $id);
    if (is_array($cached)) {
      return $cached;
    }

    $models = [];
    try {
      $directory = $registry->getProviderClassName($id)::modelMetadataDirectory();
      foreach ($directory->listModelMetadata() as $meta) {
        // Registry-only gate: drop models advertising a non-text output capability.
        if (!$this->hasOnlyTextOutput($meta)) {
          continue;
        }
        $entry = ModelCuration::entry((string) $meta->getId(), (string) $meta->getName());
        if ($entry !== null) {
          $models[] = $entry;
        }
      }

      // Recommended models first, original order preserved within each group.
      usort($models, fn($a, $b) => ($b['recommended'] <=> $a['recommended']));
    } catch (\Throwable $e) {
      // Provider unreachable or key invalid — return what we have (possibly none).
    }

    set_transient(self::CACHE_PREFIX . $id, $models, self::CACHE_TTL);
    return $models;
  }

  /**
   * Registry-only gate: the model must advertise text_generation and must NOT
   * advertise any non-text output capability. (Pattern-based suitability and the
   * recommended flag are applied separately via ModelCuration.)
   */
  private function hasOnlyTextOutput($meta): bool {
    if (!method_exists($meta, 'getSupportedCapabilities')) {
      return false;
    }

    $caps = [];
    foreach ((array) $meta->getSupportedCapabilities() as $capability) {
      $caps[] = is_object($capability) && property_exists($capability, 'value')
        ? (string) $capability->value
        : (string) $capability;
    }

    return in_array('text_generation', $caps, true)
      && !array_intersect(self::EXCLUDE_CAPABILITIES, $caps);
  }
}
