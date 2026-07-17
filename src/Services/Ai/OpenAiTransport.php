<?php

namespace FlowSystems\WebhookActions\Services\Ai;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Repositories\CredentialRepository;
use FlowSystems\WebhookActions\Services\CredentialCipher;
use WP_Error;

/**
 * Bring-your-own-key transport for OpenAI's Chat Completions API.
 *
 * Used when the WordPress AI Client is not configured. The API key is read from
 * the credentials vault (decrypted at call time only) and never leaves the
 * server or appears in a response.
 *
 * Note: temperature and token caps are intentionally omitted from the request,
 * because newer model families (gpt-5, o-series) reject `max_tokens` and any
 * non-default temperature. Letting the API use its defaults keeps this BYO path
 * working across every OpenAI model the user might enter.
 */
class OpenAiTransport implements LlmTransportInterface {
  private const ENDPOINT      = 'https://api.openai.com/v1/chat/completions';
  public  const DEFAULT_MODEL = 'gpt-4o-mini';

  private int    $credentialId;
  private string $model;

  /** @var array<string, mixed>|null */
  private ?array $lastRequest = null;

  /** @var array<string, mixed> */
  private array $lastResponseMeta = [];

  public function __construct(int $credentialId, string $model = self::DEFAULT_MODEL) {
    $this->credentialId = $credentialId;
    $this->model        = $model !== '' ? $model : self::DEFAULT_MODEL;
  }

  public function generateText(string $system, array $messages, array $options = []): string|WP_Error {
    $key = $this->resolveKey();
    if (is_wp_error($key)) {
      return $key;
    }

    $this->lastResponseMeta = [];

    $chat = [];
    if ($system !== '') {
      $chat[] = ['role' => 'system', 'content' => $system];
    }
    foreach ($this->normalizeMessages($messages) as $message) {
      $chat[] = $message;
    }

    $body = [
      'model'    => $options['model'] ?? $this->model,
      'messages' => $chat,
    ];
    if (!empty($options['json'])) {
      $body['response_format'] = ['type' => 'json_object'];
    }

    $this->lastRequest = [
      'endpoint' => self::ENDPOINT,
      'headers'  => [
        'Authorization' => 'Bearer [redacted]',
        'content-type'  => 'application/json',
      ],
      'body'     => $body,
    ];

    $response = wp_remote_post(self::ENDPOINT, [
      // Big agent prompts (system + replayed read results) can push slow providers
      // past 60s (field trace: cURL 28 on the final round of a read loop).
      'timeout' => (int) apply_filters('fswa_ai_http_timeout', 120),
      'headers' => [
        'Authorization' => 'Bearer ' . $key,
        'content-type'  => 'application/json',
      ],
      'body'    => wp_json_encode($body),
    ]);

    if (is_wp_error($response)) {
      return $response;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $data = json_decode((string) wp_remote_retrieve_body($response), true);

    if ($code < 200 || $code >= 300) {
      $message = $data['error']['message'] ?? __('The AI provider returned an error.', 'flowsystems-webhook-actions');
      return new WP_Error('fswa_openai_error', $message, ['status' => $code]);
    }

    $finish                 = $data['choices'][0]['finish_reason'] ?? null;
    $this->lastResponseMeta = is_string($finish) && $finish !== '' ? ['finish_reason' => $finish] : [];

    $text = (string) ($data['choices'][0]['message']['content'] ?? '');

    return $text !== '' ? $text : new WP_Error('fswa_openai_empty', __('The AI provider returned no text.', 'flowsystems-webhook-actions'));
  }

  public function id(): string {
    return 'openai_byok';
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

  /**
   * Decrypt the vault-stored API key for this transport.
   *
   * @return string|WP_Error
   */
  private function resolveKey(): string|WP_Error {
    $row = (new CredentialRepository())->findWithSecret($this->credentialId);
    if (!$row) {
      return new WP_Error('fswa_ai_key_missing', __('The configured AI provider key was not found in the vault.', 'flowsystems-webhook-actions'));
    }
    $key = (new CredentialCipher())->decrypt((string) ($row['secret_ciphertext'] ?? ''));
    if ($key === null || $key === '') {
      return new WP_Error('fswa_ai_key_undecryptable', __('The AI provider key could not be decrypted.', 'flowsystems-webhook-actions'));
    }
    return $key;
  }

  /**
   * Coerce transcript turns into Chat Completions shape (role + string content).
   *
   * @param array<int, array{role:string,content:string}> $messages
   * @return array<int, array{role:string,content:string}>
   */
  private function normalizeMessages(array $messages): array {
    $out = [];
    foreach ($messages as $message) {
      $role  = ($message['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user';
      $out[] = ['role' => $role, 'content' => (string) ($message['content'] ?? '')];
    }
    return $out;
  }
}
