<?php

namespace FlowSystems\WebhookActions\Integrations;

defined('ABSPATH') || exit;

use IvyForms\Entity\Field\Field;

/**
 * IvyForms integration.
 *
 * Normalizes IvyForms\Entity\Field\Field objects into clean, webhook-friendly
 * arrays via the fswa_normalize_object filter.
 *
 * For submission hooks (ivyforms/form/before_submission,
 * ivyforms/form/after_submission) the raw args structure is replaced with a
 * single `submission` key that cross-references field definitions with the
 * submitted values — so the webhook receiver gets labelled fields instead of
 * numeric keys.
 */
class IvyFormsIntegration {

  private const SUBMISSION_HOOKS = [
    'ivyforms/form/before_submission',
    'ivyforms/form/after_submission',
  ];

  public function register(): void {
    add_filter('fswa_normalize_object', [$this, 'normalizeObject'], 10, 2);
    add_filter('fswa_payload',          [$this, 'transformSubmissionPayload'], 10, 2);
  }

  /**
   * Normalize a Field entity for any hook that passes one as an argument.
   */
  public function normalizeObject(mixed $data, object $value): mixed {
    if ($value instanceof Field) {
      return $this->normalizeField($value);
    }

    return $data;
  }

  /**
   * Enrich each already-normalized Field entry in args[2] with the submitted
   * value from args[1], so the webhook receiver gets labelled fields without
   * needing to cross-reference two separate arrays.
   *
   * Hook signature: (int $formId, array $submissionData, Field[] $formFields, int|null $entryId)
   */
  public function transformSubmissionPayload(array $payload, string $trigger): array {
    if (!in_array($trigger, self::SUBMISSION_HOOKS, true)) {
      return $payload;
    }

    $submissionData = $payload['args'][1] ?? [];

    if (!isset($payload['args'][2]) || !is_array($payload['args'][2])) {
      return $payload;
    }

    foreach ($payload['args'][2] as &$fieldData) {
      $fieldId = $fieldData['id'] ?? null;
      if ($fieldId === null) {
        continue;
      }
      $fieldData['value'] = $submissionData[(string) $fieldId]
        ?? $submissionData[$fieldId]
        ?? null;
    }
    unset($fieldData);

    return $payload;
  }

  private function normalizeField(Field $field): array {
    return [
      'id'       => $field->getId(),
      'label'    => $field->getFieldGeneralSettings()->getLabel(),
      'type'     => $field->getType(),
      'required' => $field->getFieldGeneralSettings()->isRequired(),
    ];
  }
}
