<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

use DateTime;
use DateTimeZone;
use FlowSystems\WebhookActions\Repositories\QueueRepository;

class QueueService {
  private QueueRepository $repository;

  public function __construct(?QueueRepository $repository = null) {
    $this->repository = $repository ?? new QueueRepository();
  }

  /**
   * Enqueue a job for processing
   *
   * @param int $webhookId
   * @param string $trigger
   * @param array $payload
   * @param DateTime|null $scheduledAt When to process (null = now)
   * @param int|null $logId Associated log ID
   * @return int Job ID
   */
  public function enqueue(int $webhookId, string $trigger, array $payload, ?DateTime $scheduledAt = null, ?int $logId = null): int {
    if ($scheduledAt === null) {
      $scheduledAt = new DateTime('now', new DateTimeZone('UTC'));
    }

    /**
     * Filter the maximum number of retry attempts for failed webhooks.
     *
     * @param int $max_attempts Maximum retry attempts (default 5)
     */
    $maxAttempts = (int) apply_filters('fswa_max_attempts', 5);

    $data = [
      'webhook_id' => $webhookId,
      'trigger_name' => $trigger,
      'payload' => wp_json_encode($payload),
      'status' => 'pending',
      'attempts' => 0,
      'max_attempts' => $maxAttempts,
      'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
      'created_at' => current_time('mysql', true),
    ];

    if ($logId !== null) {
      $data['log_id'] = $logId;
    }

    return $this->repository->insert($data);
  }

  /**
   * Get next batch of jobs ready for processing
   *
   * @param int $batchSize
   * @return array
   */
  public function getNextBatch(int $batchSize = 10): array {
    $now = current_time('mysql', true);
    return $this->repository->findPendingBatch($now, $batchSize);
  }

  /**
   * Attempt to lock a job for processing
   *
   * @param int $jobId
   * @param string $lockId Unique identifier for this processor
   * @return bool True if lock acquired
   */
  public function lockJob(int $jobId, string $lockId): bool {
    $now = current_time('mysql', true);
    return $this->repository->acquireLock($jobId, $lockId, $now);
  }

  /**
   * Unlock a job without changing status
   *
   * @param int $jobId
   */
  public function unlockJob(int $jobId): void {
    $this->repository->update($jobId, [
      'locked_at' => null,
      'locked_by' => null,
      'status' => 'pending',
    ]);
  }

  /**
   * Mark a job as completed
   *
   * @param int $jobId
   */
  public function markCompleted(int $jobId): void {
    $this->repository->update($jobId, [
      'status' => 'completed',
      'locked_at' => null,
      'locked_by' => null,
    ]);
  }

  /**
   * Mark a job as failed
   *
   * @param int $jobId
   */
  public function markFailed(int $jobId): void {
    $this->repository->update($jobId, [
      'status' => 'failed',
      'locked_at' => null,
      'locked_by' => null,
    ]);
  }

  /**
   * Mark a job as permanently failed (non-retryable or max attempts exceeded)
   *
   * @param int $jobId
   */
  public function markPermanentlyFailed(int $jobId): void {
    $this->repository->update($jobId, [
      'status' => 'permanently_failed',
      'locked_at' => null,
      'locked_by' => null,
    ]);
  }

  /**
   * Reschedule a job with exponential backoff
   *
   * @param int $jobId
   * @return array{rescheduled: bool, scheduled_at: string|null}
   */
  public function rescheduleWithBackoff(int $jobId): array {
    $job = $this->repository->find($jobId);

    if (!$job) {
      return ['rescheduled' => false, 'scheduled_at' => null];
    }

    $newAttempts = (int) $job['attempts'] + 1;
    $maxAttempts = (int) $job['max_attempts'];

    if ($newAttempts >= $maxAttempts) {
      return ['rescheduled' => false, 'scheduled_at' => null];
    }

    // Calculate backoff delay: min(2^attempts * 30, 3600) seconds
    $delaySeconds = min(pow(2, $newAttempts) * 30, 3600);
    $scheduledAt = new DateTime('now', new DateTimeZone('UTC'));
    $scheduledAt->modify("+{$delaySeconds} seconds");
    $scheduledAtStr = $scheduledAt->format('Y-m-d H:i:s');

    $this->repository->update($jobId, [
      'attempts' => $newAttempts,
      'status' => 'pending',
      'locked_at' => null,
      'locked_by' => null,
      'scheduled_at' => $scheduledAtStr,
    ]);

    return ['rescheduled' => true, 'scheduled_at' => $scheduledAtStr];
  }

  /**
   * Cleanup stale locks (jobs stuck in processing state)
   *
   * @param int $timeoutMinutes Timeout threshold in minutes
   * @return int Number of jobs unlocked
   */
  public function cleanupStaleJobs(int $timeoutMinutes = 5): int {
    $threshold = new DateTime('now', new DateTimeZone('UTC'));
    $threshold->modify("-{$timeoutMinutes} minutes");

    return $this->repository->resetStaleJobs($threshold->format('Y-m-d H:i:s'));
  }

  /**
   * Get queue statistics
   *
   * @return array
   */
  public function getStats(): array {
    $stats = $this->repository->getCountsByStatus();

    $result = [
      'pending' => 0,
      'processing' => 0,
      'completed' => 0,
      'failed' => 0,
      'permanently_failed' => 0,
      'total' => 0,
    ];

    foreach ($stats as $row) {
      if (array_key_exists($row['status'], $result)) {
        $result[$row['status']] = (int) $row['count'];
      }
      $result['total'] += (int) $row['count'];
    }

    $result['oldest_pending'] = $this->repository->getOldestPendingTimestamp();
    $result['due_now'] = $this->repository->countDueNow(current_time('mysql', true));

    return $result;
  }

  /**
   * Get a single job by ID
   *
   * @param int $jobId
   * @return array|null
   */
  public function getJob(int $jobId): ?array {
    return $this->repository->find($jobId);
  }

  /**
   * Delete a job from the queue
   *
   * @param int $jobId
   * @return bool
   */
  public function deleteJob(int $jobId): bool {
    return $this->repository->delete($jobId);
  }

  /**
   * Get jobs with optional filters
   *
   * @param array $filters
   * @param int $limit
   * @param int $offset
   * @return array
   */
  public function getJobs(array $filters = [], int $limit = 50, int $offset = 0): array {
    return $this->repository->findBy($filters, $limit, $offset);
  }

  /**
   * Count jobs with optional filters
   *
   * @param array $filters
   * @return int
   */
  public function countJobs(array $filters = []): int {
    return $this->repository->countBy($filters);
  }

  /**
   * Cleanup old completed jobs
   *
   * @param int $olderThanDays
   * @return int Number of jobs deleted
   */
  public function cleanupCompletedJobs(int $olderThanDays = 7): int {
    $threshold = new DateTime('now', new DateTimeZone('UTC'));
    $threshold->modify("-{$olderThanDays} days");

    return $this->repository->deleteCompletedBefore($threshold->format('Y-m-d H:i:s'));
  }

  /**
   * Force retry a failed or permanently failed job immediately
   *
   * @param int $jobId
   * @return bool
   */
  public function forceRetry(int $jobId): bool {
    $job = $this->repository->find($jobId);

    if (!$job || !in_array($job['status'], ['failed', 'permanently_failed'], true)) {
      return false;
    }

    $now = current_time('mysql', true);

    return $this->repository->update($jobId, [
      'status' => 'pending',
      'attempts' => 0,
      'scheduled_at' => $now,
      'locked_at' => null,
      'locked_by' => null,
    ]);
  }

  /**
   * Get the repository instance
   *
   * @return QueueRepository
   */
  public function getRepository(): QueueRepository {
    return $this->repository;
  }
}
