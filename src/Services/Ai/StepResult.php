<?php

namespace FlowSystems\WebhookActions\Services\Ai;

defined('ABSPATH') || exit;

/**
 * Small, pure helpers for reading an ability result shape. Shared by the plan
 * executor and the build ledger so the "what object did this step touch" logic
 * lives in exactly one place.
 */
final class StepResult {
  /**
   * Best-effort extraction of the affected object id from an ability result.
   *
   * @param array<string, mixed> $result
   */
  public static function objectId(array $result): ?int {
    foreach (['webhook', 'chain', 'link', 'snippet', 'credential'] as $key) {
      if (isset($result[$key]['id'])) {
        return (int) $result[$key]['id'];
      }
    }
    foreach (['id', 'webhook_id', 'log_id', 'schema_id'] as $key) {
      if (isset($result[$key])) {
        return (int) $result[$key];
      }
    }
    return null;
  }

  /**
   * Map an ability name to an activity-log object type.
   */
  public static function objectType(string $ability): string {
    return match (true) {
      str_contains($ability, 'chain')      => 'chain',
      str_contains($ability, 'credential') => 'credential',
      default                              => 'webhook',
    };
  }
}
