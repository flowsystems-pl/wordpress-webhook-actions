/**
 * Internationalization helpers for the Webhook Actions admin SPA.
 *
 * Thin wrappers around @wordpress/i18n with the plugin text domain baked in,
 * so call sites read `__('Webhooks')` instead of repeating the domain everywhere.
 * `@wordpress/i18n` is externalized to the global `wp.i18n` at build time and the
 * matching JSON translations are loaded via `wp_set_script_translations()` in PHP.
 *
 * Usage in a component `<script setup>`:
 *   import { __, _n, sprintf } from '@/i18n'
 *   const label = __('Webhooks')
 *   const msg = sprintf(__('Deleted %d webhooks.'), count)
 * Imported bindings are auto-exposed to the template, so `{{ __('Save') }}` works.
 * A global `$t` / `__` is also registered in main.js for template-only files.
 */
import {
  __ as wp__,
  _x as wp_x,
  _n as wp_n,
  _nx as wp_nx,
  sprintf as wpSprintf,
} from '@wordpress/i18n'

export const DOMAIN = 'flowsystems-webhook-actions'

export function __(text) {
  return wp__(text, DOMAIN)
}

export function _x(text, context) {
  return wp_x(text, context, DOMAIN)
}

export function _n(single, plural, number) {
  return wp_n(single, plural, number, DOMAIN)
}

export function _nx(single, plural, number, context) {
  return wp_nx(single, plural, number, context, DOMAIN)
}

export const sprintf = wpSprintf
