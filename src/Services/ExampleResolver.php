<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Repositories\SchemaRepository;
use FlowSystems\WebhookActions\Services\Ai\PayloadLibrary;

/**
 * Which example payload a webhook+trigger should be configured against.
 *
 * Three sources, in a strict order that never changes:
 *
 *   own     — this webhook's own capture. Always wins.
 *   shared  — a capture for the same trigger on another webhook here. The
 *             do_action payload shape is trigger-global, so this is still the
 *             customer's real data.
 *   library — our hosted reference payload, from our fixtures. Only when the
 *             site has nothing of its own, and only for hosted-AI installs.
 *
 * **The library result is never persisted.** SchemaRepository::captureExamplePayload()
 * only takes a fresh example while `example_payload` is empty, so writing a
 * library payload into that column would make the site believe it had captured
 * one — and the real payload could then never arrive. Resolving it per call, the
 * way `shared` already works, means the day a real event fires the library
 * disappears on its own with no migration and no user action.
 *
 * Exists as a separate class rather than a fourth branch inside
 * SchemaRepository because that is a repository: it must not make HTTP calls.
 */
class ExampleResolver {
  private SchemaRepository $schemas;
  private PayloadLibrary $library;

  public function __construct(?SchemaRepository $schemas = null, ?PayloadLibrary $library = null) {
    $this->schemas = $schemas ?? new SchemaRepository();
    $this->library = $library ?? new PayloadLibrary();
  }

  /**
   * @param array|null $schema Pre-fetched row, to avoid a duplicate query.
   * @return array{
   *   example: array|string|null,
   *   source: string|null,
   *   from_webhook_id: int,
   *   library: array<string, mixed>|null
   * }
   */
  public function resolve(int $webhookId, string $trigger, ?array $schema = null): array {
    $local = $this->schemas->resolveExample($webhookId, $trigger, $schema);

    if ($local['example'] !== null) {
      return $local + ['library' => null];
    }

    // Chain links fire from another webhook's response, never from a do_action
    // anyone could capture — ours or theirs. Asking about one can only miss.
    if (strncmp($trigger, 'fswa_chain_link:', 16) === 0) {
      return $local + ['library' => null];
    }

    $card = $this->library->lookup($trigger);

    if ($card === null) {
      return $local + ['library' => null];
    }

    return [
      'example'         => $card['payload'],
      'source'          => 'library',
      'from_webhook_id' => 0,
      'library'         => $card,
    ];
  }

  /**
   * Whether a resolved example may back set_mapping / set_conditions for a
   * given source path.
   *
   * For an own or shared example the answer is always yes — it is the
   * customer's own data. For a library example the containers listed in
   * `unsafe.site_defined` exist on their site too, but the KEYS inside them are
   * the customer's and are not in our payload. Mapping one silently ships a
   * null, which looks applied and is quietly broken — the same failure
   * PlanExecutor::missingCapture() already refuses to allow for guessed paths.
   *
   * @param array<string, mixed> $resolved A resolve() result.
   */
  public static function pathIsUnsafe(array $resolved, string $path): bool {
    $roots = $resolved['library']['unsafe']['site_defined'] ?? null;

    if (!is_array($roots) || $path === '') {
      return false;
    }

    foreach ($roots as $root) {
      $root = (string) $root;

      if ($root !== '' && ($path === $root || strncmp($path, $root . '.', strlen($root) + 1) === 0)) {
        return true;
      }
    }

    return false;
  }
}
