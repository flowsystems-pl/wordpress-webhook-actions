<?php

namespace FlowSystems\WebhookActions\Services\Ai;

defined('ABSPATH') || exit;

/**
 * The AI Builder's applied-object ledger: a durable, per-conversation record of
 * the objects the build has already created (webhooks, provisioned credentials).
 *
 * It makes re-proposed creating steps idempotent. A weaker model tends to re-emit
 * the whole plan every turn; without this, applying it again created the SAME
 * webhook and credential repeatedly. The ledger lets {@see PlanExecutor} reuse
 * what it already made — matching a step by a stable signature — instead of
 * duplicating it. The ledger travels inside `execution_json` so it survives the
 * re-seed that happens on every re-plan.
 */
final class BuildLedger {
  /**
   * Creating abilities whose whole point is a NEW object — re-running one makes
   * a duplicate. Idempotent overwrites (set_mapping, assign_credential, …) are
   * intentionally absent: re-running them is harmless.
   */
  private const DEDUPE_ABILITIES = ['create_webhook', 'provision_wp_app_password'];

  /** Whether an ability creates a new object that must never be duplicated. */
  public static function handles(string $ability): bool {
    return in_array($ability, self::DEDUPE_ABILITIES, true);
  }

  /**
   * The ledger for a run, carried forward from the prior execution: its existing
   * entries plus any completed dedupe-able step not yet recorded. Keyed by a
   * stable per-step signature, so a re-proposed step maps to what it produced.
   *
   * @param array<string, mixed> $prior The prior execution_json (steps + ledger).
   * @return array<int, array<string, mixed>>
   */
  public function carryForward(array $prior): array {
    $ledger = is_array($prior['ledger'] ?? null) ? $prior['ledger'] : [];

    $seen = [];
    foreach ($ledger as $entry) {
      if (isset($entry['signature'])) {
        $seen[(string) $entry['signature']] = true;
      }
    }

    foreach ((array) ($prior['steps'] ?? []) as $step) {
      if ((string) ($step['status'] ?? '') !== 'done' || !self::handles((string) ($step['ability'] ?? ''))) {
        continue;
      }
      $signature = $this->signature($step);
      if ($signature === '' || isset($seen[$signature])) {
        continue;
      }
      $result   = is_array($step['result'] ?? null) ? $step['result'] : [];
      $objectId = (int) (StepResult::objectId($result) ?? 0);
      if ($objectId <= 0) {
        continue;
      }
      $ledger[]         = $this->entry($signature, (string) $step['ability'], $objectId, $result);
      $seen[$signature] = true;
    }

    return $ledger;
  }

  /**
   * Append a just-applied creating step (deduped by signature) so later steps in
   * THIS run — and the system prompt next turn — see it as built.
   *
   * @param array<int, array<string, mixed>> $ledger
   * @param array<string, mixed>             $step
   * @return array<int, array<string, mixed>>
   */
  public function record(array $ledger, array $step, int $objectId): array {
    $signature = $this->signature($step);
    if ($signature === '' || $objectId <= 0) {
      return $ledger;
    }
    foreach ($ledger as $entry) {
      if ((string) ($entry['signature'] ?? '') === $signature) {
        return $ledger;
      }
    }
    $result   = is_array($step['result'] ?? null) ? $step['result'] : [];
    $ledger[] = $this->entry($signature, (string) ($step['ability'] ?? ''), $objectId, $result);
    return $ledger;
  }

  /**
   * The ledger entry a plan step would duplicate, or null when it creates
   * something genuinely new (different signature, or a non-dedupe-able ability).
   *
   * @param array<int, array<string, mixed>> $ledger
   * @param array<string, mixed>             $step
   * @return array<string, mixed>|null
   */
  public function match(array $ledger, array $step): ?array {
    if (!self::handles((string) ($step['ability'] ?? ''))) {
      return null;
    }
    $signature = $this->signature($step);
    if ($signature === '') {
      return null;
    }
    foreach ($ledger as $entry) {
      if ((string) ($entry['signature'] ?? '') === $signature && (int) ($entry['object_id'] ?? 0) > 0) {
        return $entry;
      }
    }
    return null;
  }

  /**
   * @param array<string, mixed> $result
   * @return array<string, mixed>
   */
  private function entry(string $signature, string $ability, int $objectId, array $result): array {
    return [
      'signature' => $signature,
      'ability'   => $ability,
      'object_id' => $objectId,
      'result'    => $result,
      'label'     => $this->label($ability, $result),
    ];
  }

  /**
   * A stable identity for a creating step, so the same intent proposed on a later
   * turn maps to the object already made. create_webhook is keyed by method +
   * endpoint (site placeholders expanded) + triggers; provision_wp_app_password
   * is one-per-build. Returns '' for anything that should never dedupe (e.g. a
   * create_webhook whose endpoint is still blank).
   *
   * @param array<string, mixed> $step
   */
  private function signature(array $step): string {
    $ability = (string) ($step['ability'] ?? '');
    $input   = (array) ($step['input'] ?? []);

    switch ($ability) {
      case 'create_webhook':
        $url = strtolower(trim($this->expandSite((string) ($input['endpoint_url'] ?? ''))));
        if ($url === '') {
          return '';
        }
        $method   = strtoupper((string) ($input['http_method'] ?? 'POST'));
        $triggers = array_map('strval', (array) ($input['triggers'] ?? []));
        sort($triggers);
        return 'create_webhook|' . $method . '|' . $url . '|' . implode(',', $triggers);

      case 'provision_wp_app_password':
        return 'provision_wp_app_password';

      default:
        return '';
    }
  }

  /**
   * A short human/model-readable label for a ledger entry (shown in the system
   * prompt's "already applied" section next turn).
   *
   * @param array<string, mixed> $result
   */
  private function label(string $ability, array $result): string {
    switch ($ability) {
      case 'create_webhook':
        $webhook = (array) ($result['webhook'] ?? []);
        return sprintf(
          'webhook #%d "%s" (%s %s)',
          (int) ($webhook['id'] ?? 0),
          (string) ($webhook['name'] ?? ''),
          strtoupper((string) ($webhook['http_method'] ?? 'POST')),
          (string) ($webhook['endpoint_url'] ?? '')
        );

      case 'provision_wp_app_password':
        $credential = (array) ($result['credential'] ?? []);
        return sprintf(
          'credential #%d "%s"',
          (int) ($credential['id'] ?? ($result['id'] ?? 0)),
          (string) ($credential['name'] ?? '')
        );

      default:
        return $ability;
    }
  }

  /**
   * Expand this site's own `{{site.*}}` placeholders in a URL so a step written
   * against the placeholder form and one written against the literal REST URL
   * share the same signature. Mirrors PlanExecutor's dispatch-time expansion.
   */
  private function expandSite(string $value): string {
    $expanded = preg_replace_callback('/\{\{\s*site\.(url|home_url|rest_url)\s*\}\}/', static function (array $m): string {
      return $m[1] === 'rest_url' ? rest_url() : untrailingslashit(home_url());
    }, $value, -1, $count);

    return $count > 0 ? (string) preg_replace('#(?<!:)//+#', '/', $expanded) : $value;
  }
}
