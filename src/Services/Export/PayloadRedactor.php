<?php

namespace FlowSystems\WebhookActions\Services\Export;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Support\CredentialScrubber;

/**
 * Replaces personal data in captured example payloads with stable placeholders.
 *
 * A captured payload is the one part of a build nobody wrote: the plugin
 * recorded whatever a real visitor actually submitted — a WooCommerce order
 * carries a billing address, an email, a phone number, the customer's IP and
 * user agent. That is fine on the site that captured it and unacceptable in a
 * file someone downloads or a page anyone can read, so redaction is the default
 * everywhere and mandatory when publishing.
 *
 * Shape is preserved on purpose. The example payload exists so the importer can
 * preview field mappings, and a mapping preview needs the same keys, the same
 * nesting and the same types — so every replacement is a plausible value of the
 * same kind (RFC 2606 / RFC 5737 reserved values where they exist), never a
 * deleted key.
 *
 * Deliberately NOT redacted: field mappings, conditions, headers and URL
 * params. Those are configuration the author wrote and reviewed, and blanking
 * them would destroy what the build means.
 */
class PayloadRedactor {
  /** Guard against a pathological payload; deeper values are dropped, not walked. */
  private const MAX_DEPTH = 30;

  public const REDACTED = '[redacted]';

  /**
   * Key patterns → placeholder. Matched against the key with any leading
   * `_`, and any `billing_`/`shipping_`/`customer_`/`user_` prefix, removed —
   * so `_billing_email`, `customer_email` and `email` all hit the same rule.
   *
   * Ordered: the first pattern that matches wins, so put the specific ones
   * ("address_index") ahead of the general ones ("address").
   *
   * @var array<string, string>
   */
  private const KEY_RULES = [
    // Identity
    '/^(first_?name|given_?name)$/'                         => 'Jane',
    '/^(last_?name|sur_?name|family_?name)$/'               => 'Doe',
    '/^(full_?name|display_?name|contact_?name|sender_?name)$/' => 'Jane Doe',
    '/^(user_?login|user_?nicename|nickname|username|handle)$/' => 'janedoe',
    '/e_?mail(_address)?$/'                                 => 'jane.doe@example.com',
    '/^(phone|telephone|tel|mobile|phone_?number)$/'         => '+1 555 0100',

    // Where they live
    '/address_?index$/'                                     => '1 Example Street, Example City, 00000',
    '/^(address(_?[12])?|street(_?address)?|address_?line_?[12])$/' => '1 Example Street',
    '/^(city|town|locality)$/'                              => 'Example City',
    '/^(post_?code|postal_?code|zip(_?code)?)$/'             => '00000',
    '/^(company|organi[sz]ation|organization_?name)$/'       => 'Example Ltd',
    '/^(lat|latitude|lng|long|longitude)$/'                  => '0',

    // Machine fingerprints
    '/ip(_?address)?$/'                                     => '203.0.113.10',
    '/user_?agent$/'                                        => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',

    // Identifiers that unlock something
    '/^(order_?key|cart_?hash|transaction_?id|session_?id|nonce)$/' => self::REDACTED,
    '/(password|passwd|secret|token|api_?key|authori[sz]ation|signature|private_?key)$/' => self::REDACTED,
    '/^(vat(_?number)?|tax_?id|nip|ssn|national_?id|passport(_?number)?|iban|account_?number|card_?number|cc_?number)$/' => self::REDACTED,
    '/^(dob|date_?of_?birth|birth_?date|birthday)$/'         => '1970-01-01',
  ];

  /** Prefixes stripped before matching, so `_billing_email` matches `email`. */
  private const KEY_PREFIXES = ['billing_', 'shipping_', 'customer_', 'user_', 'order_', 'client_', 'buyer_'];

  /**
   * Redact a captured payload, preserving its shape.
   *
   * @param mixed $value
   * @return mixed
   */
  public function redact($value, int $depth = 0) {
    if ($depth > self::MAX_DEPTH) {
      return null;
    }

    if (!is_array($value)) {
      return is_string($value) ? $this->redactText($value) : $value;
    }

    // A `{key: "_billing_email", value: "…"}` pair carries the field name as
    // data, not as structure — WooCommerce meta_data, ACF fields and most form
    // plugins all look like this. Judge such a pair by the name it declares.
    $declaredName = $this->declaredName($value);
    if ($declaredName !== null) {
      $placeholder = $this->placeholderFor($declaredName);
      if ($placeholder !== null && !is_array($value['value'])) {
        $value['value'] = $this->typed($value['value'], $placeholder);
        return $value;
      }
    }

    $out = [];
    foreach ($value as $key => $item) {
      $placeholder = is_string($key) ? $this->placeholderFor($key) : null;

      if ($placeholder !== null && !is_array($item)) {
        $out[$key] = $this->typed($item, $placeholder);
        continue;
      }

      $out[$key] = $this->redact($item, $depth + 1);
    }

    return $out;
  }

  /**
   * The field name a key/value pair declares for itself, or null when this is
   * an ordinary array.
   *
   * @param array<string, mixed> $value
   */
  private function declaredName(array $value): ?string {
    if (!array_key_exists('value', $value)) {
      return null;
    }

    foreach (['key', 'name', 'field', 'label'] as $nameKey) {
      if (isset($value[$nameKey]) && is_string($value[$nameKey]) && $value[$nameKey] !== '') {
        return $value[$nameKey];
      }
    }

    return null;
  }

  /**
   * Value-level redaction for free text — a Build-with-AI transcript quotes
   * payload values back at the user, so prose needs the same treatment even
   * though it has no keys to match on.
   */
  public function redactText(string $text): string {
    if ($text === '') {
      return $text;
    }

    // Email addresses.
    $text = (string) preg_replace(
      '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/',
      'jane.doe@example.com',
      $text
    );

    // IPv4, excluding anything that is part of a longer dotted string.
    $text = (string) preg_replace(
      '/(?<![\d.])(?:\d{1,3}\.){3}\d{1,3}(?![\d.])/',
      '203.0.113.10',
      $text
    );

    // International phone numbers: a leading + and at least eight digits, so
    // order totals and IDs are left alone.
    $text = (string) preg_replace(
      '/\+\d[\d\s().\-]{6,}\d/',
      '+1 555 0100',
      $text
    );

    return $text;
  }

  /**
   * The placeholder for a key, or null when the key carries nothing personal.
   */
  private function placeholderFor(string $key): ?string {
    // Passwords first, by the same rule that keeps them out of deliveries. The
    // patterns below match `password` but not `user_pass`: stripping the
    // `user_` prefix leaves a bare `pass`, which nothing here recognised, so a
    // build exported from a user_register capture carried the password.
    if (CredentialScrubber::isCredentialKey($key)) {
      return self::REDACTED;
    }

    $normalized = ltrim(strtolower($key), '_');

    // Whole key first, then the prefix-stripped form. Order matters: stripping
    // `order_` off `order_key` leaves a bare `key`, and stripping `user_` off
    // `user_agent` leaves `agent` — neither of which means anything.
    $candidates = [$normalized];

    foreach (self::KEY_PREFIXES as $prefix) {
      if (strncmp($normalized, $prefix, strlen($prefix)) === 0) {
        $candidates[] = substr($normalized, strlen($prefix));
        break;
      }
    }

    foreach ($candidates as $candidate) {
      foreach (self::KEY_RULES as $pattern => $placeholder) {
        if (preg_match($pattern, $candidate)) {
          return $placeholder;
        }
      }
    }

    return null;
  }

  /**
   * Keep the JSON type the importer's preview expects: a number stays a number,
   * an empty string stays empty (an unfilled field is not personal data), null
   * stays null.
   *
   * @param mixed $original
   * @return mixed
   */
  private function typed($original, string $placeholder) {
    if ($original === null || $original === '' || $original === false) {
      return $original;
    }

    if (is_int($original) || is_float($original)) {
      return is_numeric($placeholder) ? 0 + $placeholder : 0;
    }

    if (is_bool($original)) {
      return $original;
    }

    return $placeholder;
  }
}
