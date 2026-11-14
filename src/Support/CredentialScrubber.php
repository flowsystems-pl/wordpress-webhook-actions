<?php

namespace FlowSystems\WebhookActions\Support;

defined('ABSPATH') || exit;

/**
 * Takes passwords out of a payload before it is stored or delivered.
 *
 * WordPress hands hooks whatever it has on hand, and for account hooks that
 * includes the password: `user_register` fires with the raw wp_insert_user()
 * array, where `user_pass` is still the text the visitor typed, and
 * `password_reset` passes the new password as a bare argument. A webhook on
 * one of those triggers used to store that value in its captured example and
 * in every delivery log, and send it to the endpoint unless a field mapping
 * happened to leave it out.
 *
 * So the value is removed where the payload is built, before anything else
 * sees it (see PayloadNormalizer::envelope()). The key stays and the value
 * becomes null: field paths and payload shape do not change, and a receiver
 * that feeds the field into "create account" fails instead of giving every
 * account the same readable placeholder as its password.
 *
 * Deliberately narrower than Services\Ai\PayloadRedactor, which also blanks
 * anything named token, key or secret before a model reads it. Doing that to
 * deliveries would break real integrations (a WooCommerce `order_key` is data
 * the receiver needs), so this class only knows about passwords and account
 * activation keys.
 */
class CredentialScrubber {
  /** What a removed value becomes. */
  public const REPLACEMENT = null;

  /** Guard against a pathological payload; deeper values are left as they are. */
  private const MAX_DEPTH = 40;

  /**
   * Key names that hold a password, matched against the normalized key
   * (see normalizeKey()): lower case, snake_case, no leading underscore.
   *
   * The bare `pass` form is anchored to a short list of prefixes on purpose.
   * `boarding_pass`, `season_pass` and `bypass` are ordinary data.
   */
  private const KEY_PATTERNS = [
    '/(password|passwd|passphrase|pass_word)(_?(\d|new|old|current|confirm|confirmation|confirmed|again|repeat|retype|verify|hash|hashed))?$/',
    '/(^|_)(pwd|pswd)(_?(\d|new|old|confirm|repeat))?$/',
    '/^((user|wp|account|login|admin|member|customer|new|old|current|confirm|repeat|db|smtp|ftp|mail)_?)?pass(_?(\d|new|old|confirm|confirmation|again|repeat))?$/',
    '/^(user_)?activation_key$/',
  ];

  /** Keys that name the field in a `{name: "...", value: "..."}` pair. */
  private const NAME_KEYS = ['key', 'name', 'field', 'label', 'meta_key', 'slug'];

  /** Keys that carry the value in such a pair. */
  private const VALUE_KEYS = ['value', 'value_raw', 'raw_value'];

  /**
   * Hooks that pass a password or a one-time account key as a bare argument,
   * with no key to match on. Hook name => argument indexes.
   *
   * Each entry was read off the do_action()/apply_filters() call in WordPress
   * 7.0 and WooCommerce 10.8. Extend with the `fswa_credential_arg_positions`
   * filter.
   */
  private const ARG_POSITIONS = [
    // do_action( 'wp_set_password', $password, $user_id, $old_user_data )
    'wp_set_password'                             => [0],
    // do_action( 'password_reset', $user, $new_pass )
    'password_reset'                              => [1],
    'after_password_reset'                        => [1],
    // do_action_ref_array( 'wp_authenticate', [ &$user_login, &$user_password ] ) — every login attempt
    'wp_authenticate'                             => [1],
    // do_action_ref_array( 'check_passwords', [ $user_login, &$pass1, &$pass2 ] )
    'check_passwords'                             => [1, 2],
    // do_action( 'retrieve_password_key', $user_login, $key )
    'retrieve_password_key'                       => [1],
    // do_action( 'wp_create_application_password', $user_id, $new_item, $new_password, $args )
    'wp_create_application_password'              => [2],
    // do_action( 'wpmu_activate_user', $user_id, $password, $meta )
    'wpmu_activate_user'                          => [1],
    // do_action( 'wpmu_activate_blog', $blog_id, $user_id, $password, $title, $meta )
    'wpmu_activate_blog'                          => [2],
    // do_action( 'after_signup_user', $user, $user_email, $key, $meta )
    'after_signup_user'                           => [2],
    // do_action( 'after_signup_site', $domain, $path, $title, $user, $user_email, $key, $meta )
    'after_signup_site'                           => [5],
    // do_action( 'invite_user', $user_id, $role, $newuser_key )
    'invite_user'                                 => [2],
    // Filters. A trigger can be put on a filter name too.
    // apply_filters( 'authenticate', null, $username, $password )
    'authenticate'                                => [2],
    // apply_filters( 'wp_authenticate_user', $user, $password )
    'wp_authenticate_user'                        => [1],
    // apply_filters( 'check_password', $check, $password, $hash, $user_id )
    'check_password'                              => [1, 2],
    // apply_filters( 'random_password', $password, $length, ... )
    'random_password'                             => [0],
    // apply_filters( 'retrieve_password_message', $message, $key, $user_login, $user_data ) — the message holds the reset link
    'retrieve_password_message'                   => [0, 1],
    // apply_filters( 'retrieve_password_notification_email', $defaults, $key, $user_login, $user_data )
    'retrieve_password_notification_email'        => [1],
    // apply_filters( 'wp_authenticate_application_password_errors', $error, $user, $item, $password )
    'wp_authenticate_application_password_errors' => [3],
    // apply_filters( 'signup_user_meta', $meta, $user, $user_email, $key )
    'signup_user_meta'                            => [3],
    // apply_filters( 'woocommerce_process_login_errors', $validation_error, $user_login, $user_password )
    'woocommerce_process_login_errors'            => [2],
    // apply_filters( 'woocommerce_process_registration_errors', $validation_error, $username, $password, $email )
    'woocommerce_process_registration_errors'     => [2],
  ];

  /**
   * Every value this instance removed, as strings. The stored-data cleanup uses
   * it to find the same value again where it has no key to go by: a field
   * mapping may have renamed `user_pass`, and an endpoint may have echoed it
   * back in its response body.
   *
   * @var array<string, true>
   */
  private array $removed = [];

  /**
   * Should payloads for this trigger be scrubbed? On for every trigger.
   *
   * @param string $hook Trigger name.
   */
  public static function enabledFor(string $hook): bool {
    /**
     * Turn the credential scrub off for a trigger.
     *
     * Returning false sends password fields to the endpoint and stores them in
     * the captured example and in the delivery log, as before 3.4.3. Only do
     * that for a trigger whose whole purpose is to forward a password, and
     * only to an endpoint you control.
     *
     * @param bool   $scrub Whether to scrub. Default true.
     * @param string $hook  The trigger name.
     */
    return (bool) apply_filters('fswa_scrub_credentials', true, $hook);
  }

  /**
   * Scrub the normalized arguments of one trigger firing: the positions this
   * hook is known to put a password in, then every password-named key.
   *
   * @param string            $hook Trigger name.
   * @param array<int, mixed> $args Arguments, already normalized to arrays and scalars.
   * @return array<int, mixed>
   */
  public function args(string $hook, array $args): array {
    foreach (self::positionsFor($hook) as $index) {
      if (array_key_exists($index, $args)) {
        $args[$index] = $this->blank($args[$index]);
      }
    }

    return $this->walk($args, 0);
  }

  /**
   * Scrub a complete payload envelope. The hook name is read from the envelope
   * when it is not passed, which is what makes this usable on stored rows.
   *
   * @param array<string, mixed> $payload
   * @return array<string, mixed>
   */
  public function envelope(array $payload, ?string $hook = null): array {
    $hook = $hook ?? (is_string($payload['hook'] ?? null) ? $payload['hook'] : '');

    if ($hook !== '' && isset($payload['args']) && is_array($payload['args']) && Arr::isList($payload['args'])) {
      foreach (self::positionsFor($hook) as $index) {
        if (array_key_exists($index, $payload['args'])) {
          $payload['args'][$index] = $this->blank($payload['args'][$index]);
        }
      }
    }

    return $this->walk($payload, 0);
  }

  /**
   * Scrub a value of any shape by key name. Strings are looked into when they
   * hold JSON.
   *
   * @param mixed $value
   * @return mixed
   */
  public function value($value) {
    return $this->walk($value, 0);
  }

  /**
   * Null every string leaf that is exactly one of the given values, and cut
   * them out of text that is not JSON. For stored rows only: it is how a
   * password that a field mapping renamed, or that an endpoint echoed back, is
   * found again. Short values are skipped because they collide with ordinary
   * data ("1234" is also an order number).
   *
   * @param mixed         $value
   * @param array<string> $secrets
   * @return mixed
   */
  public function removeKnown($value, array $secrets, int $depth = 0) {
    $secrets = array_values(array_filter($secrets, static fn($s) => is_string($s) && strlen($s) >= 6));
    if ($secrets === [] || $depth > self::MAX_DEPTH) {
      return $value;
    }

    if (is_array($value)) {
      foreach ($value as $key => $item) {
        $value[$key] = $this->removeKnown($item, $secrets, $depth + 1);
      }
      return $value;
    }

    if (!is_string($value) || $value === '') {
      return $value;
    }

    if (in_array($value, $secrets, true)) {
      return self::REPLACEMENT;
    }

    $decoded = self::decodeJson($value);
    if ($decoded !== null) {
      $cleaned = $this->removeKnown($decoded, $secrets, $depth + 1);
      return $cleaned === $decoded ? $value : self::encodeJson($cleaned);
    }

    foreach ($secrets as $secret) {
      if (strpos($value, $secret) !== false) {
        $value = str_replace($secret, '', $value);
      }
    }

    return $value;
  }

  /**
   * The values removed so far.
   *
   * @return array<int, string>
   */
  public function removed(): array {
    return array_map('strval', array_keys($this->removed));
  }

  /**
   * Is this a key that holds a password or an account activation key?
   */
  public static function isCredentialKey(string $key): bool {
    $normalized = self::normalizeKey($key);
    if ($normalized === '') {
      return false;
    }

    foreach (self::KEY_PATTERNS as $pattern) {
      if (preg_match($pattern, $normalized)) {
        return true;
      }
    }

    return false;
  }

  /**
   * Argument indexes that hold a credential for this hook.
   *
   * @return array<int, int>
   */
  public static function positionsFor(string $hook): array {
    $map = self::positionMap();

    return isset($map[$hook]) ? $map[$hook] : [];
  }

  /**
   * The hooks that pass a credential as a bare argument.
   *
   * @return array<int, string>
   */
  public static function positionalHooks(): array {
    return array_map('strval', array_keys(self::positionMap()));
  }

  /**
   * @return array<string, array<int, int>>
   */
  private static function positionMap(): array {
    /**
     * Hooks that pass a password as a bare argument, by argument index.
     *
     * @param array<string, array<int, int>> $positions Hook name => indexes.
     */
    $map = apply_filters('fswa_credential_arg_positions', self::ARG_POSITIONS);

    $clean = [];
    foreach (is_array($map) ? $map : self::ARG_POSITIONS as $hook => $positions) {
      $positions = is_array($positions) ? array_values(array_filter($positions, 'is_int')) : [];
      if (is_string($hook) && $positions !== []) {
        $clean[$hook] = $positions;
      }
    }

    return $clean;
  }

  /**
   * `userPass`, `User-Pass`, `_user_pass` and `user pass` all become `user_pass`.
   */
  private static function normalizeKey(string $key): string {
    $key = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key);
    $key = strtolower($key);
    $key = (string) preg_replace('/[^a-z0-9]+/', '_', $key);

    return trim($key, '_');
  }

  /**
   * @param mixed $value
   * @return mixed
   */
  private function walk($value, int $depth) {
    if ($depth > self::MAX_DEPTH) {
      return $value;
    }

    if (is_string($value)) {
      return $this->jsonString($value, $depth);
    }

    if (!is_array($value)) {
      return $value;
    }

    // `{name: "password", value: "..."}` and `{type: "password", value: "..."}`
    // carry the field name as data. Form plugins and WooCommerce meta rows all
    // look like this.
    $isPair = $this->isCredentialPair($value);

    foreach ($value as $key => $item) {
      if (is_string($key) && (self::isCredentialKey($key) || ($isPair && in_array($key, self::VALUE_KEYS, true)))) {
        $value[$key] = $this->blank($item);
        continue;
      }

      $value[$key] = $this->walk($item, $depth + 1);
    }

    return $value;
  }

  /**
   * Remove a value, keeping its place. Empty strings, null and booleans stay:
   * an unfilled field is not a password, and `has_password: true` is a flag.
   *
   * @param mixed $value
   * @return mixed
   */
  private function blank($value, int $depth = 0) {
    if (is_array($value)) {
      if ($depth > self::MAX_DEPTH) {
        return [];
      }
      foreach ($value as $key => $item) {
        $value[$key] = $this->blank($item, $depth + 1);
      }
      return $value;
    }

    if ($value === null || $value === '' || is_bool($value)) {
      return $value;
    }

    if (is_string($value) || is_int($value) || is_float($value)) {
      $this->removed[(string) $value] = true;
      return self::REPLACEMENT;
    }

    return $value;
  }

  /**
   * @param array<mixed> $value
   */
  private function isCredentialPair(array $value): bool {
    $hasValue = false;
    foreach (self::VALUE_KEYS as $valueKey) {
      if (array_key_exists($valueKey, $value)) {
        $hasValue = true;
        break;
      }
    }
    if (!$hasValue) {
      return false;
    }

    if (isset($value['type']) && is_string($value['type']) && preg_match('/(^|_)password$/', self::normalizeKey($value['type']))) {
      return true;
    }

    foreach (self::NAME_KEYS as $nameKey) {
      if (isset($value[$nameKey]) && is_string($value[$nameKey]) && self::isCredentialKey($value[$nameKey])) {
        return true;
      }
    }

    return false;
  }

  /**
   * A string that holds JSON gets the same treatment as an array. Response
   * bodies are stored this way, and an endpoint that echoes the request hands
   * the whole payload back inside one.
   */
  private function jsonString(string $value, int $depth): string {
    // Cheap test first: this runs on every string in every payload.
    if (strlen($value) < 8 || !preg_match('/pass|pwd|pswd|activation_key/i', $value)) {
      return $value;
    }

    $decoded = self::decodeJson($value);
    if ($decoded === null) {
      return $value;
    }

    $cleaned = $this->walk($decoded, $depth + 1);

    return $cleaned === $decoded ? $value : self::encodeJson($cleaned);
  }

  /**
   * @return array<mixed>|null
   */
  private static function decodeJson(string $value): ?array {
    $trimmed = ltrim($value);
    if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
      return null;
    }

    $decoded = json_decode($value, true);

    return is_array($decoded) ? $decoded : null;
  }

  /**
   * @param array<mixed> $value
   */
  private static function encodeJson(array $value): string {
    $json = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    // Never hand back the original on failure: it is the one known to hold the value.
    return $json === false ? '' : $json;
  }
}
