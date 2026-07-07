<?php

namespace FlowSystems\WebhookActions\Services\Ai;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Repositories\CredentialRepository;
use FlowSystems\WebhookActions\Services\CredentialCipher;
use WP_Error;

/**
 * Bring-your-own-key transport for Google's Gemini (Generative Language) API.
 *
 * Used when the WordPress AI Client is not configured. The API key is read from
 * the credentials vault (decrypted at call time only) and never leaves the
 * server or appears in a response.
 */
class GoogleTransport implements LlmTransportInterface {
  private const ENDPOINT_TEMPLATE = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';
  public  const DEFAULT_MODEL     = 'gemini-2.5-flash';

  private int    $credentialId;
  private string $model;

  /** @var array<string, mixed>|null */
  private ?array $lastRequest = null;

  public function __construct(int $credentialId, string $model = self::DEFAULT_MODEL) {
    $this->credentialId = $credentialId;
    $this->model        = $model !== '' ? $model : self::DEFAULT_MODEL;
  }

  public function generateText(string $system, array $messages, array $options = []): string|WP_Error {
    $key = $this->resolveKey();
    if (is_wp_error($key)) {
      return $key;
    }

    $model = (string) ($options['model'] ?? $this->model);

    $body = [
      'contents' => $this->normalizeMessages($messages),
    ];
    if ($system !== '') {
      $body['systemInstruction'] = ['parts' => [['text' => $system]]];
    }
    $generation = [];
    if (isset($options['temperature'])) {
      $generation['temperature'] = (float) $options['temperature'];
    }
    if (!empty($options['json'])) {
      // Force raw JSON output — without this Gemini tends to wrap the envelope
      // in prose and a ```json fence, which breaks strict parsing downstream.
      $generation['responseMimeType'] = 'application/json';
    }
    if ($generation !== []) {
      $body['generationConfig'] = $generation;
    }

    $endpoint = sprintf(self::ENDPOINT_TEMPLATE, rawurlencode($model));

    $this->lastRequest = [
      'endpoint' => $endpoint,
      'headers'  => [
        'x-goog-api-key' => '[redacted]',
        'content-type'   => 'application/json',
      ],
      'body'     => $body,
    ];

    $response = wp_remote_post($endpoint, [
      // Big agent prompts (system + replayed read results) can push slow providers
      // past 60s (field trace: cURL 28 on the final round of a read loop).
      'timeout' => (int) apply_filters('fswa_ai_http_timeout', 120),
      'headers' => [
        'x-goog-api-key' => $key,
        'content-type'   => 'application/json',
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
      return new WP_Error('fswa_google_error', $message, ['status' => $code]);
    }

    // Concatenate the text parts of the first candidate.
    $text = '';
    foreach ((array) ($data['candidates'][0]['content']['parts'] ?? []) as $part) {
      $text .= (string) ($part['text'] ?? '');
    }

    return $text !== '' ? $text : new WP_Error('fswa_google_empty', __('The AI provider returned no text.', 'flowsystems-webhook-actions'));
  }

  public function id(): string {
    return 'google_byok';
  }

  public function model(): string {
    return $this->model;
  }

  public function lastRequest(): ?array {
    return $this->lastRequest;
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
   * Coerce transcript turns into Gemini `contents` shape. Gemini uses the role
   * name 'model' for assistant turns and 'user' for everything else.
   *
   * @param array<int, array{role:string,content:string}> $messages
   * @return array<int, array{role:string,parts:array<int,array{text:string}>}>
   */
  private function normalizeMessages(array $messages): array {
    $out = [];
    foreach ($messages as $message) {
      $role  = ($message['role'] ?? 'user') === 'assistant' ? 'model' : 'user';
      $out[] = ['role' => $role, 'parts' => [['text' => (string) ($message['content'] ?? '')]]];
    }
    return $out;
  }
}
