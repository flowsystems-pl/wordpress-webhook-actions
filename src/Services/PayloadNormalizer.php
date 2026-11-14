<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Support\CredentialScrubber;

/**
 * Builds the payload envelope a trigger fires with, and normalizes the raw
 * do_action arguments inside it into something JSON can carry.
 *
 * This used to live inside Dispatcher as two private methods, which was fine
 * while the dispatcher was the only thing that ever built a payload. It is not
 * any more: the payload harvester that fills the hosted payload library has to
 * produce a byte-identical shape, because the field paths we serve
 * ("args.1.billing.email") are only correct if they are the same paths a real
 * dispatch would produce on the customer's site.
 *
 * A second implementation that merely *looked* right would be the worst kind of
 * bug — set_mapping would accept the guessed paths, test_dispatch could pass
 * against a forgiving endpoint, and the customer's live deliveries would quietly
 * ship nulls. So there is one implementation, here, and both callers use it.
 *
 * @see Dispatcher::dispatch()
 */
class PayloadNormalizer {
  /** Payload envelope version, reported as event.version. */
  public const VERSION = '1.0';

  /**
   * The complete payload for a trigger firing: event identity, hook name,
   * normalized args, and site context.
   *
   * Event identity is a parameter rather than generated here because the
   * dispatcher also puts it on the wire as X-Event-Id / X-Event-Timestamp and
   * needs the same values it embedded. Callers with no such need (the harvester)
   * pass null and get fresh ones.
   *
   * @param string      $hook           Trigger (do_action) name.
   * @param array<int, mixed> $args     Raw arguments the hook fired with.
   * @param string|null $eventId        Event UUID; generated when null.
   * @param string|null $eventTimestamp ISO-8601 UTC; generated when null.
   * @return array<string, mixed>
   */
  public static function envelope(
    string $hook,
    array $args,
    ?string $eventId = null,
    ?string $eventTimestamp = null
  ): array {
    // Passwords come out here, before the payload exists anywhere else: the
    // captured example, the queue, the delivery log, the wire and Code Glue all
    // start from this array. See CredentialScrubber for what counts.
    $scrub      = CredentialScrubber::enabledFor($hook);
    $normalized = self::args($args);
    if ($scrub) {
      $normalized = (new CredentialScrubber())->args($hook, $normalized);
    }

    /**
     * Filter the webhook payload before dispatching.
     *
     * @param array  $payload The default payload data
     * @param string $hook    The trigger event name
     * @param array  $args    Original arguments passed to the trigger
     */
    $payload = apply_filters(
      'fswa_payload',
      [
        'event' => [
          'id'        => $eventId ?? wp_generate_uuid4(),
          'timestamp' => $eventTimestamp ?? gmdate('Y-m-d\TH:i:s\Z'),
          'version'   => self::VERSION,
        ],
        'hook'      => $hook,
        'args'      => $normalized,
        'timestamp' => time(),
        'site'      => [
          'url' => home_url(),
        ],
      ],
      $hook,
      $args
    );

    // Again after the filter, which is handed the raw arguments and can put a
    // password back without meaning to.
    if ($scrub && is_array($payload)) {
      $payload = (new CredentialScrubber())->envelope($payload, $hook);
    }

    return $payload;
  }

  /**
   * Normalize arguments for payload serialization.
   *
   * @param array<int, mixed> $args Arguments to normalize
   * @return array<int, mixed> Normalized arguments
   */
  public static function args(array $args): array {
    return array_map([self::class, 'value'], $args);
  }

  /**
   * Recursively normalize a single value for payload serialization.
   *
   * @param mixed $value Value to normalize
   * @return mixed Normalized value
   */
  public static function value(mixed $value): mixed {
    if (is_scalar($value) || $value === null) {
      return $value;
    }

    if (is_array($value)) {
      return array_map([self::class, 'value'], $value);
    }

    if (is_object($value)) {
      if ($value instanceof \Closure) {
        return null;
      }

      if ($value instanceof \DateTimeInterface) {
        return $value->format(\DateTime::ATOM);
      }

      if ($value instanceof \Traversable) {
        return array_map([self::class, 'value'], iterator_to_array($value, false));
      }

      // Allow third-party code to provide custom extraction for any object type.
      $custom = apply_filters('fswa_normalize_object', null, $value);
      if (is_array($custom)) {
        return array_merge(['__type' => get_class($value)], array_map([self::class, 'value'], $custom));
      }

      if (method_exists($value, 'get_data')) {
        $data = $value->get_data();
      } elseif ($value instanceof \JsonSerializable) {
        $data = $value->jsonSerialize();
      } elseif (method_exists($value, 'get_properties')) {
        $data = $value->get_properties();
      } else {
        $data = get_object_vars($value);
      }

      // The extractor above can hand back something that is not an array, and
      // the old `(string) $value` fallback then threw on any object without
      // __toString — "Object of class X could not be converted to string",
      // raised from inside dispatch(), which kills the request rather than
      // dropping one field. WordPress fires http_api_debug with a
      // WP_HTTP_Requests_Response that hits exactly this path, so a webhook on
      // that trigger would have taken the site's request down with it.
      //
      // Degrade instead: keep a usable scalar, stringify only what can be
      // stringified, and otherwise emit the bare __type — which callers already
      // understand as "opaque object, nothing to map" (see
      // ReadAbilities::captureIsOpaque()).
      if (is_array($data)) {
        $data = array_map([self::class, 'value'], $data);
      } elseif (is_scalar($data) || $data === null) {
        $data = ['value' => $data];
      } elseif (method_exists($value, '__toString')) {
        $data = ['value' => (string) $value];
      } else {
        $data = [];
      }

      return array_merge(['__type' => get_class($value)], $data);
    }

    return null;
  }
}
