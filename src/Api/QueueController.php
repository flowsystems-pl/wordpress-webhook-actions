<?php

namespace FlowSystems\WebhookActions\Api;

defined('ABSPATH') || exit;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Response;
use WP_REST_Request;
use WP_Error;
use FlowSystems\WebhookActions\Services\QueueService;
use FlowSystems\WebhookActions\Services\Dispatcher;
use FlowSystems\WebhookActions\Services\WPHttpTransport;
use FlowSystems\WebhookActions\Repositories\WebhookRepository;

class QueueController extends WP_REST_Controller {
  protected $namespace = 'fswa/v1';
  protected $rest_base = 'queue';

  private QueueService $queueService;

  public function __construct() {
    $this->queueService = new QueueService();
  }

  public function registerRoutes(): void {
    // List queue jobs
    register_rest_route($this->namespace, '/' . $this->rest_base, [
      [
        'methods' => WP_REST_Server::READABLE,
        'callback' => [$this, 'getItems'],
        'permission_callback' => [$this, 'permissionsCheck'],
        'args' => [
          'status' => [
            'type' => 'string',
            'enum' => ['pending', 'processing', 'completed', 'failed', 'permanently_failed'],
          ],
          'webhook_id' => [
            'type' => 'integer',
          ],
          'event_uuid' => [
            'type' => 'string',
          ],
          'target_url' => [
            'type' => 'string',
          ],
          'date_from' => [
            'type' => 'string',
          ],
          'date_to' => [
            'type' => 'string',
          ],
          'per_page' => [
            'type' => 'integer',
            'default' => 50,
            'minimum' => 1,
            'maximum' => 100,
          ],
          'page' => [
            'type' => 'integer',
            'default' => 1,
            'minimum' => 1,
          ],
        ],
      ],
    ]);

    // Get queue statistics
    register_rest_route($this->namespace, '/' . $this->rest_base . '/stats', [
      [
        'methods' => WP_REST_Server::READABLE,
        'callback' => [$this, 'getStats'],
        'permission_callback' => [$this, 'permissionsCheck'],
      ],
    ]);

    // Execute a specific job immediately
    register_rest_route($this->namespace, '/' . $this->rest_base . '/execute', [
      [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => [$this, 'executeItem'],
        'permission_callback' => [$this, 'permissionsCheck'],
        'args' => [
          'id' => [
            'required' => true,
            'type' => 'integer',
          ],
        ],
      ],
    ]);

    // Delete a job from the queue
    register_rest_route($this->namespace, '/' . $this->rest_base . '/delete', [
      [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => [$this, 'deleteItem'],
        'permission_callback' => [$this, 'permissionsCheck'],
        'args' => [
          'id' => [
            'required' => true,
            'type' => 'integer',
          ],
        ],
      ],
    ]);

    // Force retry a failed job
    register_rest_route($this->namespace, '/' . $this->rest_base . '/retry', [
      [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => [$this, 'retryItem'],
        'permission_callback' => [$this, 'permissionsCheck'],
        'args' => [
          'id' => [
            'required' => true,
            'type' => 'integer',
          ],
        ],
      ],
    ]);
  }

  public function permissionsCheck(WP_REST_Request $_request): bool {
    return current_user_can('manage_options');
  }

  /**
   * List queue jobs
   */
  public function getItems($request): WP_REST_Response {
    $perPage = (int) $request->get_param('per_page');
    $page = (int) $request->get_param('page');
    $offset = ($page - 1) * $perPage;

    $filters = [];

    if ($request->get_param('status')) {
      $filters['status'] = $request->get_param('status');
    }

    if ($request->get_param('webhook_id')) {
      $filters['webhook_id'] = (int) $request->get_param('webhook_id');
    }

    if ($request->get_param('event_uuid')) {
      $filters['event_uuid'] = sanitize_text_field($request->get_param('event_uuid'));
    }

    if ($request->get_param('target_url')) {
      $filters['target_url'] = sanitize_text_field($request->get_param('target_url'));
    }

    if ($request->get_param('date_from')) {
      $filters['date_from'] = sanitize_text_field($request->get_param('date_from'));
    }

    if ($request->get_param('date_to')) {
      $filters['date_to'] = sanitize_text_field($request->get_param('date_to'));
    }

    $jobs = $this->queueService->getJobs($filters, $perPage, $offset);
    $total = $this->queueService->countJobs($filters);

    // Enrich jobs with webhook information
    $items = array_map(function ($job) {
      return $this->formatJob($job);
    }, $jobs);

    $response = rest_ensure_response($items);

    // Add pagination headers
    $response->header('X-WP-Total', $total);
    $response->header('X-WP-TotalPages', ceil($total / $perPage));

    return $response;
  }

  /**
   * Get queue statistics
   */
  /** @noinspection PhpUnusedParameterInspection */
  public function getStats(WP_REST_Request $_request): WP_REST_Response {
    $stats = $this->queueService->getStats();

    return rest_ensure_response($stats);
  }

  /**
   * Execute a job immediately
   */
  public function executeItem($request): WP_REST_Response|WP_Error {
    $jobId = (int) $request->get_param('id');

    $job = $this->queueService->getJob($jobId);

    if (!$job) {
      return new WP_Error(
        'rest_job_not_found',
        __('Queue job not found.', 'flowsystems-webhook-actions'),
        ['status' => 404]
      );
    }

    if ($job['status'] === 'processing') {
      return new WP_Error(
        'rest_job_processing',
        __('Job is currently being processed.', 'flowsystems-webhook-actions'),
        ['status' => 409]
      );
    }

    if ($job['status'] === 'completed') {
      return new WP_Error(
        'rest_job_completed',
        __('Job has already been completed.', 'flowsystems-webhook-actions'),
        ['status' => 409]
      );
    }

    // Decode payload
    $jobData = json_decode($job['payload'], true);
    if (!$jobData || !isset($jobData['webhook']) || !isset($jobData['payload'])) {
      return new WP_Error(
        'rest_job_invalid',
        __('Job has invalid payload data.', 'flowsystems-webhook-actions'),
        ['status' => 500]
      );
    }

    // Lock the job
    $lockId = wp_generate_uuid4();
    if (!$this->queueService->lockJob($jobId, $lockId)) {
      return new WP_Error(
        'rest_job_lock_failed',
        __('Could not lock job for processing.', 'flowsystems-webhook-actions'),
        ['status' => 500]
      );
    }

    $webhook = $jobData['webhook'];
    $payload = $jobData['payload'];
    $trigger = $job['trigger_name'];
    $logId = !empty($job['log_id']) ? (int) $job['log_id'] : (!empty($jobData['log_id']) ? (int) $jobData['log_id'] : null);
    $attempts = (int) ($job['attempts'] ?? 0);

    $transport = new WPHttpTransport();
    $dispatcher = new Dispatcher($transport, $this->queueService);

    $result = $dispatcher->sendToWebhook($webhook, $payload, $trigger, $logId, $attempts);

    if ($result['success']) {
      $this->queueService->markCompleted($jobId);
      return rest_ensure_response([
        'success' => true,
        'message' => __('Job executed successfully.', 'flowsystems-webhook-actions'),
      ]);
    }

    if ($result['shouldRetry']) {
      $reschedule = $this->queueService->rescheduleWithBackoff($jobId);
      return rest_ensure_response([
        'success' => false,
        'rescheduled' => $reschedule['rescheduled'],
        'message' => $reschedule['rescheduled']
          ? __('Job failed and has been rescheduled for retry.', 'flowsystems-webhook-actions')
          : __('Job failed and has exceeded maximum retry attempts.', 'flowsystems-webhook-actions'),
      ]);
    }

    // Non-retryable failure (4xx, bad config, etc.)
    $this->queueService->markPermanentlyFailed($jobId);
    return rest_ensure_response([
      'success' => false,
      'rescheduled' => false,
      'permanently_failed' => true,
      'message' => __('Job failed with a non-retryable error.', 'flowsystems-webhook-actions'),
    ]);
  }

  /**
   * Delete a job from the queue
   */
  public function deleteItem($request): WP_REST_Response|WP_Error {
    $jobId = (int) $request->get_param('id');

    $job = $this->queueService->getJob($jobId);

    if (!$job) {
      return new WP_Error(
        'rest_job_not_found',
        __('Queue job not found.', 'flowsystems-webhook-actions'),
        ['status' => 404]
      );
    }

    if ($job['status'] === 'processing') {
      return new WP_Error(
        'rest_job_processing',
        __('Cannot delete a job that is currently being processed.', 'flowsystems-webhook-actions'),
        ['status' => 409]
      );
    }

    $result = $this->queueService->deleteJob($jobId);

    if (!$result) {
      return new WP_Error(
        'rest_job_delete_failed',
        __('Failed to delete queue job.', 'flowsystems-webhook-actions'),
        ['status' => 500]
      );
    }

    return rest_ensure_response([
      'success' => true,
      'message' => __('Job deleted successfully.', 'flowsystems-webhook-actions'),
    ]);
  }

  /**
   * Force retry a failed job
   */
  public function retryItem($request): WP_REST_Response|WP_Error {
    $jobId = (int) $request->get_param('id');

    $job = $this->queueService->getJob($jobId);

    if (!$job) {
      return new WP_Error(
        'rest_job_not_found',
        __('Queue job not found.', 'flowsystems-webhook-actions'),
        ['status' => 404]
      );
    }

    if (!in_array($job['status'], ['failed', 'permanently_failed'], true)) {
      return new WP_Error(
        'rest_job_not_retryable',
        __('Only failed or permanently failed jobs can be retried.', 'flowsystems-webhook-actions'),
        ['status' => 409]
      );
    }

    $result = $this->queueService->forceRetry($jobId);

    if (!$result) {
      return new WP_Error(
        'rest_job_retry_failed',
        __('Failed to retry queue job.', 'flowsystems-webhook-actions'),
        ['status' => 500]
      );
    }

    return rest_ensure_response([
      'success' => true,
      'message' => __('Job has been queued for retry.', 'flowsystems-webhook-actions'),
    ]);
  }

  /**
   * Format a job for API response
   */
  private function formatJob(array $job): array {
    $jobData = json_decode($job['payload'], true);
    $webhook = $jobData['webhook'] ?? [];
    $eventPayload = $jobData['payload'] ?? [];

    $scheduledTimestamp = strtotime($job['scheduled_at']);
    $createdTimestamp = strtotime($job['created_at']);

    return [
      'id' => (int) $job['id'],
      'log_id' => isset($job['log_id']) ? (int) $job['log_id'] : null,
      'event_uuid' => $eventPayload['event']['id'] ?? null,
      'webhook_id' => (int) $job['webhook_id'],
      'webhook_name' => $webhook['name'] ?? null,
      'webhook_url' => $webhook['endpoint_url'] ?? null,
      'trigger_name' => $job['trigger_name'],
      'status' => $job['status'],
      'attempts' => (int) $job['attempts'],
      'max_attempts' => (int) $job['max_attempts'],
      'scheduled_at' => $job['scheduled_at'],
      'scheduled_at_human' => human_time_diff($scheduledTimestamp, time()) .
        ($scheduledTimestamp > time() ? ' from now' : ' ago'),
      'is_due' => $scheduledTimestamp <= time(),
      'created_at' => $job['created_at'],
      'created_at_human' => human_time_diff($createdTimestamp, time()) . ' ago',
      'locked_at' => $job['locked_at'],
      'locked_by' => $job['locked_by'],
    ];
  }
}
