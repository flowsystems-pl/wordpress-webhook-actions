<?php

namespace FlowSystems\WebhookActions\Api;

defined('ABSPATH') || exit;

use FlowSystems\WebhookActions\Services\RetryPolicy;

/**
 * The JSON Schema for a webhook resource.
 *
 * Lives apart from {@see WebhooksController} because it is a large, purely
 * declarative block that says nothing about how the controller behaves — the
 * same split the ability definitions get in AbilityCatalog.
 */
class WebhookSchema {
  /**
   * @return array<string, mixed>
   */
  public static function definition(): array {
    return [
    '$schema' => 'http://json-schema.org/draft-04/schema#',
    'title' => 'webhook',
    'type' => 'object',
    'properties' => [
      'id' => [
        'description' => __('Unique identifier for the webhook.', 'flowsystems-webhook-actions'),
        'type' => 'integer',
        'context' => ['view', 'edit'],
        'readonly' => true,
      ],
      'name' => [
        'description' => __('Name of the webhook.', 'flowsystems-webhook-actions'),
        'type' => 'string',
        'context' => ['view', 'edit'],
        'required' => true,
      ],
      'description' => [
        'description' => __('Optional markdown description documenting what this webhook does.', 'flowsystems-webhook-actions'),
        'type' => 'string',
        'context' => ['view', 'edit'],
      ],
      'endpoint_url' => [
        'description' => __('URL to send the webhook to.', 'flowsystems-webhook-actions'),
        'type' => 'string',
        'format' => 'uri',
        'context' => ['view', 'edit'],
        'required' => true,
      ],
      'auth_header' => [
        'description' => __('Authorization header value.', 'flowsystems-webhook-actions'),
        'type' => 'string',
        'context' => ['view', 'edit'],
      ],
      'auth_credential_id' => [
        'description' => __('ID of a vault credential to use for authorization. Takes precedence over auth_header.', 'flowsystems-webhook-actions'),
        'type' => ['integer', 'null'],
        'context' => ['view', 'edit'],
      ],
      'http_method' => [
        'description' => __('HTTP method used for delivery.', 'flowsystems-webhook-actions'),
        'type'        => 'string',
        'enum'        => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
        'default'     => 'POST',
        'context'     => ['view', 'edit'],
      ],
      'custom_headers' => [
        'description' => __('Extra request headers as key-value pairs.', 'flowsystems-webhook-actions'),
        'type'        => 'array',
        'context'     => ['view', 'edit'],
        'items'       => [
          'type'       => 'object',
          'properties' => [
            'key'   => ['type' => 'string'],
            'value' => ['type' => 'string'],
          ],
        ],
      ],
      'url_params' => [
        'description' => __('Query parameters appended to the URL.', 'flowsystems-webhook-actions'),
        'type'        => 'array',
        'context'     => ['view', 'edit'],
        'items'       => [
          'type'       => 'object',
          'properties' => [
            'key'   => ['type' => 'string'],
            'value' => ['type' => 'string'],
          ],
        ],
      ],
      'is_enabled' => [
        'description' => __('Whether the webhook is enabled.', 'flowsystems-webhook-actions'),
        'type' => 'boolean',
        'context' => ['view', 'edit'],
        'default' => true,
      ],
      'is_synchronous' => [
        'description' => __('Whether the webhook executes synchronously (blocking, bypasses queue).', 'flowsystems-webhook-actions'),
        'type' => 'boolean',
        'context' => ['view', 'edit'],
        'default' => false,
      ],
      'retry_limit' => [
        'description' => __('Maximum delivery attempts for this webhook. Null inherits the site-wide setting, then the default of 5.', 'flowsystems-webhook-actions'),
        'type'        => ['integer', 'null'],
        'minimum'     => RetryPolicy::MAX_ATTEMPTS_MIN,
        'maximum'     => RetryPolicy::MAX_ATTEMPTS_MAX,
        'context'     => ['view', 'edit'],
      ],
      'backoff_strategy' => [
        'description' => __('How the wait between retries grows. Null inherits the site-wide setting, then exponential.', 'flowsystems-webhook-actions'),
        'type'        => ['string', 'null'],
        'enum'        => [...RetryPolicy::STRATEGIES, null],
        'context'     => ['view', 'edit'],
      ],
      'backoff_base_delay' => [
        'description' => __('Base wait in seconds the backoff strategy multiplies. Null inherits the site-wide setting, then 30.', 'flowsystems-webhook-actions'),
        'type'        => ['integer', 'null'],
        'minimum'     => RetryPolicy::DELAY_MIN,
        'maximum'     => RetryPolicy::DELAY_MAX,
        'context'     => ['view', 'edit'],
      ],
      'backoff_max_delay' => [
        'description' => __('Ceiling in seconds for an exponential backoff. Null inherits the site-wide setting, then 3600.', 'flowsystems-webhook-actions'),
        'type'        => ['integer', 'null'],
        'minimum'     => RetryPolicy::DELAY_MIN,
        'maximum'     => RetryPolicy::DELAY_MAX,
        'context'     => ['view', 'edit'],
      ],
      'notifications_mode' => [
        'description' => __('Which notification rules apply: inherit the site-wide rules, custom rules for this webhook only, or off.', 'flowsystems-webhook-actions'),
        'type'        => 'string',
        'enum'        => ['inherit', 'custom', 'off'],
        'default'     => 'inherit',
        'context'     => ['view', 'edit'],
      ],
      'muted_rule_ids' => [
        'description' => __('IDs of site-wide notification rules muted for this webhook (inherit mode only).', 'flowsystems-webhook-actions'),
        'type'        => 'array',
        'items'       => ['type' => 'integer'],
        'context'     => ['view', 'edit'],
      ],
      'triggers' => [
        'description' => __('List of trigger actions.', 'flowsystems-webhook-actions'),
        'type' => 'array',
        'items' => ['type' => 'string'],
        'context' => ['view', 'edit'],
      ],
      'created_at' => [
        'description' => __('Creation date.', 'flowsystems-webhook-actions'),
        'type' => 'string',
        'format' => 'date-time',
        'context' => ['view'],
        'readonly' => true,
      ],
      'updated_at' => [
        'description' => __('Last update date.', 'flowsystems-webhook-actions'),
        'type' => 'string',
        'format' => 'date-time',
        'context' => ['view'],
        'readonly' => true,
      ],
    ],
  ];
  }
}
