<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

class ConditionEvaluator {

  /**
   * Evaluate conditions config against a payload.
   *
   * @param array $conditions Decoded conditions array.
   * @param array $payload    The transformed payload to test against.
   * @return array{passed: bool, failed_rule: array|null}
   */
  public function evaluate(array $conditions, array $payload): array {
    if (empty($conditions)) {
      return ['passed' => true, 'failed_rule' => null];
    }

    if (empty($conditions['enabled'])) {
      return ['passed' => true, 'failed_rule' => null];
    }

    $rules = $conditions['rules'] ?? [];
    if (empty($rules)) {
      return ['passed' => true, 'failed_rule' => null];
    }

    $type = isset($conditions['type']) && $conditions['type'] === 'or' ? 'or' : 'and';

    $lastRule = null;
    foreach ($rules as $rule) {
      $lastRule   = $rule;
      $ruleResult = isset($rule['type']) && $rule['type'] === 'group'
        ? $this->evaluateGroup($rule, $payload)
        : $this->evaluateRule($rule, $payload);

      if ($type === 'and' && !$ruleResult) {
        return ['passed' => false, 'failed_rule' => $rule];
      }
      if ($type === 'or' && $ruleResult) {
        return ['passed' => true, 'failed_rule' => null];
      }
    }

    if ($type === 'or') {
      return ['passed' => false, 'failed_rule' => $lastRule];
    }

    return ['passed' => true, 'failed_rule' => null];
  }

  /**
   * Evaluate a group item (has its own match type and nested rules).
   *
   * @param array $group   {type: 'group', match: 'and'|'or', rules: array}
   * @param array $payload
   * @return bool
   */
  private function evaluateGroup(array $group, array $payload): bool {
    $rules = $group['rules'] ?? [];
    if (empty($rules)) {
      return true;
    }

    $match = isset($group['match']) && $group['match'] === 'or' ? 'or' : 'and';

    foreach ($rules as $rule) {
      $result = $this->evaluateRule($rule, $payload);
      if ($match === 'and' && !$result) return false;
      if ($match === 'or'  && $result)  return true;
    }

    return $match === 'and';
  }

  /**
   * Resolve a dot-notation field path from the payload.
   * Returns null when the path does not exist.
   *
   * @param string $path e.g. "data.form_id" or "event.id"
   * @param array  $data
   * @return mixed
   */
  private function getField(string $path, array $data): mixed {
    $segments = explode('.', $path);
    $current  = $data;

    foreach ($segments as $segment) {
      if (!is_array($current) || !array_key_exists($segment, $current)) {
        return null;
      }
      $current = $current[$segment];
    }

    return $current;
  }

  /**
   * Evaluate a single rule against the payload.
   *
   * @param array $rule    {field, operator, value}
   * @param array $payload
   * @return bool
   */
  private function evaluateRule(array $rule, array $payload): bool {
    $field    = $rule['field'] ?? '';
    $operator = $rule['operator'] ?? '';
    $value    = $rule['value'] ?? '';

    if (empty($field) || empty($operator)) {
      return false;
    }

    $actual = $this->getField($field, $payload);

    $cast = $rule['cast'] ?? null;
    if ($cast !== null && $actual !== null) {
      $actual = match ($cast) {
        'number'    => (float) $actual,
        'string'    => (string) $actual,
        'boolean'   => filter_var($actual, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $actual,
        'stringify' => is_array($actual) || is_object($actual) ? wp_json_encode($actual) : (string) $actual,
        default     => $actual,
      };
    }

    switch ($operator) {
      case 'equals':
        return $actual !== null && (string) $actual === (string) $value;

      case 'not_equals':
        return (string) $actual !== (string) $value;

      case 'contains':
        return $actual !== null && str_contains((string) $actual, (string) $value);

      case 'not_contains':
        return !str_contains((string) $actual, (string) $value);

      case 'greater_than':
        return $actual !== null
          && is_numeric($actual)
          && is_numeric($value)
          && (float) $actual > (float) $value;

      case 'less_than':
        return $actual !== null
          && is_numeric($actual)
          && is_numeric($value)
          && (float) $actual < (float) $value;

      case 'is_empty':
        return $actual === null || $actual === '' || $actual === [];

      case 'is_not_empty':
        return $actual !== null && $actual !== '' && $actual !== [];

      case 'array_contains':
        if (!is_array($actual)) return false;
        return in_array((string) $value, array_map('strval', $actual), true);

      case 'object_contains':
        if (!is_array($actual) && !is_object($actual)) return false;
        $key = isset($rule['key']) && $rule['key'] !== '' ? (string) $rule['key'] : null;
        if ($key !== null) {
          return $this->deepContainsEntry((array) $actual, $key, (string) $value);
        }
        return $this->deepContainsValue((array) $actual, (string) $value);

      case 'is_true':
        return in_array(strtolower((string) $actual), ['true', '1', 'yes'], true)
          || $actual === true;

      case 'is_false':
        return $actual === null
          || $actual === false
          || in_array(strtolower((string) $actual), ['false', '0', 'no', ''], true);

      default:
        return false;
    }
  }

  private function deepContainsValue(array $data, string $search): bool {
    foreach ($data as $v) {
      if (is_array($v) || is_object($v)) {
        if ($this->deepContainsValue((array) $v, $search)) return true;
      } elseif ((string) $v === $search) {
        return true;
      }
    }
    return false;
  }

  private function deepContainsEntry(array $data, string $key, string $value): bool {
    if (array_key_exists($key, $data) && (string) $data[$key] === $value) return true;
    foreach ($data as $v) {
      if (is_array($v) || is_object($v)) {
        if ($this->deepContainsEntry((array) $v, $key, $value)) return true;
      }
    }
    return false;
  }
}
