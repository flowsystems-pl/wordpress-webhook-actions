<script setup>
import { computed } from 'vue';
import { ArrowRight } from 'lucide-vue-next';
import { fieldMeta } from '@/lib/aiLabels';
import { __ } from '@/i18n';

// What a plan step sets up (its input) and what came back (its result), in
// words. Opened from the Build progress list, so a finished build can be read
// back step by step instead of only "Done". Abilities without a dedicated view
// fall back to their scalar inputs; "Raw data" always has the full JSON.
const props = defineProps({
  step: { type: Object, required: true },
  credentials: { type: Array, default: () => [] },
});

const input = computed(() => props.step.input || {});
const result = computed(() => props.step.result || null);
const ran = computed(() => props.step.status === 'done' || props.step.status === 'failed');

// Same wording as ConditionsEditor's operator picker, so the msgids are shared.
const OPERATOR_LABELS = {
  equals: __('equals'),
  not_equals: __('does not equal'),
  contains: __('contains'),
  not_contains: __('does not contain'),
  greater_than: __('greater than'),
  less_than: __('less than'),
  is_empty: __('is empty'),
  is_not_empty: __('is not empty'),
  is_true: __('is true'),
  is_false: __('is false'),
  array_contains: __('array contains'),
  object_contains: __('object contains'),
};

function credentialLabel(id) {
  if (id === null || id === undefined || id === '') return '';
  const c = props.credentials.find((x) => String(x.id) === String(id));
  if (!c) return `#${id}`;
  return c.header_name ? `${c.name} (${c.header_name})` : c.name;
}

function show(value) {
  if (value === null || value === undefined) return '—';
  if (Array.isArray(value)) return value.join(', ');
  if (typeof value === 'boolean') return value ? __('Yes') : __('No');
  return String(value);
}

// Key/value rows for webhook-level settings and the generic fallback.
const rows = computed(() => {
  const i = input.value;
  const out = [];
  const add = (label, value, mono = false) => {
    if (value !== undefined && value !== null && value !== '') out.push({ label, value: show(value), mono });
  };

  switch (props.step.ability) {
    case 'create_webhook':
    case 'update_webhook':
      add(__('Name'), i.name);
      if (i.endpoint_url) add(__('Endpoint'), `${i.http_method || 'POST'} ${i.endpoint_url}`, true);
      add(__('Triggers'), i.triggers, true);
      if (typeof i.is_synchronous === 'boolean') {
        add(__('Delivery'), i.is_synchronous ? __('Synchronous (inline)') : __('Background (queued, retried)'));
      }
      add(__('Credential'), credentialLabel(i.auth_credential_id));
      add(__('Webhook'), result.value?.webhook?.id ? `#${result.value.webhook.id}` : (i.id ? `#${i.id}` : null));
      break;
    case 'assign_credential':
      add(__('Webhook'), i.webhook_id ? `#${i.webhook_id}` : null);
      add(__('Credential'), credentialLabel(i.credential_id));
      break;
    case 'set_mapping':
    case 'set_conditions':
      add(__('Webhook'), i.webhook_id ? `#${i.webhook_id}` : null);
      add(__('Trigger'), i.trigger, true);
      break;
    case 'enable_webhook':
      add(__('Webhook'), i.id ? `#${i.id}` : null);
      if (result.value && typeof result.value.is_enabled === 'boolean') {
        add(__('Status'), result.value.is_enabled ? __('Live') : __('Disabled'));
      }
      break;
    case 'assign_snippet':
      add(__('Webhook'), i.webhook_id ? `#${i.webhook_id}` : null);
      add(__('Trigger'), i.trigger, true);
      add(__('Stage'), i.stage === 'post' ? __('After delivery') : __('Before delivery'));
      add(__('Snippet'), i.snippet_id ? `#${i.snippet_id}` : null);
      break;
    case 'test_dispatch':
      add(__('Webhook'), i.webhook_id ? `#${i.webhook_id}` : null);
      break;
    default:
      for (const [key, value] of Object.entries(i)) {
        // Code is rendered by the panel; objects live in Raw data.
        if (key === 'code' || (typeof value === 'object' && value !== null && !Array.isArray(value))) continue;
        add(fieldMeta(key).label, value, typeof value === 'string' && /^(args\.|https?:)/.test(value));
      }
  }
  return out;
});

const mapping = computed(() => {
  const m = input.value.field_mapping;
  if (props.step.ability !== 'set_mapping' || !m) return null;
  return {
    pairs: Array.isArray(m.mappings) ? m.mappings : [],
    includeUnmapped: !!m.includeUnmapped,
  };
});

const conditions = computed(() => {
  const c = input.value.conditions;
  if (props.step.ability !== 'set_conditions' || !c) return null;
  return {
    any: c.type === 'or',
    rules: Array.isArray(c.rules) ? c.rules : [],
  };
});

const test = computed(() => {
  if (props.step.ability !== 'test_dispatch' || !result.value) return null;
  const r = result.value;
  const code = Number(r.http_code) || 0;
  return {
    code: r.http_code,
    ok: code >= 200 && code < 300,
    source: r.payload_source,
    mapped: r.mapping_applied,
    glue: r.glue_applied,
    response: typeof r.response === 'string' ? r.response.slice(0, 600) : r.response ? JSON.stringify(r.response).slice(0, 600) : '',
  };
});

const raw = computed(() => JSON.stringify({ input: props.step.input ?? null, result: props.step.result ?? null }, null, 2));
</script>

<template>
  <div class="rounded-md border border-border bg-muted/30 p-3 space-y-3 text-sm">
    <div class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
      {{ ran ? __('What this step set up') : __('What this step will set up') }}
    </div>

    <dl v-if="rows.length" class="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1.5">
      <template v-for="row in rows" :key="row.label">
        <dt class="text-muted-foreground">{{ row.label }}</dt>
        <dd :class="['min-w-0 break-all', row.mono && 'font-mono text-xs leading-5']">{{ row.value }}</dd>
      </template>
    </dl>

    <!-- set_mapping: what leaves WordPress, field by field -->
    <div v-if="mapping" class="space-y-1">
      <div class="text-muted-foreground">{{ __('Field mapping') }}</div>
      <ul class="space-y-1">
        <li v-for="(pair, i) in mapping.pairs" :key="i"
          class="flex flex-wrap items-center gap-1.5 font-mono text-xs">
          <span class="rounded bg-muted px-1.5 py-0.5 break-all">{{ pair.source }}</span>
          <ArrowRight class="w-3 h-3 shrink-0 text-muted-foreground" />
          <span class="rounded bg-primary/10 px-1.5 py-0.5 break-all">{{ pair.target }}</span>
        </li>
      </ul>
      <p class="text-xs text-muted-foreground">
        {{ mapping.includeUnmapped ? __('Unmapped fields are sent as well.') : __('Only these fields are sent.') }}
      </p>
    </div>

    <!-- set_conditions: when the webhook fires -->
    <div v-if="conditions" class="space-y-1">
      <div class="text-muted-foreground">
        {{ conditions.any ? __('Sends when ANY of these is true') : __('Sends only when ALL of these are true') }}
      </div>
      <ul class="space-y-1">
        <li v-for="(rule, i) in conditions.rules" :key="i" class="flex flex-wrap items-center gap-1.5 text-xs">
          <span class="font-mono rounded bg-muted px-1.5 py-0.5 break-all">{{ rule.field }}</span>
          <span class="text-muted-foreground">{{ OPERATOR_LABELS[rule.operator] || rule.operator }}</span>
          <span v-if="rule.value !== undefined && rule.value !== ''"
            class="font-mono rounded bg-primary/10 px-1.5 py-0.5 break-all">{{ rule.value }}</span>
        </li>
      </ul>
    </div>

    <!-- test_dispatch: what the endpoint answered -->
    <div v-if="test" class="space-y-1.5">
      <div class="flex flex-wrap items-center gap-2">
        <span :class="['rounded px-1.5 py-0.5 font-mono text-xs font-semibold',
          test.ok ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400' : 'bg-destructive/15 text-destructive']">
          HTTP {{ test.code }}
        </span>
        <span v-if="test.source" class="text-xs text-muted-foreground">{{ __('Payload') }}: {{ test.source }}</span>
        <span v-if="test.mapped" class="text-xs text-muted-foreground">· {{ __('mapping applied') }}</span>
        <span v-if="test.glue" class="text-xs text-muted-foreground">· {{ __('Code Glue applied') }}</span>
      </div>
      <pre v-if="test.response"
        class="max-h-40 overflow-auto rounded bg-muted p-2 font-mono text-xs whitespace-pre-wrap break-all">{{ test.response }}</pre>
    </div>

    <p v-if="step.status === 'failed' && step.error" class="text-sm text-destructive">{{ step.error }}</p>

    <details class="text-xs">
      <summary class="cursor-pointer select-none text-muted-foreground hover:text-foreground">{{ __('Raw data') }}</summary>
      <pre class="mt-2 max-h-64 overflow-auto rounded bg-muted p-2 font-mono whitespace-pre break-normal">{{ raw }}</pre>
    </details>
  </div>
</template>
