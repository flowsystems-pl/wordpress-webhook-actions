<script setup>
import { ref, computed, watch } from 'vue';
import { Button, Input, Label, Switch, UpgradeBadge, Select, SelectTrigger, SelectValue, SelectContent, SelectItem, Tooltip, RadioGroup, RadioGroupItem, Dialog, Checkbox, Badge } from '@/components/ui';
import { Info, Link2, Network } from 'lucide-vue-next';
import TriggerSelect from '@/components/TriggerSelect.vue';
import ChainPicker from '@/components/ChainPicker.vue';
import KeyValueEditor from '@/components/KeyValueEditor.vue';
import { usePro } from '@/composables/usePro';
import { useSyncWarning } from '@/composables/useSyncWarning';
import { useChains, useWebhookChainInvolvement } from '@/composables/useChains';

const { proActive } = usePro();
const { dontShowAgain, isWarningDismissed, applyDismiss, resetDontShowAgain } = useSyncWarning();
const { chains, fetchChains } = useChains();

// ── helpers ───────────────────────────────────────────────────────────────────

const ordinal = (n) => {
  const s = ['th', 'st', 'nd', 'rd']
  const v = n % 100
  return n + (s[(v - 20) % 10] || s[v] || s[0])
}

const formatDelay = (seconds) => {
  if (seconds < 60) return `${seconds} second${seconds !== 1 ? 's' : ''}`
  if (seconds < 3600) {
    const m = Math.floor(seconds / 60)
    const s = seconds % 60
    const mStr = `${m} minute${m !== 1 ? 's' : ''}`
    return s > 0 ? `${mStr} ${s}s` : mStr
  }
  const h = Math.floor(seconds / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  const hStr = `${h} hour${h !== 1 ? 's' : ''}`
  return m > 0 ? `${hStr} ${m} min` : hStr
}

// ── props / emits / state ─────────────────────────────────────────────────────

const props = defineProps({
  webhook: { type: Object, default: null },
  loading: Boolean,
  examplePayload: { type: Object, default: null },
  gluePayload: { type: Object, default: null },
  initialChainMode: { type: Boolean, default: false },
});

const emit = defineEmits(['submit', 'cancel', 'change']);

const form = ref({
  name: '',
  endpoint_url: '',
  http_method: 'POST',
  auth_header: '',
  custom_headers: [],
  url_params: [],
  is_enabled: true,
  triggers: [],
  retry_limit: '',
  backoff_strategy: 'default',
  backoff_base_delay: '',
  backoff_max_delay: '',
  is_synchronous: false,
});

const useChainTrigger = ref(false);
const chainConfig = ref({
  chain_id: null,
  new_chain_name: '',
  source_webhook_ids: [],
});

const errors = ref({});
const showSyncWarning = ref(false);
const pendingSyncValue = ref(false);
const showChainSaveFirstDialog = ref(false);

const webhookIdRef = computed(() => props.webhook?.id ?? null);
const chainInvolvement = useWebhookChainInvolvement(webhookIdRef);

// ── watches ───────────────────────────────────────────────────────────────────

watch(() => props.webhook, async (webhook) => {
  if (webhook) {
    const rawTriggers = webhook.triggers || [];
    const wpTriggers = rawTriggers.filter((t) => !String(t).startsWith('fswa_chain_link:'));
    const hasChainTriggers = rawTriggers.some((t) => String(t).startsWith('fswa_chain_link:'));

    form.value = {
      name:               webhook.name || '',
      endpoint_url:       webhook.endpoint_url || '',
      http_method:        webhook.http_method || 'POST',
      auth_header:        webhook.auth_header || '',
      custom_headers:     webhook.custom_headers || [],
      url_params:         webhook.url_params || [],
      is_enabled:         webhook.is_enabled ?? true,
      triggers:           wpTriggers,
      retry_limit:        webhook.retry_limit != null ? String(webhook.retry_limit) : '',
      backoff_strategy:   webhook.backoff_strategy ?? 'default',
      backoff_base_delay: webhook.backoff_base_delay != null ? String(webhook.backoff_base_delay) : '',
      backoff_max_delay:  webhook.backoff_max_delay != null ? String(webhook.backoff_max_delay) : '',
      is_synchronous:     webhook.is_synchronous ?? false,
    };

    useChainTrigger.value = hasChainTriggers;

    // Hydrate chainConfig from existing chain involvement (webhook as TARGET).
    await fetchChains();
    const id = Number(webhook.id);
    let targetChainId = null;
    const sourceIds = new Set();
    for (const c of chains.value) {
      for (const l of (c.links || [])) {
        if (Number(l.target_webhook_id) === id) {
          targetChainId = Number(c.id);
          sourceIds.add(Number(l.source_webhook_id));
        }
      }
    }
    chainConfig.value = {
      chain_id: targetChainId,
      new_chain_name: '',
      source_webhook_ids: Array.from(sourceIds),
    };

    // Auto-enable chain mode if the parent passed initialChainMode (e.g. user
    // clicked "Save & enable chain mode" on the create page, was redirected
    // here, and we want to land them in chain mode immediately).
    if (props.initialChainMode && !hasChainTriggers) {
      useChainTrigger.value = true;
      form.value.triggers = [];
      if (!form.value.is_synchronous) form.value.is_synchronous = true;
    }
  }
}, { immediate: true });

watch(() => form.value.backoff_strategy, (val) => {
  if (val === 'default') {
    form.value.backoff_base_delay = ''
    form.value.backoff_max_delay  = ''
  }
})

watch(form, () => emit('change'), { deep: true })

// ── computed ──────────────────────────────────────────────────────────────────

const isDotPath = (val) => val && val.includes('.') && !/\s/.test(val)

const resolveByPath = (obj, path) => {
  if (!obj || !path) return undefined
  return path.split('.').reduce(
    (acc, key) => (acc != null && typeof acc === 'object' ? acc[key] : undefined),
    obj
  )
}

const urlHasTemplates = computed(() => form.value.endpoint_url.includes('{{'))

const urlTemplatePreview = computed(() => {
  if (!urlHasTemplates.value) return null
  if (!props.gluePayload && !props.examplePayload) return null
  return form.value.endpoint_url.replace(/\{\{\s*([\w][\w.]*)\s*\}\}/g, (match, path) => {
    const trimmed = path.trim()
    let value = props.gluePayload ? resolveByPath(props.gluePayload, trimmed) : undefined
    if ((value === undefined || value === null) && props.examplePayload) {
      value = resolveByPath(props.examplePayload, trimmed)
    }
    return value !== null && value !== undefined ? encodeURIComponent(String(value)) : match
  })
})

const urlParamsPreview = computed(() => {
  const params = form.value.url_params.filter(p => p.key)
  if (!params.length) return null

  const base = urlTemplatePreview.value ?? form.value.endpoint_url ?? ''
  const qs = params.map(p => {
    let displayVal = p.value || ''
    if (isDotPath(p.value)) {
      let resolved = props.gluePayload ? resolveByPath(props.gluePayload, p.value) : undefined
      if ((resolved === undefined || resolved === null) && props.examplePayload) {
        resolved = resolveByPath(props.examplePayload, p.value)
      }
      displayVal = (resolved !== undefined && resolved !== null)
        ? String(resolved)
        : `{${p.value}}`
    }
    return `${p.key}=${displayVal}`
  }).join('&')

  if (!base) return `?${qs}`
  return base.includes('?') ? `${base}&${qs}` : `${base}?${qs}`
})

const backoffPreview = computed(() => {
  const strategy = form.value.backoff_strategy
  if (!strategy || strategy === 'default') return []

  const maxAttempts = form.value.retry_limit !== ''
    ? Math.max(2, parseInt(form.value.retry_limit, 10))
    : 5
  const baseDelay = form.value.backoff_base_delay !== ''
    ? parseInt(form.value.backoff_base_delay, 10)
    : (strategy === 'exponential' ? 30 : 60)
  const maxDelay = form.value.backoff_max_delay !== ''
    ? parseInt(form.value.backoff_max_delay, 10)
    : 3600

  const delays = []
  for (let n = 1; n < maxAttempts; n++) {
    let d
    if (strategy === 'linear')     d = n * baseDelay
    else if (strategy === 'fixed') d = baseDelay
    else                           d = Math.min(Math.pow(2, n) * baseDelay, maxDelay)
    delays.push(d)
  }

  const peak = Math.max(...delays, 1)
  return delays.map((d, i) => ({
    delay: d,
    label: `Wait ${formatDelay(d)}`,
    height: Math.max(4, Math.round((d / peak) * 56)),
    retryLabel: `${ordinal(i + 1)} Retry`,
  }))
})

// ── form logic ────────────────────────────────────────────────────────────────

const validate = () => {
  errors.value = {};

  if (!form.value.name.trim()) {
    errors.value.name = 'Name is required';
  }

  if (!form.value.endpoint_url.trim()) {
    errors.value.endpoint_url = 'Endpoint URL is required';
  } else {
    try {
      const urlForValidation = form.value.endpoint_url.replace(/\{\{[^}]+\}\}/g, '0');
      const url = new URL(urlForValidation);
      if (!['http:', 'https:'].includes(url.protocol)) {
        errors.value.endpoint_url = 'URL must be HTTP or HTTPS';
      }
    } catch {
      errors.value.endpoint_url = 'Invalid URL format';
    }
  }

  if (useChainTrigger.value) {
    if (chainConfig.value.chain_id == null && !chainConfig.value.new_chain_name?.trim()) {
      errors.value.triggers = 'Select an existing chain or enter a new chain name';
    } else if ((chainConfig.value.source_webhook_ids || []).length === 0) {
      errors.value.triggers = 'Select at least one upstream webhook to trigger this one';
    }
  }
  // Triggerless webhooks are allowed — they simply show as orphans in the
  // list view until WP-hook triggers or chain links are configured.

  return Object.keys(errors.value).length === 0;
};

const toggleChainMode = (val) => {
  // In create mode, chain config can't be saved (no webhook ID exists yet).
  // Intercept the ON path and prompt the user to save the webhook first.
  if (val && !props.webhook) {
    showChainSaveFirstDialog.value = true;
    return;
  }
  useChainTrigger.value = val;
  if (val) {
    form.value.triggers = [];
    if (!form.value.is_synchronous) {
      form.value.is_synchronous = true;
    }
  } else {
    chainConfig.value = { chain_id: null, new_chain_name: '', source_webhook_ids: [] };
  }
};

const handleSaveFirstAndContinue = () => {
  // Validate the standard fields. Chain mode is not yet on, so the
  // validator requires at least one WP-hook trigger — which is what we
  // want for the initial save.
  if (!validate()) {
    showChainSaveFirstDialog.value = false;
    return;
  }
  showChainSaveFirstDialog.value = false;

  const data = { ...form.value };
  data.retry_limit        = data.retry_limit !== '' ? parseInt(data.retry_limit, 10) : null;
  data.backoff_strategy   = data.backoff_strategy !== 'default' ? data.backoff_strategy : null;
  data.backoff_base_delay = data.backoff_base_delay !== '' ? parseInt(data.backoff_base_delay, 10) : null;
  data.backoff_max_delay  = data.backoff_max_delay !== '' ? parseInt(data.backoff_max_delay, 10) : null;
  data.custom_headers     = data.custom_headers ?? [];
  data.url_params         = data.url_params ?? [];
  data.__chain_config     = { enabled: false };
  data.__continue_to_chain = true;
  emit('submit', data);
};

const handleSyncToggle = (newVal) => {
  if (newVal) {
    if (isWarningDismissed()) {
      form.value.is_synchronous = true;
    } else {
      pendingSyncValue.value = true;
      showSyncWarning.value = true;
    }
  } else {
    form.value.is_synchronous = false;
  }
};

const confirmSyncToggle = () => {
  applyDismiss();
  form.value.is_synchronous = true;
  showSyncWarning.value = false;
};

const cancelSyncToggle = () => {
  showSyncWarning.value = false;
  pendingSyncValue.value = false;
  resetDontShowAgain();
};

const handleSubmit = () => {
  if (validate()) {
    const data = { ...form.value };
    data.retry_limit        = data.retry_limit !== '' ? parseInt(data.retry_limit, 10) : null;
    data.backoff_strategy   = data.backoff_strategy !== 'default' ? data.backoff_strategy : null;
    data.backoff_base_delay = data.backoff_base_delay !== '' ? parseInt(data.backoff_base_delay, 10) : null;
    data.backoff_max_delay  = data.backoff_max_delay !== '' ? parseInt(data.backoff_max_delay, 10) : null;
    data.custom_headers     = data.custom_headers ?? [];
    data.url_params         = data.url_params ?? [];

    // Chain config: tells the parent (WebhookEdit) how to sync chain links
    // after the webhook save. When useChainTrigger is OFF and webhook
    // currently has chain links, parent should call clearTargetLinks.
    data.__chain_config = useChainTrigger.value
      ? {
          enabled: true,
          chain_id: chainConfig.value.chain_id,
          new_chain_name: chainConfig.value.new_chain_name?.trim() || '',
          source_webhook_ids: chainConfig.value.source_webhook_ids || [],
        }
      : { enabled: false };

    emit('submit', data);
  }
};
</script>

<template>
  <form class="space-y-6" @submit.prevent="handleSubmit">
    <!-- Chain involvement banner (top of form) -->
    <div
      v-if="chainInvolvement.length"
      class="rounded-md border-l-4 border-accent bg-muted/40 px-3 py-2 space-y-1"
    >
      <div class="flex items-center gap-1.5 text-sm font-medium">
        <Network class="h-4 w-4 text-accent" />
        <span>Part of {{ chainInvolvement.length === 1 ? 'chain' : 'chains' }}:</span>
        <span v-for="(c, idx) in chainInvolvement" :key="c.id" class="inline-flex items-center gap-1">
          <Badge variant="secondary">{{ c.name }}</Badge>
          <span v-if="idx < chainInvolvement.length - 1" class="text-muted-foreground">·</span>
        </span>
      </div>
    </div>

    <!-- Name -->
    <div class="space-y-2">
      <Label for="name">Name</Label>
      <Input
        id="name"
        v-model="form.name"
        placeholder="My Webhook"
        :class="{ 'border-destructive': errors.name }"
      />
      <p v-if="errors.name" class="text-sm text-destructive">{{ errors.name }}</p>
    </div>

    <!-- Endpoint URL -->
    <div class="space-y-2">
      <Label for="endpoint_url">Endpoint URL</Label>
      <Input
        id="endpoint_url"
        v-model="form.endpoint_url"
        type="text"
        placeholder="https://example.com/webhook"
        :class="{ 'border-destructive': errors.endpoint_url }"
      />
      <p v-if="errors.endpoint_url" class="text-sm text-destructive">{{ errors.endpoint_url }}</p>
      <div class="flex items-start gap-2">
        <p class="text-sm text-muted-foreground">
          The URL where webhook payloads will be sent. Supports
          <code class="font-mono text-xs" v-pre>{{ field.path }}</code>
          templates — resolved against the final payload, after code glue applied.
        </p>
        <UpgradeBadge v-if="!proActive" class="shrink-0 mt-0.5" />
      </div>
      <template v-if="urlHasTemplates">
        <div class="rounded-md bg-muted px-3 py-2 font-mono text-xs break-all text-muted-foreground">
          <template v-if="urlTemplatePreview">{{ urlTemplatePreview }}</template>
          <span v-else class="italic">No captured payload — trigger the webhook once to preview the resolved URL.</span>
        </div>
      </template>
    </div>

    <!-- HTTP Method -->
    <div class="space-y-2 border-t pt-5">
      <Label>HTTP Method</Label>
      <Select v-model="form.http_method">
        <SelectTrigger class="w-40">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="POST">POST</SelectItem>
          <SelectItem value="GET">GET</SelectItem>
          <SelectItem value="PUT">PUT</SelectItem>
          <SelectItem value="PATCH">PATCH</SelectItem>
          <SelectItem value="DELETE">DELETE</SelectItem>
        </SelectContent>
      </Select>
    </div>

    <!-- Auth Header -->
    <div class="space-y-2 border-t pt-5">
      <Label for="auth_header">Authorization Header (optional)</Label>
      <Input
        id="auth_header"
        v-model="form.auth_header"
        placeholder="Bearer your_token_goes_here"
      />
      <p class="text-sm text-muted-foreground break-all md:break-normal">
        Value for the Authorization header (e.g., "Bearer your_token_goes_here"
        or "Basic your_encoded_base64(username:password)")
      </p>
    </div>

    <!-- Custom Request Headers -->
    <div class="space-y-2 border-t pt-5">
      <Label>Custom Request Headers</Label>
      <KeyValueEditor v-model="form.custom_headers" :examplePayload="examplePayload" :gluePayload="gluePayload" keyPlaceholder="Header name" />
      <p class="text-sm text-muted-foreground">
        Values support dot-notation paths into the outgoing payload (e.g. <code class="text-xs">event.id</code>) or static strings.
      </p>
    </div>

    <!-- URL Query Parameters -->
    <div class="space-y-2 border-t pt-5">
      <Label>
        {{ ['GET', 'DELETE'].includes(form.http_method) ? 'URL Query Parameters' : 'Additional URL Query Parameters' }}
      </Label>
      <KeyValueEditor v-model="form.url_params" :examplePayload="examplePayload" :gluePayload="gluePayload" keyPlaceholder="Param name" />
      <p class="text-sm text-muted-foreground">
        <template v-if="['GET', 'DELETE'].includes(form.http_method)">
          These are the primary way to send data for {{ form.http_method }} requests. If none are set, the mapped payload is sent as <code class="text-xs">?payload=&lt;json&gt;</code>.
        </template>
        <template v-else>
          Appended to the URL alongside the JSON body. Values support dot-notation paths or static strings.
        </template>
      </p>
      <div v-if="urlParamsPreview" class="rounded-md bg-muted px-3 py-2 font-mono text-xs break-all text-muted-foreground">
        {{ urlParamsPreview }}
      </div>
    </div>

    <!-- Triggers -->
    <div class="space-y-3 border-t pt-5">
      <div class="flex items-center justify-between gap-2">
        <Label>Triggers</Label>
        <div class="flex items-center gap-2">
          <Switch
            :model-value="useChainTrigger"
            @update:model-value="toggleChainMode"
          />
          <Label class="font-normal text-sm flex">
            <span class="inline-flex items-center gap-1">
              <Link2 class="h-3.5 w-3.5" />
              Use other Webhooks as triggers
            </span>
          </Label>
        </div>
      </div>

      <template v-if="!useChainTrigger">
        <TriggerSelect v-model="form.triggers" />
        <p class="text-sm text-muted-foreground">WordPress actions that will trigger this webhook</p>
      </template>
      <template v-else>
        <ChainPicker
          v-model="chainConfig"
          :current-webhook-id="props.webhook?.id ?? null"
        />
      </template>

      <p v-if="errors.triggers" class="text-sm text-destructive">{{ errors.triggers }}</p>
    </div>

    <!-- Max Attempts (Pro) -->
    <div class="space-y-2 border-t pt-5">
      <div class="flex items-center gap-2">
        <Label for="retry_limit">Max Attempts</Label>
        <Tooltip content="Total delivery attempts for this webhook, including the first try. Overrides the global setting. Once reached the job is permanently failed." side="right">
          <Info class="h-3.5 w-3.5 text-muted-foreground cursor-help shrink-0" />
        </Tooltip>
        <UpgradeBadge v-if="!proActive" />
      </div>
      <Input
        id="retry_limit"
        v-model="form.retry_limit"
        type="number"
        min="1"
        max="100"
        placeholder="Use global setting"
        class="w-48"
        :disabled="!proActive"
      />
      <p class="text-sm text-muted-foreground">
        Override the global retry limit for this webhook. Leave empty to use the global setting.
      </p>
    </div>

    <!-- Backoff Strategy (Pro) -->
    <div class="space-y-2 border-t pt-5">
      <div class="flex items-center gap-2">
        <Label>Backoff Strategy</Label>
        <Tooltip content="How to calculate the wait between retries. Overrides the global setting." side="right">
          <Info class="h-3.5 w-3.5 text-muted-foreground cursor-help shrink-0" />
        </Tooltip>
        <UpgradeBadge v-if="!proActive" />
      </div>

      <div class="space-y-2">
      <Select v-model="form.backoff_strategy" :disabled="!proActive">
        <SelectTrigger class="w-56">
          <SelectValue placeholder="Use global setting" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="default">Use global setting</SelectItem>
          <SelectItem value="exponential">Exponential</SelectItem>
          <SelectItem value="linear">Linear</SelectItem>
          <SelectItem value="fixed">Fixed</SelectItem>
        </SelectContent>
      </Select>

      <p class="text-sm text-muted-foreground">
        Override the retry delay strategy for this webhook. Leave empty to use the global setting.
      </p>

     </div>

      <div v-if="form.backoff_strategy !== 'default'" class="flex flex-wrap gap-3 mt-2">
        <div class="space-y-1">
          <div class="flex items-center gap-1">
            <Label class="text-xs text-muted-foreground">Base Delay (s)</Label>
            <Tooltip content="Base seconds used to calculate the retry delay. Acts as a multiplier for exponential, interval for linear, and constant wait for fixed." side="right">
              <Info class="h-3 w-3 text-muted-foreground cursor-help shrink-0" />
            </Tooltip>
          </div>
          <Input
            v-model="form.backoff_base_delay"
            type="number"
            min="1"
            max="86400"
            :placeholder="form.backoff_strategy === 'exponential' ? '30' : '60'"
            class="w-32"
            :disabled="!proActive"
          />
        </div>
        <div v-if="form.backoff_strategy === 'exponential'" class="space-y-1">
          <div class="flex items-center gap-1">
            <Label class="text-xs text-muted-foreground">Max Delay (s)</Label>
            <Tooltip content="Cap on the wait between retries. Prevents exponential backoff from growing indefinitely — any delay above this value is clamped to it." side="right">
              <Info class="h-3 w-3 text-muted-foreground cursor-help shrink-0" />
            </Tooltip>
          </div>
          <Input
            v-model="form.backoff_max_delay"
            type="number"
            min="1"
            max="86400"
            placeholder="3600"
            class="w-32"
            :disabled="!proActive"
          />
        </div>
      </div>

      <!-- Per-webhook backoff preview -->
      <div v-if="backoffPreview.length" class="pt-2 space-y-2">
        <p class="text-xs font-medium text-muted-foreground">Delay preview</p>
        <div class="flex items-end gap-1" style="height: 48px;">
          <div
            v-for="item in backoffPreview"
            :key="item.retryLabel"
            class="flex-1 bg-accent rounded-sm transition-all duration-300"
            :style="{ height: item.height + 'px' }"
          />
        </div>
        <div class="flex gap-1">
          <div v-for="item in backoffPreview" :key="item.retryLabel" class="flex-1 text-center">
            <div class="text-xs font-medium truncate">{{ item.label }}</div>
            <div class="text-xs text-muted-foreground truncate">{{ item.retryLabel }}</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Enabled -->
    <div class="space-y-2 border-t pt-5">
      <div class="flex items-center space-x-2">
        <Switch v-model="form.is_enabled" />
        <Label>Enabled Webhook</Label>
      </div>
      <div
        v-if="!form.is_enabled"
        class="rounded-md border border-blue-200 bg-blue-50 p-3 text-sm text-blue-700 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-300"
      >
        Disabled webhooks still capture payload examples for mapping and conditions configuration.
      </div>
    </div>

    <!-- Save-first-to-enable-chain dialog -->
    <Dialog
      :open="showChainSaveFirstDialog"
      title="Save webhook first"
      @close="showChainSaveFirstDialog = false"
    >
      <div class="space-y-2 text-sm text-muted-foreground">
        <p>
          Chain mode links this webhook to upstream webhooks, which requires a saved webhook record.
          <strong class="text-foreground">Save the webhook first</strong>
          (with or without WP-hook triggers — they'll be cleared when you pick upstream webhooks), then choose your chain sources on the next screen.
        </p>
      </div>
      <template #footer>
        <Button variant="outline" type="button" @click="showChainSaveFirstDialog = false">Cancel</Button>
        <Button type="button" @click="handleSaveFirstAndContinue">Save &amp; continue to chain setup</Button>
      </template>
    </Dialog>

    <!-- Synchronous Execution warning dialog -->
    <Dialog
      :open="showSyncWarning"
      title="Enable Synchronous Execution?"
      @close="cancelSyncToggle"
    >
      <div class="space-y-2 text-sm text-muted-foreground">
        <p>
          This webhook will fire inline during the WordPress request that triggers it, bypassing the queue.
          Slow or unreachable endpoints can <strong class="text-foreground">delay page loads, form submissions, and other frontend interactions.</strong>
        </p>
        <p>
          The <strong class="text-foreground">recommended approach is asynchronous delivery</strong> via the built-in system cron or an external cron job.
        </p>
      </div>
      <label class="flex items-center gap-2 cursor-pointer select-none">
        <Checkbox v-model="dontShowAgain" />
        <span class="text-sm text-muted-foreground">Don't show this again</span>
      </label>
      <template #footer>
        <Button variant="outline" type="button" @click="cancelSyncToggle">Cancel</Button>
        <Button variant="destructive" type="button" @click="confirmSyncToggle">Enable Anyway</Button>
      </template>
    </Dialog>

    <!-- Synchronous Execution -->
    <div class="space-y-2 border-t pt-5">
      <div class="flex items-center space-x-2">
        <Switch
          :model-value="form.is_synchronous"
          @update:model-value="handleSyncToggle"
        />
        <Label>Synchronous Execution</Label>
        <Tooltip content="When enabled, this webhook fires inline during the WordPress request that triggers it, bypassing the queue. May slow down your site if the endpoint is slow." side="right">
          <Info class="h-3.5 w-3.5 text-muted-foreground cursor-help shrink-0" />
        </Tooltip>
      </div>
      <div
        v-if="form.is_synchronous"
        class="rounded-md border border-yellow-200 bg-yellow-50 p-3 text-sm text-yellow-700 dark:border-yellow-800 dark:bg-yellow-950 dark:text-yellow-300"
      >
        This webhook executes synchronously. Slow or unreachable endpoints will delay page loads and frontend interactions.
      </div>
    </div>

    <!-- Actions -->
    <div class="flex gap-2 pt-4">
      <Button type="submit" :loading="loading">
        {{ webhook ? 'Save Changes' : 'Create Webhook' }}
      </Button>
      <Button type="button" variant="outline" @click="$emit('cancel')">
        Cancel
      </Button>
    </div>
  </form>
</template>
