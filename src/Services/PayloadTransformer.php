<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Repositories\SchemaRepository;
use WP_User;

class PayloadTransformer {
  private SchemaRepository $schemaRepository;

  /**
   * User triggers that support enrichment with the argument containing user_id
   */
  private const USER_ID_TRIGGERS = [
    'user_register',
    'profile_update',
    'delete_user',
    'set_user_role',
  ];

  /**
   * User triggers that pass WP_User object in args
   */
  private const USER_OBJECT_TRIGGERS = [
    'wp_login',
    'password_reset',
  ];

  /**
   * Triggers that use current user
   */
  private const CURRENT_USER_TRIGGERS = [
    'wp_logout',
  ];

  public function __construct() {
    $this->schemaRepository = new SchemaRepository();
  }

  /**
   * Transform a payload based on webhook/trigger schema configuration
   *
   * @param int $webhookId
   * @param string $trigger
   * @param array $payload
   * @param array $args Original trigger arguments
   * @return array{original: array|null, transformed: array, mapping_applied: bool}
   */
  public function transform(int $webhookId, string $trigger, array $payload, array $args = []): array {
    $schema = $this->schemaRepository->findByWebhookAndTrigger($webhookId, $trigger);

    // If no schema exists, capture this as an example payload
    if (!$schema) {
      $this->schemaRepository->captureExamplePayload($webhookId, $trigger, $payload);
      return [
        'original' => null,
        'transformed' => $payload,
        'mapping_applied' => false,
      ];
    }

    // If schema exists but no example captured yet, capture it
    if (empty($schema['example_payload'])) {
      $this->schemaRepository->captureExamplePayload($webhookId, $trigger, $payload);
    }

    $transformedPayload = $payload;
    $mappingApplied = false;

    // Apply user data enrichment if enabled
    if (!empty($schema['include_user_data'])) {
      $userData = $this->extractUserData($trigger, $args);
      if ($userData) {
        $transformedPayload['user'] = $userData;
        $mappingApplied = true;
      }
    }

    // Apply field mapping if configured
    $fieldMapping = $schema['field_mapping'] ?? null;
    // Handle case where field_mapping might be a JSON string (not decoded)
    if (is_string($fieldMapping)) {
      $fieldMapping = json_decode($fieldMapping, true);
    }
    if (!empty($fieldMapping) && is_array($fieldMapping)) {
      $transformedPayload = $this->applyFieldMapping($transformedPayload, $fieldMapping);
      $mappingApplied = true;
    }

    return [
      'original' => $mappingApplied ? $payload : null,
      'transformed' => $transformedPayload,
      'mapping_applied' => $mappingApplied,
    ];
  }

  /**
   * Extract user data from trigger arguments based on trigger type
   *
   * @param string $trigger
   * @param array $args
   * @return array|null
   */
  private function extractUserData(string $trigger, array $args): ?array {
    $user = null;

    // Triggers that pass user_id as first argument
    if (in_array($trigger, self::USER_ID_TRIGGERS, true)) {
      $userId = $this->findUserId($args);
      if ($userId) {
        $user = get_user_by('id', $userId);
      }
    }

    // Triggers that pass WP_User object
    if (in_array($trigger, self::USER_OBJECT_TRIGGERS, true)) {
      $user = $this->findUserObject($args);
    }

    // Triggers that use current user
    if (in_array($trigger, self::CURRENT_USER_TRIGGERS, true)) {
      $user = wp_get_current_user();
      if ($user->ID === 0) {
        $user = null;
      }
    }

    if (!$user instanceof WP_User) {
      return null;
    }

    return $this->getUserData($user);
  }

  /**
   * Find user ID from arguments
   *
   * @param array $args
   * @return int|null
   */
  private function findUserId(array $args): ?int {
    foreach ($args as $arg) {
      if (is_numeric($arg) && (int) $arg > 0) {
        return (int) $arg;
      }
    }
    return null;
  }

  /**
   * Find WP_User object from arguments
   *
   * @param array $args
   * @return WP_User|null
   */
  private function findUserObject(array $args): ?WP_User {
    foreach ($args as $arg) {
      if ($arg instanceof WP_User) {
        return $arg;
      }
    }
    return null;
  }

  /**
   * Get user data array from WP_User
   *
   * @param WP_User $user
   * @return array
   */
  public function getUserData(WP_User $user): array {
    $data = [
      'id' => $user->ID,
      'login' => $user->user_login,
      'email' => $user->user_email,
      'display_name' => $user->display_name,
      'first_name' => $user->first_name,
      'last_name' => $user->last_name,
      'roles' => $user->roles,
      'registered' => $user->user_registered,
    ];

    // Add common meta fields
    $metaKeys = ['nickname', 'description', 'locale'];
    $meta = [];
    foreach ($metaKeys as $key) {
      $value = get_user_meta($user->ID, $key, true);
      if ($value !== '') {
        $meta[$key] = $value;
      }
    }

    if (!empty($meta)) {
      $data['meta'] = $meta;
    }

    return $data;
  }

  /**
   * Apply field mapping to transform payload
   *
   * @param array $payload
   * @param array $mapping
   * @return array
   */
  private function applyFieldMapping(array $payload, array $mapping): array {
    // Mapping structure:
    // {
    //   "mappings": [{"source": "path.to.field", "target": "new.path"}],
    //   "excluded": ["path.to.exclude"],
    //   "includeUnmapped": true
    // }

    $mappings = $mapping['mappings'] ?? [];
    $excluded = $mapping['excluded'] ?? [];
    $includeUnmapped = $mapping['includeUnmapped'] ?? true;

    // Flatten the payload for easier access
    $flatPayload = $this->flattenArray($payload);

    // Build the result
    $result = [];

    // Apply explicit mappings first
    $mappedSourcePaths = [];
    foreach ($mappings as $map) {
      $sourcePath = $map['source'] ?? '';
      $targetPath = $map['target'] ?? '';

      if (empty($sourcePath) || empty($targetPath)) {
        continue;
      }

      $mappedSourcePaths[] = $sourcePath;

      // Get value from source path
      $value = $this->getValueByPath($payload, $sourcePath);

      if ($value !== null) {
        // Set value at target path
        $this->setValueByPath($result, $targetPath, $value);
      }
    }

    // Include unmapped fields if enabled
    if ($includeUnmapped) {
      foreach ($flatPayload as $path => $value) {
        // Skip if this path is mapped
        if (in_array($path, $mappedSourcePaths, true)) {
          continue;
        }

        // Skip if this path is excluded
        $isExcluded = false;
        foreach ($excluded as $excludedPath) {
          if ($path === $excludedPath || str_starts_with($path, $excludedPath . '.')) {
            $isExcluded = true;
            break;
          }
        }

        if ($isExcluded) {
          continue;
        }

        // Include this field at its original path
        $this->setValueByPath($result, $path, $value);
      }
    }

    return $result;
  }

  /**
   * Flatten an array to dot notation paths (including indexed arrays)
   *
   * @param array $array
   * @param string $prefix
   * @param int $depth Current depth
   * @param int $maxDepth Maximum depth to flatten
   * @return array
   */
  private function flattenArray(array $array, string $prefix = '', int $depth = 0, int $maxDepth = 10): array {
    $result = [];

    if ($depth > $maxDepth) {
      // Prevent infinite recursion, store as-is
      $result[$prefix] = $array;
      return $result;
    }

    foreach ($array as $key => $value) {
      $newKey = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

      if (is_array($value) && !empty($value)) {
        // Recursively flatten both sequential and associative arrays
        $result = array_merge($result, $this->flattenArray($value, $newKey, $depth + 1, $maxDepth));
      } else {
        $result[$newKey] = $value;
      }
    }

    return $result;
  }

  /**
   * Get value from array by dot-notation path
   *
   * @param array $array
   * @param string $path
   * @return mixed
   */
  private function getValueByPath(array $array, string $path) {
    $keys = explode('.', $path);
    $current = $array;

    foreach ($keys as $key) {
      if (!is_array($current)) {
        return null;
      }

      // Try both string and integer key for numeric values
      if (is_numeric($key)) {
        $intKey = (int) $key;
        if (array_key_exists($intKey, $current)) {
          $current = $current[$intKey];
          continue;
        }
      }

      if (!array_key_exists($key, $current)) {
        return null;
      }
      $current = $current[$key];
    }

    return $current;
  }

  /**
   * Set value in array by dot-notation path
   *
   * @param array $array
   * @param string $path
   * @param mixed $value
   */
  private function setValueByPath(array &$array, string $path, $value): void {
    $keys = explode('.', $path);
    $current = &$array;

    foreach ($keys as $i => $key) {
      // Convert numeric string keys to integers for proper array handling
      $arrayKey = is_numeric($key) ? (int) $key : $key;

      if ($i === count($keys) - 1) {
        $current[$arrayKey] = $value;
      } else {
        if (!isset($current[$arrayKey]) || !is_array($current[$arrayKey])) {
          // Check if next key is numeric to create array vs object
          $nextKey = $keys[$i + 1] ?? null;
          $current[$arrayKey] = ($nextKey !== null && is_numeric($nextKey)) ? [] : [];
        }
        $current = &$current[$arrayKey];
      }
    }
  }

  /**
   * Check if a trigger supports user data enrichment
   *
   * @param string $trigger
   * @return bool
   */
  public function supportsUserEnrichment(string $trigger): bool {
    return in_array($trigger, self::USER_ID_TRIGGERS, true)
      || in_array($trigger, self::USER_OBJECT_TRIGGERS, true)
      || in_array($trigger, self::CURRENT_USER_TRIGGERS, true);
  }

  /**
   * Get all triggers that support user enrichment
   *
   * @return array
   */
  public function getUserEnrichmentTriggers(): array {
    return array_merge(
      self::USER_ID_TRIGGERS,
      self::USER_OBJECT_TRIGGERS,
      self::CURRENT_USER_TRIGGERS
    );
  }
}
