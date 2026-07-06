// Human-friendly labels for the AI Builder: turns ability names and raw input
// keys (endpoint_url, http_method, …) into readable titles, help text and example
// placeholders, so the step UI reads like a form, not a JSON dump.

export function humanize(key) {
  return String(key || '')
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase());
}

// Per-field overrides. `placeholder` shows a real example, never "Enter a value…".
const FIELD_META = {
  endpoint_url: {
    label: 'Endpoint URL',
    help: 'Add your n8n endpoint — the URL the webhook will POST to.',
    placeholder: 'https://n8n.io/webhook/abc123',
  },
  url: {
    label: 'URL',
    help: 'The endpoint to test before going live.',
    placeholder: 'https://n8n.io/webhook/abc123',
  },
  name: { label: 'Name', placeholder: 'CF7 → n8n' },
  http_method: { label: 'HTTP method' },
  trigger: { label: 'Trigger', placeholder: 'wpcf7_mail_sent' },
  triggers: { label: 'Triggers' },
  webhook_id: { label: 'Webhook' },
  field_mapping: { label: 'Field mapping' },
  auth_credential_id: { label: 'Credential' },
  id: { label: 'ID' },
};

export function fieldMeta(key) {
  return { label: humanize(key), help: '', placeholder: '', ...(FIELD_META[key] || {}) };
}

// Friendly title for a step: prefer the ability's catalog label, else humanize.
export function abilityTitle(abilities, name) {
  return abilities?.[name]?.label || humanize(name);
}

// Short, laconic names for the aside progress list (2–3 words max).
const SHORT_LABELS = {
  list_triggers: 'Check triggers',
  list_webhooks: 'List webhooks',
  get_webhook: 'Get webhook',
  get_trigger_schema: 'Capture payload',
  get_logs: 'Read logs',
  list_credentials: 'List credentials',
  create_webhook: 'Create webhook',
  update_webhook: 'Update webhook',
  set_mapping: 'Map fields',
  set_conditions: 'Set conditions',
  assign_credential: 'Assign credential',
  create_chain: 'Create chain',
  create_chain_link: 'Link chain',
  probe_endpoint: 'Probe endpoint',
  test_dispatch: 'Test delivery',
  enable_webhook: 'Enable webhook',
  delete_webhook: 'Delete webhook',
};

export function shortLabel(name) {
  return SHORT_LABELS[name] || humanize(name);
}
