<?php

namespace FlowSystems\WebhookActions\Api;

defined('ABSPATH') || exit;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Response;
use FlowSystems\WebhookActions\Repositories\LogRepository;
use FlowSystems\WebhookActions\Repositories\WebhookRepository;
use FlowSystems\WebhookActions\Services\QueueService;
use FlowSystems\WebhookActions\Services\StatsService;

class HealthController extends WP_REST_Controller {
  protected $namespace = 'fswa/v1';
  protected $rest_base = 'health';

  private LogRepository $logRepository;
  private WebhookRepository $webhookRepository;
  private QueueService $queueService;
  private StatsService $statsService;

  public function __construct() {
    $this->logRepository = new LogRepository();
    $this->webhookRepository = new WebhookRepository();
    $this->queueService = new QueueService();
    $this->statsService = new StatsService();
  }

  /**
   * Register routes
   */
  public function registerRoutes(): void {
    register_rest_route($this->namespace, '/' . $this->rest_base, [
      [
        'methods' => WP_REST_Server::READABLE,
        'callback' => [$this, 'getStats'],
        'permission_callback' => [$this, 'permissionsCheck'],
      ],
    ]);
  }

  /**
   * Check permissions
   */
  public function permissionsCheck($request): bool {
    return current_user_can('manage_options');
  }

  /**
   * Get health statistics
   */
  public function getStats($request): WP_REST_Response {
    // Get current log stats (what's in the database)
    $currentLogStats = $this->logRepository->getAllTimeStats();

    // Get archived stats (from logs that were deleted during retention)
    $archivedStats = $this->statsService->getArchivedStats();

    // Calculate totals: current logs + archived
    $totalSent = $currentLogStats['total_sent'] + $archivedStats['total_sent'];
    $totalSuccess = $currentLogStats['total_success'] + $archivedStats['total_success'];
    $totalError = $currentLogStats['total_error'] + $archivedStats['total_error'];

    // Calculate success rate from all-time data
    $totalDeliveries = $totalSuccess + $totalError;
    $hasData = $totalDeliveries > 0;
    $successRate = $hasData
      ? round(($totalSuccess / $totalDeliveries) * 100, 1)
      : 0.0;

    // Get recent log stats (last 7 days) for the logs view
    $recentLogStats = $this->logRepository->getStats(null, 7);

    // Get webhook counts
    $totalWebhooks = $this->webhookRepository->count(false);
    $activeWebhooks = $this->webhookRepository->count(true);

    // Get queue stats
    $queueStats = $this->queueService->getStats();

    // Get velocity stats
    $velocityStats = $this->logRepository->getVelocityStats();

    return rest_ensure_response([
      'success_rate' => $successRate,
      'has_data' => $hasData,
      'webhooks' => [
        'total' => $totalWebhooks,
        'active' => $activeWebhooks,
      ],
      'logs' => [
        'total' => $recentLogStats['total'],
        'total_all_time' => $totalSent,
        'success' => $recentLogStats['success'],
        'error' => $recentLogStats['error'],
        'pending' => $recentLogStats['pending'],
        'retry' => $recentLogStats['retry'],
      ],
      'queue' => [
        'pending' => $queueStats['pending'],
        'processing' => $queueStats['processing'],
        'completed' => $queueStats['completed'],
        'failed' => $queueStats['failed'],
        'total' => $queueStats['total'],
        'due_now' => $queueStats['due_now'],
      ],
      'velocity' => [
        'last_hour' => $velocityStats['last_hour'],
        'last_day' => $velocityStats['last_day'],
        'avg_duration_ms' => $velocityStats['avg_duration_ms'],
      ],
    ]);
  }
}
