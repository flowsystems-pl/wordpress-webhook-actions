<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Repositories\ChainLinkRepository;

/**
 * Chain Dispatcher
 *
 * Listens on fswa_glue_post_dispatch (fires after a successful 2xx delivery)
 * and forwards to any chain-linked target webhooks. Each target receives the
 * full post-dispatch context as $args so its mapping/conditions can reach the
 * upstream response body and original payload.
 */
class ChainDispatcher {

  private Dispatcher $dispatcher;
  private ChainLinkRepository $linkRepository;

  public function __construct(Dispatcher $dispatcher) {
    $this->dispatcher     = $dispatcher;
    $this->linkRepository = new ChainLinkRepository();

    // Priority 20 — after Pro Code Glue post-dispatch (default 10) so any
    // side effects it performs are already in place.
    add_action('fswa_glue_post_dispatch', [$this, 'onPostDispatch'], 20, 7);
  }

  /**
   * @param int        $webhookId       Source webhook ID
   * @param string     $trigger         Source trigger event name
   * @param int        $responseCode    HTTP response code (2xx — fired only on success)
   * @param string     $responseBody    Raw response body
   * @param array      $payload         Sent payload
   * @param array      $webhook         Full source webhook config
   * @param array|null $originalPayload Pre-mapping payload
   */
  public function onPostDispatch(
    int $webhookId,
    string $trigger,
    int $responseCode,
    string $responseBody,
    array $payload,
    array $webhook,
    ?array $originalPayload
  ): void {
    $links = $this->linkRepository->findBySource($webhookId);
    if (empty($links)) {
      return;
    }

    $parsedBody = json_decode($responseBody, true);

    // Carry chain depth across hops for log inspection. Read from incoming
    // payload (if this source was itself chain-triggered).
    $incomingDepth = 0;
    $args0 = $payload['args'][0] ?? null;
    if (is_array($args0) && isset($args0['chain']['depth'])) {
      $incomingDepth = (int) $args0['chain']['depth'];
    }

    foreach ($links as $link) {
      $linkId        = (int) $link['id'];
      $chainId       = (int) $link['chain_id'];
      $syntheticTrig = 'fswa_chain_link:' . $linkId;

      $chainArgs = [[
        'source_webhook_id' => $webhookId,
        'source_trigger'    => $trigger,
        'response'          => [
          'code'     => $responseCode,
          'body'     => $parsedBody,
          'raw_body' => $responseBody,
        ],
        'payload'           => $payload,
        'original_payload'  => $originalPayload,
        'source_webhook'    => [
          'id'   => $webhookId,
          'name' => $webhook['name'] ?? '',
          'uuid' => $webhook['webhook_uuid'] ?? null,
        ],
        'chain'             => [
          'id'      => $chainId,
          'link_id' => $linkId,
          'depth'   => $incomingDepth + 1,
        ],
      ]];

      $this->dispatcher->dispatch($syntheticTrig, $chainArgs);
    }
  }
}
