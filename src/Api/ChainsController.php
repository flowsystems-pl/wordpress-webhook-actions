<?php

namespace FlowSystems\WebhookActions\Api;

defined('ABSPATH') || exit;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Response;
use WP_Error;
use FlowSystems\WebhookActions\Api\AuthHelper;
use FlowSystems\WebhookActions\Repositories\ChainRepository;
use FlowSystems\WebhookActions\Repositories\ChainLinkRepository;
use FlowSystems\WebhookActions\Repositories\WebhookRepository;
use FlowSystems\WebhookActions\Services\ActivityLogService;

class ChainsController extends WP_REST_Controller {
  protected $namespace = 'fswa/v1';
  protected $rest_base = 'chains';

  private ChainRepository $chainRepository;
  private ChainLinkRepository $linkRepository;
  private WebhookRepository $webhookRepository;
  private ActivityLogService $activityLog;

  public function __construct() {
    $this->chainRepository   = new ChainRepository();
    $this->linkRepository    = new ChainLinkRepository();
    $this->webhookRepository = new WebhookRepository();
    $this->activityLog       = new ActivityLogService();
  }

  public function registerRoutes(): void {
    register_rest_route($this->namespace, '/' . $this->rest_base, [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [$this, 'getItems'],
        'permission_callback' => [$this, 'readPermissions'],
      ],
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [$this, 'createItem'],
        'permission_callback' => [$this, 'writePermissions'],
      ],
    ]);

    register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [$this, 'getItem'],
        'permission_callback' => [$this, 'readPermissions'],
      ],
      [
        'methods'             => WP_REST_Server::EDITABLE,
        'callback'            => [$this, 'updateItem'],
        'permission_callback' => [$this, 'writePermissions'],
      ],
      [
        'methods'             => WP_REST_Server::DELETABLE,
        'callback'            => [$this, 'deleteItem'],
        'permission_callback' => [$this, 'writePermissions'],
      ],
    ]);

    register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)/links', [
      [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => [$this, 'getLinks'],
        'permission_callback' => [$this, 'readPermissions'],
      ],
      [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [$this, 'createLink'],
        'permission_callback' => [$this, 'writePermissions'],
      ],
    ]);

    register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)/links/(?P<linkId>[\d]+)', [
      'methods'             => WP_REST_Server::DELETABLE,
      'callback'            => [$this, 'deleteLink'],
      'permission_callback' => [$this, 'writePermissions'],
    ]);
  }

  public function readPermissions($request): bool|WP_Error {
    return AuthHelper::dualAuth($request, AuthHelper::SCOPE_READ);
  }

  public function writePermissions($request): bool|WP_Error {
    return AuthHelper::dualAuth($request, AuthHelper::SCOPE_FULL);
  }

  public function getItems($request): WP_REST_Response {
    $chains   = $this->chainRepository->getAll();
    $members  = $this->chainRepository->getMembersByChain();
    $allLinks = $this->linkRepository->getAll();

    $linksByChain = [];
    foreach ($allLinks as $l) {
      $linksByChain[(int) $l['chain_id']][] = $l;
    }

    foreach ($chains as &$chain) {
      $cid = (int) $chain['id'];
      $chain['member_webhook_ids'] = array_map('intval', $members[$cid] ?? []);
      $chain['links']              = $linksByChain[$cid] ?? [];
    }
    unset($chain);

    return rest_ensure_response($chains);
  }

  public function getItem($request) {
    $id    = (int) $request->get_param('id');
    $chain = $this->chainRepository->find($id);
    if (!$chain) {
      return new WP_Error('rest_chain_not_found', __('Chain not found.', 'flowsystems-webhook-actions'), ['status' => 404]);
    }
    $chain['links']              = $this->linkRepository->findByChain($id);
    $members                     = $this->chainRepository->getMembersByChain();
    $chain['member_webhook_ids'] = array_map('intval', $members[$id] ?? []);
    return rest_ensure_response($chain);
  }

  public function createItem($request) {
    $name        = sanitize_text_field((string) $request->get_param('name'));
    $description = $request->get_param('description');
    $description = $description === null ? null : sanitize_textarea_field((string) $description);

    if ($name === '') {
      return new WP_Error('rest_missing_name', __('Chain name is required.', 'flowsystems-webhook-actions'), ['status' => 400]);
    }
    if ($this->chainRepository->findByName($name) !== null) {
      return new WP_Error('rest_chain_name_exists', __('A chain with that name already exists.', 'flowsystems-webhook-actions'), ['status' => 409]);
    }

    $id = $this->chainRepository->create(['name' => $name, 'description' => $description]);
    if ($id === false) {
      return new WP_Error('rest_chain_create_failed', __('Failed to create chain.', 'flowsystems-webhook-actions'), ['status' => 500]);
    }
    $chain                       = $this->chainRepository->find($id);
    $chain['links']              = [];
    $chain['member_webhook_ids'] = [];

    $this->activityLog->log('chain.created', 'chain', $id, $chain['name'] ?? null, [
      'new' => ['name' => $chain['name'] ?? null, 'description' => $chain['description'] ?? null],
    ]);

    return rest_ensure_response($chain);
  }

  public function updateItem($request) {
    $id    = (int) $request->get_param('id');
    $chain = $this->chainRepository->find($id);
    if (!$chain) {
      return new WP_Error('rest_chain_not_found', __('Chain not found.', 'flowsystems-webhook-actions'), ['status' => 404]);
    }

    $data = [];
    if ($request->has_param('name')) {
      $name = sanitize_text_field((string) $request->get_param('name'));
      if ($name === '') {
        return new WP_Error('rest_missing_name', __('Chain name is required.', 'flowsystems-webhook-actions'), ['status' => 400]);
      }
      $conflict = $this->chainRepository->findByName($name);
      if ($conflict !== null && (int) $conflict['id'] !== $id) {
        return new WP_Error('rest_chain_name_exists', __('A chain with that name already exists.', 'flowsystems-webhook-actions'), ['status' => 409]);
      }
      $data['name'] = $name;
    }
    if ($request->has_param('description')) {
      $desc = $request->get_param('description');
      $data['description'] = $desc === null ? null : sanitize_textarea_field((string) $desc);
    }

    $oldValues = array_intersect_key($chain, $data);

    $this->chainRepository->update($id, $data);
    $updated                       = $this->chainRepository->find($id);
    $updated['links']              = $this->linkRepository->findByChain($id);
    $members                       = $this->chainRepository->getMembersByChain();
    $updated['member_webhook_ids'] = array_map('intval', $members[$id] ?? []);

    $this->activityLog->log('chain.updated', 'chain', $id, $updated['name'] ?? null, [
      'old' => $oldValues,
      'new' => $data,
    ]);

    return rest_ensure_response($updated);
  }

  public function deleteItem($request) {
    $id    = (int) $request->get_param('id');
    $chain = $this->chainRepository->find($id);
    if (!$chain) {
      return new WP_Error('rest_chain_not_found', __('Chain not found.', 'flowsystems-webhook-actions'), ['status' => 404]);
    }
    $linksRemoved = $this->linkRepository->deleteByChain($id);
    $this->chainRepository->delete($id);

    $this->activityLog->log('chain.deleted', 'chain', $id, $chain['name'] ?? null, [
      'old' => ['name' => $chain['name'] ?? null, 'description' => $chain['description'] ?? null, 'links_removed' => $linksRemoved],
    ]);

    return rest_ensure_response(['deleted' => true, 'id' => $id, 'links_removed' => $linksRemoved]);
  }

  public function getLinks($request): WP_REST_Response {
    $id = (int) $request->get_param('id');
    return rest_ensure_response($this->linkRepository->findByChain($id));
  }

  public function createLink($request) {
    $chainId = (int) $request->get_param('id');
    $chain   = $this->chainRepository->find($chainId);
    if (!$chain) {
      return new WP_Error('rest_chain_not_found', __('Chain not found.', 'flowsystems-webhook-actions'), ['status' => 404]);
    }

    $sourceId = (int) $request->get_param('source_webhook_id');
    $targetId = (int) $request->get_param('target_webhook_id');
    if ($sourceId <= 0 || $targetId <= 0) {
      return new WP_Error('rest_invalid_params', __('Both source_webhook_id and target_webhook_id are required.', 'flowsystems-webhook-actions'), ['status' => 400]);
    }
    if ($sourceId === $targetId) {
      return new WP_Error('rest_self_link', __('A webhook cannot trigger itself.', 'flowsystems-webhook-actions'), ['status' => 409]);
    }
    if (!$this->webhookRepository->find($sourceId)) {
      return new WP_Error('rest_invalid_source', __('Source webhook does not exist.', 'flowsystems-webhook-actions'), ['status' => 400]);
    }
    if (!$this->webhookRepository->find($targetId)) {
      return new WP_Error('rest_invalid_target', __('Target webhook does not exist.', 'flowsystems-webhook-actions'), ['status' => 400]);
    }

    if ($this->linkRepository->wouldCreateCycle($sourceId, $targetId)) {
      return new WP_Error('rest_chain_cycle', __('That link would create a cycle in the chain graph.', 'flowsystems-webhook-actions'), ['status' => 409]);
    }

    $linkId = $this->linkRepository->create($chainId, $sourceId, $targetId);
    if ($linkId === false) {
      return new WP_Error('rest_chain_link_create_failed', __('Failed to create chain link (it may already exist).', 'flowsystems-webhook-actions'), ['status' => 409]);
    }

    $this->activityLog->log('chain.link_added', 'chain', $chainId, $chain['name'] ?? null, [
      'new' => ['source_webhook_id' => $sourceId, 'target_webhook_id' => $targetId],
    ]);

    return rest_ensure_response($this->linkRepository->find($linkId));
  }

  public function deleteLink($request) {
    $linkId = (int) $request->get_param('linkId');
    $link   = $this->linkRepository->find($linkId);
    if (!$link) {
      return new WP_Error('rest_chain_link_not_found', __('Chain link not found.', 'flowsystems-webhook-actions'), ['status' => 404]);
    }
    $chainId = (int) $link['chain_id'];
    $chain   = $this->chainRepository->find($chainId);
    $this->linkRepository->delete($linkId);
    $chainDeleted = $this->linkRepository->cleanupEmptyChains([$chainId]) > 0;

    $this->activityLog->log('chain.link_deleted', 'chain', $chainId, $chain['name'] ?? null, [
      'old' => ['source_webhook_id' => (int) $link['source_webhook_id'], 'target_webhook_id' => (int) $link['target_webhook_id']],
    ]);

    return rest_ensure_response([
      'deleted'       => true,
      'id'            => $linkId,
      'chain_deleted' => $chainDeleted,
    ]);
  }
}
