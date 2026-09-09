<?php

namespace FlowSystems\WebhookActions\Services\Ai;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * Runs the AI Builder on anonymous WP Webhooks trial credits.
 *
 * No API key, no account. The site holds a trial licence key issued by our API
 * (see TrialClient) and we proxy generation through it, so the provider key
 * stays on our side and the user sees the product work before deciding anything.
 *
 * Uses wp_remote_post deliberately: WordPress Playground translates the WP HTTP
 * API into browser fetch(), but does not support curl or file_get_contents — so
 * this transport works inside the Live Preview, which is the whole point.
 */
class HostedTrialTransport implements LlmTransportInterface {
  /** See TrialClient::JSON_HEADERS — `accept` keeps error bodies parseable. */
  private const JSON_HEADERS = [
    'content-type' => 'application/json',
    'accept'       => 'application/json',
  ];

  /** Reported by the API; the model is chosen server-side, not here. */
  private string $model = 'wpwebhooks-hosted';

  /** @var array<string, mixed>|null */
  private ?array $lastRequest = null;

  /** @var array<string, mixed> */
  private array $lastResponseMeta = [];

  public function __construct(private TrialClient $trial) {}

  public function generateText(string $system, array $messages, array $options = []): string|WP_Error {
    $key = $this->trial->key();
    if ($key === '') {
      return new WP_Error('fswa_trial_missing', __('No free trial is active on this site.', 'flowsystems-webhook-actions'));
    }

    $this->lastResponseMeta = [];

    $body = [
      'license_key' => $key,
      'site_url'    => home_url(),
      'purpose'     => (string) ($options['purpose'] ?? 'agent'),
      'messages'    => $this->normalizeMessages($messages),
      // Capabilities. `docs_research` says this client understands the 202
      // "researching_docs" handshake and will wait and re-send; without it
      // the API never holds a turn for the API Docs Library.
      'features'    => ['docs_research'],
    ];

    if ($system !== '') {
      $body['system'] = $system;
    }

    $generation = array_filter([
      'max_tokens'  => isset($options['max_tokens']) ? (int) $options['max_tokens'] : null,
      'temperature' => isset($options['temperature']) ? (float) $options['temperature'] : null,
    ], fn ($v) => $v !== null);

    if ($generation !== []) {
      $body['options'] = $generation;
    }

    $endpoint = TrialClient::apiBase() . '/api/ai/generate';

    $this->lastRequest = [
      'endpoint' => $endpoint,
      'headers'  => self::JSON_HEADERS,
      // The licence key is a credential: it must never reach a trace the user
      // can copy into a support thread.
      'body'     => ['license_key' => '[redacted]'] + $body,
    ];

    $response = wp_remote_post($endpoint, [
      'timeout' => (int) apply_filters('fswa_ai_http_timeout', 120),
      'headers' => self::JSON_HEADERS,
      'body'    => wp_json_encode($body),
    ]);

    if (is_wp_error($response)) {
      return $response;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $data = json_decode((string) wp_remote_retrieve_body($response), true);

    // The API Docs Library is reading this service's reference for the first
    // time: not an error, a wait. The orchestrator shows it and re-sends.
    if ($code === 202 && is_array($data) && ($data['error'] ?? '') === 'researching_docs') {
      return self::researchingError($data);
    }

    if ($code < 200 || $code >= 300) {
      return $this->mapError($code, is_array($data) ? $data : []);
    }

    $remaining = $data['credits']['monthly_remaining'] ?? null;
    $topup     = $data['credits']['topup_remaining'] ?? null;

    if ($remaining !== null || $topup !== null) {
      $this->trial->rememberCredits((int) $remaining + (int) $topup);
    }

    $finish = $data['usage']['finish_reason'] ?? null;
    $this->lastResponseMeta = is_string($finish) && $finish !== '' ? ['finish_reason' => $finish] : [];

    // What the API Docs Library added to this turn (service, operation,
    // verified date, source) — the orchestrator turns it into a pill.
    if (is_array($data['knowledge'] ?? null)) {
      $this->lastResponseMeta['knowledge'] = $data['knowledge'];
    }

    $text = (string) ($data['text'] ?? '');

    return $text !== ''
      ? $text
      : new WP_Error('fswa_trial_empty', __('The AI returned no text.', 'flowsystems-webhook-actions'));
  }

  /**
   * The 202 handshake as a WP_Error the orchestrator recognises by code.
   * Shared shape with the Pro transport: same code, same data keys.
   *
   * @param array<string, mixed> $data
   */
  public static function researchingError(array $data): WP_Error {
    return new WP_Error('fswa_ai_researching_docs', (string) ($data['message'] ?? __('Reading this service\'s API reference…', 'flowsystems-webhook-actions')), [
      'status'      => 202,
      'service'     => (string) ($data['service'] ?? ''),
      'operation'   => (string) ($data['operation'] ?? ''),
      'retry_after' => max(5, min(60, (int) ($data['retry_after'] ?? 20))),
      'elapsed'     => (int) ($data['elapsed'] ?? 0),
      'max_wait'    => (int) ($data['max_wait'] ?? 90),
    ]);
  }

  /**
   * Turn an API failure into something the UI can act on.
   *
   * The two that matter are distinct on purpose: `out_of_credits` means THIS site
   * used its trial and should be offered Pro or its own key, while
   * `trial_budget_exhausted` means our shared daily pool is spent and the visitor
   * did nothing wrong — that one must read as "try again tomorrow", never as a
   * failure of their site.
   */
  private function mapError(int $code, array $data): WP_Error {
    $error   = (string) ($data['error'] ?? '');
    $message = (string) ($data['message'] ?? __('The AI service returned an error.', 'flowsystems-webhook-actions'));

    if ($error === 'out_of_credits') {
      $this->trial->rememberCredits(0, true);

      return new WP_Error('fswa_trial_out_of_credits', $message, ['status' => $code, 'buy_url' => $data['buy_url'] ?? null]);
    }

    // The trial was claimed by a licence this site later bought: its pages and
    // the credits they earned moved across, and the trial itself is closed.
    // Mark it spent locally so the panel stops offering it and points at the
    // licence instead of retrying a key that will never work again.
    if ($error === 'trial_claimed' || $error === 'license_invalid') {
      $this->trial->rememberCredits(0, true);

      return new WP_Error('fswa_trial_closed', $message, ['status' => $code]);
    }

    if ($error === 'trial_budget_exhausted') {
      return new WP_Error('fswa_trial_budget_exhausted', $message, ['status' => $code]);
    }

    return new WP_Error('fswa_trial_error', $message, ['status' => $code, 'error' => $error]);
  }

  /**
   * @param array<int, array{role:string,content:string}> $messages
   * @return array<int, array{role:string,content:string}>
   */
  private function normalizeMessages(array $messages): array {
    $out = [];

    foreach ($messages as $message) {
      $role = (string) ($message['role'] ?? 'user');

      $out[] = [
        // The API validates role in user,assistant,model.
        'role'    => in_array($role, ['user', 'assistant', 'model'], true) ? $role : 'user',
        'content' => (string) ($message['content'] ?? ''),
      ];
    }

    return $out;
  }

  public function id(): string {
    return 'hosted_trial';
  }

  public function model(): string {
    return $this->model;
  }

  public function lastRequest(): ?array {
    return $this->lastRequest;
  }

  public function lastResponseMeta(): array {
    return $this->lastResponseMeta;
  }
}
