<?php

namespace FlowSystems\WebhookActions\Services\Ai;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Repositories\CredentialRepository;

/**
 * Resolves the active LLM transport for the AI Builder and reports setup status.
 *
 * Two credential SOURCES are supported and presented identically in the UI:
 *   - 'wp_ai_client' — the WordPress 7.0 AI Client (Settings → Connectors). No
 *     keys stored by us; models discovered from the AI Client registry.
 *   - 'byok'         — bring-your-own-key. The user stores an API key per
 *     provider (anthropic/openai/google) in our vault; models are discovered by
 *     calling each provider's own /models endpoint with that key.
 *
 * The 'fswa_ai_source' option selects which one the agent uses:
 *   'auto' (default) prefers the AI Client when available, else BYO; the user can
 *   pin it to 'wp_ai_client' or 'byok'. Either source can pick a provider+model
 *   and falls back across its other configured providers on a runtime failure.
 *
 * A third source, 'hosted', is Pro-only: WP Webhooks AI credits proxied through
 * our API. The free plugin only whitelists the value — without Pro's
 * `fswa_ai_transport` filter supplying a transport, 'hosted' resolves like
 * 'auto', so the option is inert on free installs.
 *
 * Option shapes:
 *   fswa_ai_source     : 'auto' | 'wp_ai_client' | 'byok' | 'hosted'
 *   fswa_ai_client_pref: [ 'provider' => 'google', 'model' => 'gemini-2.5-pro' ]
 *   fswa_ai_byok       : [ 'active' => 'openai', 'providers' => [
 *                            'openai' => [ 'credential_id' => 13, 'model' => 'gpt-4o-mini' ], … ] ]
 *
 * Pro overrides resolution via the `fswa_ai_transport` filter (needs no keys).
 */
class LlmTransport {
  private const SOURCE_KEY = 'fswa_ai_source';
  private const PREF_KEY   = 'fswa_ai_client_pref';
  private const BYOK_KEY   = 'fswa_ai_byok';

  /** Providers we support for the bring-your-own-key path, with display labels. */
  public const BYOK_PROVIDERS = [
    'anthropic' => 'Anthropic (Claude)',
    'openai'    => 'OpenAI',
    'google'    => 'Google Gemini',
  ];

  // ---- Resolution ----------------------------------------------------------

  public function resolve(): ?LlmTransportInterface {
    $override = apply_filters('fswa_ai_transport', null);
    if ($override instanceof LlmTransportInterface) {
      return $override;
    }

    $source      = $this->source();
    $aiAvailable = WpAiClientTransport::isAvailable();

    if ($source === 'byok') {
      return $this->byokTransport() ?? ($aiAvailable ? $this->aiClientTransport() : null);
    }

    if ($source === 'wp_ai_client') {
      return $aiAvailable ? $this->aiClientTransport() : $this->byokTransport();
    }

    // auto: prefer the AI Client when present, else fall back to BYO.
    return $aiAvailable ? $this->aiClientTransport() : $this->byokTransport();
  }

  // ---- Status --------------------------------------------------------------

  /**
   * @return array<string, mixed>
   */
  public function status(): array {
    $aiAvailable = WpAiClientTransport::isAvailable();
    $pref        = $this->preference();
    $active      = $this->resolve();

    return [
      'configured'       => $active !== null,
      'source'           => $this->source(),
      'active_transport' => $active?->id(),
      'active_model'     => $active?->model(),
      'active_provider'  => $this->activeProvider($active, $pref),
      'wp_ai_client'     => [
        'available'  => $aiAvailable,
        'present'    => function_exists('wp_ai_client_prompt'),
        'providers'  => $aiAvailable ? (new AiProviderCatalog())->providers() : [],
        'preference' => $pref,
      ],
      'byok'             => $this->byokStatus(),
      // Pro supplies hosted-credit availability + balances; null on free
      // installs. Lives here (not only in the /status controller) because
      // save-source/byok/preference endpoints return this array too and the
      // admin UI replaces its whole status with each response.
      'hosted'           => apply_filters('fswa_ai_hosted_status', null),
    ];
  }

  /**
   * BYO key status: every supported provider, whether it's connected, its chosen
   * model, key hint, and (for connected providers) its live model list.
   *
   * @return array<string, mixed>
   */
  private function byokStatus(): array {
    $config    = $this->byokConfig();
    $providers = $config['providers'];
    $repo      = new CredentialRepository();
    $catalog   = new ByokModelCatalog();

    $out = [];
    foreach (self::BYOK_PROVIDERS as $id => $label) {
      $entry        = $providers[$id] ?? null;
      $credentialId = (int) ($entry['credential_id'] ?? 0);
      $credential   = $credentialId > 0 ? $repo->find($credentialId) : null;
      $connected    = $credential !== null;

      $out[] = [
        'id'        => $id,
        'label'     => $label,
        'connected' => $connected,
        'model'     => (string) ($entry['model'] ?? ''),
        'hint'      => $connected ? (string) ($credential['hint'] ?? '') : '',
        'models'    => $connected ? $catalog->models($id, $credentialId) : [],
      ];
    }

    return [
      'providers' => $out,
      'active'    => $config['active'],
    ];
  }

  // ---- WP AI Client preference --------------------------------------------

  public function savePreference(string $provider, string $model): void {
    update_option(self::PREF_KEY, ['provider' => $provider, 'model' => $model]);
  }

  /**
   * @return array{provider:?string,model:?string}
   */
  public function preference(): array {
    $pref = get_option(self::PREF_KEY, []);
    $pref = is_array($pref) ? $pref : [];
    return [
      'provider' => isset($pref['provider']) ? (string) $pref['provider'] : null,
      'model'    => isset($pref['model']) ? (string) $pref['model'] : null,
    ];
  }

  // ---- Source toggle -------------------------------------------------------

  public function source(): string {
    $source = (string) get_option(self::SOURCE_KEY, 'auto');
    return in_array($source, ['auto', 'wp_ai_client', 'byok', 'hosted'], true) ? $source : 'auto';
  }

  public function saveSource(string $source): void {
    if (in_array($source, ['auto', 'wp_ai_client', 'byok', 'hosted'], true)) {
      update_option(self::SOURCE_KEY, $source);
    }
  }

  // ---- BYO key management --------------------------------------------------

  /**
   * Upsert a provider's entry (credential and/or chosen model) and make it the
   * active BYO provider.
   */
  public function saveByokProvider(string $provider, int $credentialId, string $model): void {
    if (!isset(self::BYOK_PROVIDERS[$provider])) {
      return;
    }
    $config = $this->byokConfig();

    $existing = $config['providers'][$provider] ?? [];
    if ($credentialId > 0) {
      $existing['credential_id'] = $credentialId;
    }
    if ($model !== '') {
      $existing['model'] = $model;
    }
    $config['providers'][$provider] = $existing;
    $config['active']               = $provider;

    update_option(self::BYOK_KEY, $config);
  }

  /**
   * Remove a provider's BYO entry. Returns the now-orphaned credential id (so the
   * caller can delete the vault secret), or 0.
   */
  public function removeByokProvider(string $provider): int {
    $config       = $this->byokConfig();
    $credentialId = (int) ($config['providers'][$provider]['credential_id'] ?? 0);

    unset($config['providers'][$provider]);
    if (($config['active'] ?? null) === $provider) {
      $config['active'] = array_key_first($config['providers']) ?: null;
    }

    update_option(self::BYOK_KEY, $config);
    return $credentialId;
  }

  // ---- Transport builders --------------------------------------------------

  private function aiClientTransport(): LlmTransportInterface {
    $pref       = $this->preference();
    $candidates = (new AiProviderCatalog())->textModelCandidates($pref['model']);
    return new WpAiClientTransport($candidates);
  }

  /**
   * Build a FallbackTransport from the configured BYO providers, active first.
   */
  private function byokTransport(): ?LlmTransportInterface {
    $config    = $this->byokConfig();
    $providers = $config['providers'];
    if ($providers === []) {
      return null;
    }

    // Active provider leads; the rest follow as fallbacks.
    $order = array_keys($providers);
    if (($active = $config['active']) && isset($providers[$active])) {
      $order = array_merge([$active], array_values(array_diff($order, [$active])));
    }
    $leadProvider = $order[0] ?? null;

    $repo       = new CredentialRepository();
    $transports = [];
    foreach ($order as $provider) {
      $entry        = $providers[$provider];
      $credentialId = (int) ($entry['credential_id'] ?? 0);
      if ($credentialId <= 0 || !$repo->find($credentialId)) {
        continue;
      }
      $model        = (string) ($entry['model'] ?? '');
      $transports[] = $this->providerTransport($provider, $credentialId, $model);

      // Same-provider retry FIRST: a specific model often fails (deprecated,
      // rate-limited, overloaded) while another model on the SAME provider is
      // fine, so try that before switching providers. Only expands the active
      // provider — one extra model — to keep the chain (and latency) short.
      if ($provider === $leadProvider) {
        $alt = $this->alternativeModel($provider, $credentialId, $model);
        if ($alt !== null) {
          $transports[] = $this->providerTransport($provider, $credentialId, $alt);
        }
      }
    }

    if ($transports === []) {
      return null;
    }

    $fallback = new FallbackTransport($transports);
    return $fallback->isEmpty() ? null : $fallback;
  }

  private function providerTransport(string $provider, int $credentialId, string $model): LlmTransportInterface {
    return match ($provider) {
      'openai'    => new OpenAiTransport($credentialId, $model),
      'google'    => new GoogleTransport($credentialId, $model),
      'anthropic' => new AnthropicTransport($credentialId, $model),
      default     => new AnthropicTransport($credentialId, $model),
    };
  }

  /**
   * The best same-provider fallback model: the provider's top-recommended curated
   * model whose id differs from the one already selected. Uses ONLY the cached
   * model list (no network fetch on this hot path — the model list is warmed by
   * the Change-model dropdown), so it returns null on a cold cache rather than
   * adding provider latency to the turn.
   */
  private function alternativeModel(string $provider, int $credentialId, string $selectedModel): ?string {
    foreach ((new ByokModelCatalog())->cachedModels($provider, $credentialId) as $m) {
      $id = (string) ($m['id'] ?? '');
      if ($id !== '' && $id !== $selectedModel) {
        return $id;
      }
    }
    return null;
  }

  // ---- Helpers -------------------------------------------------------------

  /**
   * @return array{active:?string,providers:array<string,array<string,mixed>>}
   */
  private function byokConfig(): array {
    $config    = get_option(self::BYOK_KEY, []);
    $config    = is_array($config) ? $config : [];
    $providers = isset($config['providers']) && is_array($config['providers']) ? $config['providers'] : [];
    return [
      'active'    => isset($config['active']) ? (string) $config['active'] : (array_key_first($providers) ?: null),
      'providers' => $providers,
    ];
  }

  private function activeProvider(?LlmTransportInterface $active, array $pref): ?string {
    if ($active === null) {
      return null;
    }

    $id = $active->id();
    if ($id === 'wp_ai_client') {
      $catalog = new AiProviderCatalog();
      $used    = method_exists($active, 'usedModel') ? $active->usedModel() : '';
      return $catalog->providerForModel($used ?: ($pref['model'] ?? $active->model())) ?? $pref['provider'];
    }

    // BYO transports encode the provider in their id, e.g. 'openai_byok'.
    return str_replace('_byok', '', $id);
  }
}
