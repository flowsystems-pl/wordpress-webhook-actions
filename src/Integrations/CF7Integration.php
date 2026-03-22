<?php

namespace FlowSystems\WebhookActions\Integrations;

defined('ABSPATH') || exit;

/**
 * Contact Form 7 integration.
 *
 * Normalizes WPCF7_ContactForm and WPCF7_Submission objects into clean,
 * webhook-friendly arrays via the fswa_normalize_object filter.
 *
 * Recommended hook: wpcf7_before_send_mail (passes submission as 3rd arg).
 * Falls back gracefully on wpcf7_mail_sent / wpcf7_mail_failed where only
 * the contact form is available.
 */
class CF7Integration {

  public function register(): void {
    add_filter('fswa_normalize_object', [$this, 'normalizeObject'], 10, 2);
  }

  public function normalizeObject(mixed $data, object $value): mixed {
    if ($value instanceof \WPCF7_ContactForm) {
      return $this->normalizeContactForm($value);
    }

    if ($value instanceof \WPCF7_Submission) {
      return $this->normalizeSubmission($value);
    }

    return $data;
  }

  private function normalizeContactForm(\WPCF7_ContactForm $form, bool $includeSubmission = true): array {
    $data = [
      'id'     => $form->id(),
      'title'  => $form->title(),
      'name'   => $form->name(),
      'locale' => $form->locale(),
    ];

    // Include submission data when available (e.g. wpcf7_mail_sent passes
    // only the form object, but the submission singleton is still live).
    // $includeSubmission=false when called from normalizeSubmission to prevent recursion.
    if ($includeSubmission) {
      $submission = \WPCF7_Submission::get_instance();
      if ($submission && $submission->get_contact_form()->id() === $form->id()) {
        $data['submission'] = $this->normalizeSubmission($submission);
        unset($data['submission']['form']); // avoid redundant nesting
      }
    }

    return $data;
  }

  private function normalizeSubmission(\WPCF7_Submission $submission): array {
    $contactForm = $submission->get_contact_form();

    $data = [
      'form' => $contactForm ? $this->normalizeContactForm($contactForm, false) : null,
      'fields' => $this->sanitizePostedData($submission->get_posted_data()),
      'meta' => [
        'url'               => $submission->get_meta('url'),
        'timestamp'         => $submission->get_meta('timestamp'),
        'remote_ip'         => $submission->get_meta('remote_ip'),
        'user_agent'        => $submission->get_meta('user_agent'),
        'container_post_id' => $submission->get_meta('container_post_id'),
        'current_user_id'   => $submission->get_meta('current_user_id'),
      ],
    ];

    $uploadedFiles = $submission->uploaded_files();
    if (!empty($uploadedFiles)) {
      $data['uploaded_files'] = array_map(
        fn($path) => is_string($path) ? basename($path) : null,
        $uploadedFiles
      );
    }

    return $data;
  }

  /**
   * Strip internal CF7 fields (prefixed with _wpcf7) from posted data.
   */
  private function sanitizePostedData(array $posted): array {
    return array_filter(
      $posted,
      fn($key) => !str_starts_with($key, '_'),
      ARRAY_FILTER_USE_KEY
    );
  }
}
