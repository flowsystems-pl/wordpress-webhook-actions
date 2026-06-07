<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

/**
 * Reversible encryption for vault credentials (AES-256-GCM).
 *
 * Secrets must be decryptable at dispatch time to build the outgoing
 * Authorization/custom header, so this is authenticated symmetric encryption
 * (not one-way hashing like API tokens).
 *
 * Key material (in priority order):
 *   1. FSWA_SECRET_KEY constant (wp-config.php) — keeps the key out of the DB.
 *   2. A random 32-byte key stored once in the `fswa_vault_key` option.
 *
 * Deriving from a stored key (rather than wp_salt()) means secrets survive
 * WordPress salt rotation. The GCM auth tag makes a wrong-key or tampered
 * blob fail cleanly — decrypt() returns null instead of garbage.
 *
 * Key transition: encrypt() always uses the PRIMARY key (constant if defined,
 * else DB key). decrypt() tries ALL candidate keys (primary + legacy DB key),
 * so adding FSWA_SECRET_KEY to an existing install never breaks credentials.
 * Call reencrypt()/forgetDbKey() (see CredentialsController) to finish the
 * migration and remove the DB key once everything is re-wrapped.
 */
class CredentialCipher {
  private const CIPHER     = 'aes-256-gcm';
  private const OPTION_KEY = 'fswa_vault_key';
  private const VERSION    = 'v1';
  private const IV_LEN     = 12;
  private const TAG_LEN    = 16;

  /** FSWA_SECRET_KEY material, or null when the constant is not set. */
  private function constantKeyMaterial(): ?string {
    return (defined('FSWA_SECRET_KEY') && FSWA_SECRET_KEY) ? (string) constant('FSWA_SECRET_KEY') : null;
  }

  /**
   * Stored DB key material. Generates it once when $create is true (so the
   * default zero-config path keeps working), returns null otherwise.
   */
  private function dbKeyMaterial(bool $create): ?string {
    $stored = get_option(self::OPTION_KEY, '');
    if (is_string($stored) && $stored !== '') {
      return $stored;
    }
    if (!$create) {
      return null;
    }
    $key = base64_encode(random_bytes(32));
    // Autoload so the dispatcher can read it without an extra query per tick.
    add_option(self::OPTION_KEY, $key, '', 'yes');
    return $key;
  }

  private function deriveKey(string $material): string {
    return hash('sha256', 'fswa_vault_v1|' . $material, true);
  }

  /** The key new ciphertext is sealed with. */
  private function primaryKey(): string {
    $const = $this->constantKeyMaterial();
    if ($const !== null) {
      return $this->deriveKey($const);
    }
    return $this->deriveKey((string) $this->dbKeyMaterial(true));
  }

  /**
   * All keys decrypt() may try, primary first. When the constant is set we also
   * try the legacy DB key (if still present) so existing secrets keep working
   * during the migration window.
   *
   * @return array<int, string>
   */
  private function candidateKeys(): array {
    $keys  = [];
    $const = $this->constantKeyMaterial();

    if ($const !== null) {
      $keys[] = $this->deriveKey($const);
      $db = $this->dbKeyMaterial(false);
      if ($db !== null) {
        $keys[] = $this->deriveKey($db);
      }
    } else {
      $keys[] = $this->deriveKey((string) $this->dbKeyMaterial(true));
    }

    return $keys;
  }

  /**
   * Encrypt plaintext into a versioned, base64-encoded envelope using the
   * primary key.
   *
   * @throws \RuntimeException if encryption fails.
   */
  public function encrypt(string $plaintext): string {
    $iv  = random_bytes(self::IV_LEN);
    $tag = '';

    $ciphertext = openssl_encrypt(
      $plaintext,
      self::CIPHER,
      $this->primaryKey(),
      OPENSSL_RAW_DATA,
      $iv,
      $tag,
      '',
      self::TAG_LEN
    );

    if ($ciphertext === false) {
      throw new \RuntimeException('Credential encryption failed.');
    }

    return self::VERSION . ':' . base64_encode($iv . $tag . $ciphertext);
  }

  /**
   * Decrypt a versioned envelope, trying each candidate key.
   *
   * @return string|null Plaintext, or null if the blob is malformed or cannot
   *                     be decrypted with any known key.
   */
  public function decrypt(string $blob): ?string {
    if (strncmp($blob, self::VERSION . ':', strlen(self::VERSION) + 1) !== 0) {
      return null;
    }

    $raw = base64_decode(substr($blob, strlen(self::VERSION) + 1), true);
    if ($raw === false || strlen($raw) < self::IV_LEN + self::TAG_LEN) {
      return null;
    }

    $iv         = substr($raw, 0, self::IV_LEN);
    $tag        = substr($raw, self::IV_LEN, self::TAG_LEN);
    $ciphertext = substr($raw, self::IV_LEN + self::TAG_LEN);

    foreach ($this->candidateKeys() as $key) {
      $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
      if ($plaintext !== false) {
        return $plaintext;
      }
    }

    return null;
  }

  /**
   * Re-wrap an existing blob with the current primary key.
   *
   * @return string|null New ciphertext, or null if the source can't be decrypted.
   */
  public function reencrypt(string $blob): ?string {
    $plaintext = $this->decrypt($blob);
    return $plaintext === null ? null : $this->encrypt($plaintext);
  }

  /** True when the wp-config FSWA_SECRET_KEY constant is in use. */
  public function usingConstant(): bool {
    return $this->constantKeyMaterial() !== null;
  }

  /** True when a key is still stored in the database. */
  public function dbKeyPresent(): bool {
    $stored = get_option(self::OPTION_KEY, '');
    return is_string($stored) && $stored !== '';
  }

  /** 'constant' (wp-config) or 'database'. */
  public function keySource(): string {
    return $this->usingConstant() ? 'constant' : 'database';
  }

  /**
   * Remove the stored DB key. Only safe after every credential has been
   * re-wrapped with the constant key — otherwise those secrets become
   * permanently undecryptable.
   */
  public function forgetDbKey(): void {
    delete_option(self::OPTION_KEY);
  }
}
