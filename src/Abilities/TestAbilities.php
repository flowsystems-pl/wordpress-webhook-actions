<?php

namespace FlowSystems\WebhookActions\Abilities;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Repositories\WebhookRepository;
use FlowSystems\WebhookActions\Repositories\CredentialRepository;
use FlowSystems\WebhookActions\Services\ExampleResolver;
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
  /**
   * Render a rule and send it for real to every channel it names.
   */
  public function testNotificationRule(array $input): array|WP_Error {
    $rule = (new \FlowSystems\WebhookActions\Repositories\NotificationRuleRepository())->find((int) ($input['id'] ?? 0));
    if (!$rule) {
      return $this->notFound();
    }
    $preview = (new \FlowSystems\WebhookActions\Services\Notifications\PreviewContext())->build(
      $rule['event'],
      $rule['webhook_id'] ? (int) $rule['webhook_id'] : null,
      (int) ($input['log_id'] ?? 0) ?: null
    );
    $message = \FlowSystems\WebhookActions\Services\Notifications\MessageBuilder::asTest(
      (new \FlowSystems\WebhookActions\Services\Notifications\MessageBuilder())->buildFromRoots($rule['event'], $rule['template'], $preview['roots'], $preview['ctx'], $rule)
    );

    $channels = new \FlowSystems\WebhookActions\Repositories\NotificationChannelRepository();
    $sender   = new \FlowSystems\WebhookActions\Services\Notifications\NotificationSender();
    $results  = [];
    foreach ($channels->findManyWithSecrets($rule['channel_ids']) as $channelId => $channel) {
      $outcome = $sender->deliver($message, $channel);
      if ($outcome === true) {
        $channels->recordSent($channelId);
      } else {
        $channels->recordError($channelId, $outcome->get_error_message());
      }
      $results[] = ['channel_id' => $channelId, 'channel' => $channel['name'], 'sent' => $outcome === true, 'error' => $outcome === true ? null : $outcome->get_error_message()];
    }

    return [
      'results' => $results,
      'rendered' => ['subject' => $message['subject'], 'title' => $message['title'], 'body' => $message['body'], 'short' => $message['short']],
      'source'   => $preview['source'],
    ];
  }

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
      // The verb actually probed: a 4xx means something very different for a
      // body-writing method (the empty body was rejected, so this proves only
      // reachability) than for a GET. Callers cannot infer it — the method is
      // often inherited from the webhook rather than passed in.
      'method'  => $method,
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
    $payloadSource   = 'provided';
    $libraryNote     = null;
    if (isset($input['payload']) && is_array($input['payload'])) {
      $payload = $input['payload'];
    } else {
      // This webhook's own captured example, one captured for the same trigger
      // on another webhook (the do_action shape is trigger-global), or — for
      // hosted-AI installs — our hosted reference payload.
      //
      // Through ExampleResolver rather than SchemaRepository directly, because
      // the rest of a library-backed build already assumes the library counts
      // here: the prompt tells the agent to finish with test_dispatch (the only
      // proof a reference payload survives contact with this site), and
      // PlanExecutor::withCaptureStep() skips appending a "fire the event" step
      // precisely because the resolver found an example. Reading the repository
      // instead contradicted both, so every library-backed build dead-ended at
      // its own verification step with nothing left to do but abandon it.
      $resolved = (new ExampleResolver())->resolve($id, $trigger);
      $example  = $resolved['example'] ?? null;
      if (empty($example)) {
        return new WP_Error('fswa_no_payload', __('No payload provided and no captured example exists yet for this trigger.', 'flowsystems-webhook-actions'), ['status' => 422]);
      }
      $payloadSource = (string) ($resolved['source'] ?? 'own');
      $libraryNote   = $payloadSource === 'library' ? $this->libraryNote($resolved) : null;
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

    $result = [
      'log_id'          => $logId,
      'status'          => $log['status'] ?? null,
      'http_code'       => $log['http_code'] ?? null,
      // Which payload actually went on the wire. The agent cannot infer it —
      // "own" and "library" produce an identical 2xx — and the difference is
      // what it has to tell the user afterwards.
      'payload_source'  => $payloadSource,
      'mapping_applied' => $mappingApplied,
      'glue_applied'    => $glueApplied,
      'response'        => $this->truncate(is_string($body) ? $body : (string) wp_json_encode($body), self::PROBE_BODY_LIMIT),
    ];

    if ($libraryNote !== null) {
      $result['payload_note'] = $libraryNote;
    }

    return $result;
  }

  // ===================================================================
  // Helpers
  // ===================================================================

  /**
   * What the agent must pass on to the user after a test that ran on our
   * fixture data rather than theirs.
   *
   * A 2xx here proves the endpoint accepts the SHAPE the mapping produces. It
   * says nothing about the values, because the values were ours — and on a form
   * trigger the field keys under a site-defined container are the user's own.
   * Without this line an agent reports "verified" and means less than the user
   * will hear.
   *
   * @param array<string, mixed> $resolved An ExampleResolver::resolve() result.
   */
  private function libraryNote(array $resolved): string {
    $from    = (array) ($resolved['library']['captured_from']['plugins'] ?? []);
    $version = '';
    foreach ($from as $slug => $ver) {
      $version = $slug . ' ' . $ver;
      break;
    }

    return $version === ''
      ? __('This test sent our reference payload, not data from this site: a 2xx proves the endpoint accepts the shape the mapping produces, not that the field VALUES are right. Tell the user, and ask them to fire the real event once if those values matter.', 'flowsystems-webhook-actions')
      : sprintf(
        /* translators: %s: plugin slug and version the reference payload was captured on, e.g. "contact-form-7 6.1.7". */
        __('This test sent our reference payload, captured on %s, not data from this site: a 2xx proves the endpoint accepts the shape the mapping produces, not that the field VALUES are right. Tell the user, and ask them to fire the real event once if those values matter.', 'flowsystems-webhook-actions'),
        $version
      );
  }

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
