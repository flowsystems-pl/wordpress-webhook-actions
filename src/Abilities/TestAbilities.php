<?php

namespace FlowSystems\WebhookActions\Abilities;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Repositories\WebhookRepository;
use FlowSystems\WebhookActions\Repositories\SchemaRepository;
use FlowSystems\WebhookActions\Repositories\CredentialRepository;
use FlowSystems\WebhookActions\Services\PayloadTransformer;
use FlowSystems\WebhookActions\Services\LogService;
use FlowSystems\WebhookActions\Services\Dispatcher;
use FlowSystems\WebhookActions\Services\QueueService;
use FlowSystems\WebhookActions\Services\WPHttpTransport;
use FlowSystems\WebhookActions\Services\CredentialCipher;
use WP_Error;

/**
 * Test / validate ability handlers: probe_endpoint (guarded empty-body call to
 * check reachability + auth) and test_dispatch (a REAL synchronous delivery to
 * verify the integration end to end). Both make live outbound HTTP calls, so
 * everything here is rate-limited, SSRF-guarded, size-capped and secret-redacting
 * — the raw vault secret is injected server-side and never returned.
 */
class TestAbilities {
  use AbilityErrors;

  /** Max bytes of a probe response body returned to the agent. */
  private const PROBE_BODY_LIMIT = 4096;

  /** Probe calls allowed per rolling minute (abuse guard). */
  private const PROBE_RATE_PER_MIN = 10;

  /**
   * Guarded outbound test call. Reuses the same wp_remote_request path as
   * dispatch, with an SSRF guard, a rate limit, body-size cap, and vault-secret
   * injection by credential id (the raw secret never leaves the server).
   */
  public function probeEndpoint(array $input): array|WP_Error {
    $url    = esc_url_raw((string) ($input['url'] ?? ''));
    $method = strtoupper((string) ($input['method'] ?? ''));
    $authId = (int) ($input['auth_credential_id'] ?? 0);

    // Whether the caller explicitly asked for an unsafe method (vs. inheriting the
    // webhook's own configured method, which is pre-approved for the webhook the
    // user is building).
    $methodExplicit = $method !== '';

    // Probe a webhook we already created: reuse its endpoint URL, credential and
    // HTTP method so it validates the endpoint the way the webhook will actually
    // call it (e.g. a POST-only receiver correctly, instead of a false GET 404).
    // An empty body is sent — a real delivery with the payload is test_dispatch.
    $webhookId = (int) ($input['webhook_id'] ?? 0);
    if ($url === '' && $webhookId > 0) {
      $webhook = (new WebhookRepository())->find($webhookId);
      if (!$webhook) {
        return $this->notFound();
      }
      $url = esc_url_raw((string) ($webhook['endpoint_url'] ?? ''));
      if ($authId === 0 && !empty($webhook['auth_credential_id'])) {
        $authId = (int) $webhook['auth_credential_id'];
      }
      if ($method === '') {
        $method = strtoupper((string) ($webhook['http_method'] ?? 'GET'));
      }
    }

    if ($method === '') {
      $method = 'GET';
    }

    if ($url === '') {
      return $this->invalid(__('A url or webhook_id is required.', 'flowsystems-webhook-actions'));
    }

    // SSRF guard: WordPress rejects loopback / private / reserved hosts unless a
    // filter opts in. This also blocks link-local cloud-metadata endpoints.
    if (!wp_http_validate_url($url)) {
      return new WP_Error('fswa_probe_blocked', __('That URL is not allowed (private, reserved or invalid host).', 'flowsystems-webhook-actions'), ['status' => 422]);
    }

    // A probe sends an EMPTY body, so GET/HEAD/POST can't create data and run
    // freely. Only genuinely destructive verbs (PUT/PATCH/DELETE) — which a
    // body-less call can still mutate or delete — need confirmation, and only when
    // the method was caller-specified on an arbitrary URL. A webhook's own method
    // is pre-approved: the user is building that webhook.
    $destructive = $methodExplicit && in_array($method, ['PUT', 'PATCH', 'DELETE'], true);
    if ($destructive && empty($input['confirmed'])) {
      return new WP_Error('fswa_probe_confirm', __('Destructive probe methods (PUT, PATCH, DELETE) require confirmation.', 'flowsystems-webhook-actions'), ['status' => 412]);
    }

    if (!$this->probeRateOk()) {
      return new WP_Error('fswa_probe_rate', __('Too many probe calls — try again in a minute.', 'flowsystems-webhook-actions'), ['status' => 429]);
    }

    $headers = [];
    foreach ((array) ($input['headers'] ?? []) as $k => $v) {
      $headers[sanitize_text_field((string) $k)] = sanitize_text_field((string) $v);
    }

    // Inject the vault credential without ever exposing it to the caller.
    if ($authId > 0) {
      $injected = $this->resolveCredentialHeader($authId);
      if (is_wp_error($injected)) {
        return $injected;
      }
      $headers = array_merge($headers, $injected);
    }

    $args = [
      'method'              => $method,
      'headers'             => $headers,
      'timeout'             => 8,
      'redirection'         => 2,
      'limit_response_size' => self::PROBE_BODY_LIMIT,
      'user-agent'          => 'WordPress/FlowSystemsWebhookActions-AIProbe',
    ];
    if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
      // Send a minimal empty JSON body when none is supplied, so POST-only
      // receivers accept the request instead of rejecting an empty payload.
      $args['headers']['Content-Type'] = 'application/json';
      $args['body']                    = wp_json_encode(isset($input['body']) ? $input['body'] : new \stdClass());
    }

    // Honor the same HTTP-args customizations as real deliveries (proxy, custom
    // CA, timeouts — and the local-dev sslverify override for self-signed hosts),
    // so a probe reaches the endpoint the way the webhook actually will. Without
    // this, an internal automation targeting a self-signed local host fails the
    // probe with cURL 60 even though dispatch (which applies this filter) works.
    $args = apply_filters('fswa_http_args', $args, $url, []);

    $response = wp_remote_request($url, $args);
    if (is_wp_error($response)) {
      return ['ok' => false, 'error' => $response->get_error_message()];
    }

    $body = $this->truncate((string) wp_remote_retrieve_body($response), self::PROBE_BODY_LIMIT);

    return [
      'ok'      => true,
      'status'  => (int) wp_remote_retrieve_response_code($response),
      'headers' => $this->redactHeaders((array) wp_remote_retrieve_headers($response)->getAll()),
      'body'    => $this->redactBody($body, $args['headers']),
    ];
  }

  /**
   * Synchronous test delivery. Mirrors WebhooksController::testItem for the
   * custom/captured payload paths, sending immediately so the agent sees a result.
   */
  public function testDispatch(array $input): array|WP_Error {
    $id       = (int) ($input['webhook_id'] ?? 0);
    $repo     = new WebhookRepository();
    $webhook  = $repo->find($id);
    if (!$webhook) {
      return $this->notFound();
    }

    $triggers = $webhook['triggers'] ?? [];
    $trigger  = (string) ($input['trigger'] ?? ($triggers[0] ?? ''));
    if ($trigger === '') {
      return $this->invalid(__('The webhook has no triggers; provide one.', 'flowsystems-webhook-actions'));
    }

    $mappingApplied  = false;
    $originalPayload = null;
    if (isset($input['payload']) && is_array($input['payload'])) {
      $payload = $input['payload'];
    } else {
      // Use this webhook's own captured example, or — when reuse is enabled — one
      // captured for the same trigger on another webhook (trigger-global shape).
      $example = (new SchemaRepository())->resolveExample($id, $trigger)['example'] ?? null;
      if (empty($example)) {
        return new WP_Error('fswa_no_payload', __('No payload provided and no captured example exists yet for this trigger.', 'flowsystems-webhook-actions'), ['status' => 422]);
      }
      $decoded = is_string($example) ? (json_decode($example, true) ?: []) : (array) $example;
      // Apply the stored field mapping so the test matches real deliveries.
      $mapped          = (new PayloadTransformer())->applyStoredMapping($id, $trigger, $decoded);
      $payload         = $mapped['payload'];
      $mappingApplied  = $mapped['mapping_applied'];
      $originalPayload = $mappingApplied ? $decoded : null;
    }

    // Apply pre-dispatch Code Glue exactly like real deliveries (the sync
    // branch of dispatch() and processJob()) — sendToWebhook() expects an
    // already-glued payload, so without this a test silently skips the
    // webhook's snippet and can't reproduce production behaviour.
    $preGlue = $payload;
    $glued   = apply_filters('fswa_webhook_payload', $payload, $id, $trigger, $originalPayload ?: null);
    $payload = is_array($glued) ? $glued : $payload;
    $glueApplied = $payload !== $preGlue;
    if ($glueApplied && $originalPayload === null) {
      $originalPayload = $preGlue;
    }

    $logService = new LogService();
    $logId      = $logService->logPending($id, $trigger, $payload, $originalPayload, $mappingApplied || $glueApplied);

    $dispatcher = new Dispatcher(new WPHttpTransport(), new QueueService());
    $dispatcher->sendToWebhook($webhook, $payload, $trigger, $logId, 0, true, null);

    $log = $logService->getRepository()->find($logId);

    // response_body may come back json-decoded (array) from the repository.
    $body = $log['response_body'] ?? '';

    return [
      'log_id'          => $logId,
      'status'          => $log['status'] ?? null,
      'http_code'       => $log['http_code'] ?? null,
      'mapping_applied' => $mappingApplied,
      'glue_applied'    => $glueApplied,
      'response'        => $this->truncate(is_string($body) ? $body : (string) wp_json_encode($body), self::PROBE_BODY_LIMIT),
    ];
  }

  // ===================================================================
  // Helpers
  // ===================================================================

  /**
   * Resolve a vault credential into outgoing header(s) — internal only.
   *
   * @return array<string, string>|WP_Error
   */
  private function resolveCredentialHeader(int $credentialId): array|WP_Error {
    $row = (new CredentialRepository())->findWithSecret($credentialId);
    if (!$row) {
      return $this->invalid(__('Credential not found.', 'flowsystems-webhook-actions'));
    }
    $secret = (new CredentialCipher())->decrypt((string) ($row['secret_ciphertext'] ?? ''));
    if ($secret === null) {
      return new WP_Error('fswa_credential_undecryptable', __('Credential could not be decrypted.', 'flowsystems-webhook-actions'), ['status' => 500]);
    }

    $header = $row['header_name'] ?: 'Authorization';
    $value  = match ($row['type']) {
      'bearer' => 'Bearer ' . $secret,
      'basic'  => 'Basic ' . base64_encode($secret),
      default  => $secret,
    };

    return [$header => $value];
  }

  private function probeRateOk(): bool {
    $key   = 'fswa_probe_rl_' . gmdate('YmdHi');
    $count = (int) get_transient($key);
    if ($count >= self::PROBE_RATE_PER_MIN) {
      return false;
    }
    set_transient($key, $count + 1, MINUTE_IN_SECONDS);
    return true;
  }

  /**
   * Drop Authorization-style headers from a returned header set.
   *
   * @param array<string, mixed> $headers
   * @return array<string, mixed>
   */
  private function redactHeaders(array $headers): array {
    foreach (array_keys($headers) as $key) {
      if (preg_match('/authorization|cookie|set-cookie|api[-_]?key|token/i', (string) $key)) {
        $headers[$key] = '***';
      }
    }
    return $headers;
  }

  /**
   * Redact any secret we sent (the injected auth header values) from a response
   * body, in case a misconfigured target reflects our request headers back.
   *
   * @param array<string, mixed> $sentHeaders The outgoing request headers.
   */
  private function redactBody(string $body, array $sentHeaders): string {
    foreach ($sentHeaders as $key => $value) {
      $value = (string) $value;
      if ($value !== '' && preg_match('/authorization|cookie|api[-_]?key|token|secret/i', (string) $key)) {
        $body = str_replace($value, '***', $body);
      }
    }
    return $body;
  }

  private function truncate(string $value, int $limit): string {
    return strlen($value) > $limit ? substr($value, 0, $limit) . '…' : $value;
  }
}
