<?php

namespace FlowSystems\WebhookActions\Api;

defined('ABSPATH') || exit;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Response;
use FlowSystems\WebhookActions\Repositories\LogRepository;
use FlowSystems\WebhookActions\Repositories\StatsRepository;
use FlowSystems\WebhookActions\Repositories\WebhookRepository;
use FlowSystems\WebhookActions\Services\QueueService;
use FlowSystems\WebhookActions\Services\StatsService;
use FlowSystems\WebhookActions\Services\Scheduler;
use FlowSystems\WebhookActions\Api\AuthHelper;
use WP_Error;

class HealthController extends WP_REST_Controller {
  protected $namespace = 'fswa/v1';
  protected $rest_base = 'health';

  private LogRepository   $logRepository;
  private WebhookRepository $webhookRepository;
  private QueueService    $queueService;
  private StatsService    $statsService;
  private StatsRepository $statsRepository;

  public function __construct() {
    $this->logRepository   = new LogRepository();
    $this->webhookRepository = new WebhookRepository();
    $this->queueService    = new QueueService();
    $this->statsService    = new StatsService();
    $this->statsRepository = new StatsRepository();
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

  public function permissionsCheck($request): bool|WP_Error {
    return AuthHelper::dualAuth($request, AuthHelper::SCOPE_READ);
  }

  /**
   * Get health statistics
   */
  public function getStats($request): WP_REST_Response {
    // Get persistent stats (fswa_stats — not affected by log deletion or replay)
    $persistentStats = $this->statsRepository->getAllTimeStats();

    // Add legacy archived stats (pre-v1.2.0 logs deleted before the new table existed)
    $archivedStats = $this->statsService->getArchivedStats();

    $totalSent    = $persistentStats['total_sent']    + $archivedStats['total_sent'];
    $totalSuccess = $persistentStats['total_success'] + $archivedStats['total_success'];
    $totalError   = $persistentStats['total_error']   + $archivedStats['total_error'];

    // Calculate success rate from all-time data
    $totalDeliveries = $totalSuccess + $totalError;
    $hasData = $totalDeliveries > 0;
    $successRate = $hasData
      ? round(($totalSuccess / $totalDeliveries) * 100, 1)
      : 0.0;

    // "Sent today" comes from the SAME table and columns as "Total sent", just
    // windowed to today — so the pair can never contradict itself.
    //
    // It used to be getVelocityStats()['last_day'], a raw COUNT(*) of log rows in
    // the last 24h. That counts rows that were never sent: `skipped` (a condition
    // filtered the event out), `pending` (not dispatched yet) and `test` (a manual
    // test dispatch). None of them appear in `total_sent`, so a site could show
    // "Sent today 5 / Total sent 4" — today exceeding all time, which is
    // impossible and reads as a broken counter. A fresh Live Preview hit it every
    // time, because the seeded conditions demo deliberately produces one skipped
    // delivery.
    $todayStats = $this->statsRepository->getPeriodStats(null, 0);
    $sentToday  = $todayStats['success'] + $todayStats['permanently_failed'];

    // Get recent stats (persistent + transient) for the logs view
    $recentPersistent = $this->statsRepository->getPeriodStats(null, 7);
    $recentTransient  = $this->logRepository->getTransientStats(null, 7);
    $recentLogStats   = [
      'success'            => $recentPersistent['success'],
      'permanently_failed' => $recentPersistent['permanently_failed'],
      'error'              => $recentTransient['error'],
      'retry'              => $recentTransient['retry'],
      'pending'            => $recentTransient['pending'],
      'total'              => $recentPersistent['success'] + $recentTransient['error'] + $recentPersistent['permanently_failed'],
    ];

    // Get webhook counts
    $totalWebhooks = $this->webhookRepository->count(false);
    $activeWebhooks = $this->webhookRepository->count(true);

    // Get queue stats
    $queueStats = $this->queueService->getStats();

    // Get velocity stats
    $velocityStats = $this->logRepository->getVelocityStats();

    $oldestPendingAge = $this->logRepository->getOldestPendingAgeSeconds();

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
        'sent_today' => $sentToday,
        'success' => $recentLogStats['success'],
        'error' => $recentLogStats['error'],
        'pending' => $recentLogStats['pending'],
        'retry' => $recentLogStats['retry'],
        'permanently_failed' => $recentLogStats['permanently_failed'] ?? 0,
      ],
      'queue' => [
        'pending' => $queueStats['pending'],
        'processing' => $queueStats['processing'],
        'completed' => $queueStats['completed'],
        'permanently_failed' => $queueStats['permanently_failed'] ?? 0,
        'total' => $queueStats['total'],
        'due_now' => $queueStats['due_now'],
      ],
      'velocity' => [
        'last_hour' => $velocityStats['last_hour'],
        'last_day' => $velocityStats['last_day'],
        'avg_duration_ms' => $velocityStats['avg_duration_ms'],
      ],
      'notifications' => $this->notificationHealth(),
      'observability' => [
        'avg_attempts_per_event' => $this->logRepository->getAvgAttemptsPerEvent(),
        'oldest_pending_age_seconds' => $oldestPendingAge,
        'queue_stuck' => ($oldestPendingAge ?? 0) > 600,
        'wp_cron_only' => !Scheduler::hasActionScheduler() && (int) get_option('fswa_last_cron_run', 0) === 0,
      ],
    ]);
  }

  /**
   * Channels whose last send failed, so a rotated Slack URL or a revoked bot
   * token surfaces in the health bar instead of failing quietly forever.
   *
   * @return array{failing: array<int, array{id:int, name:string, type:string, error:string}>, pending:int}
   */
  private function notificationHealth(): array {
    $failing = [];
    foreach ((new \FlowSystems\WebhookActions\Repositories\NotificationChannelRepository())->failing() as $channel) {
      $failing[] = [
        'id'    => (int) $channel['id'],
        'name'  => (string) $channel['name'],
        'type'  => (string) $channel['type'],
        'error' => mb_substr((string) $channel['last_error'], 0, 200),
        'at'    => $channel['last_error_at'],
      ];
    }

    return [
      'failing' => $failing,
      'pending' => (new \FlowSystems\WebhookActions\Repositories\NotificationLogRepository())->countPending(),
    ];
  }
}
