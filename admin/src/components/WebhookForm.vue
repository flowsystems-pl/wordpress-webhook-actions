<script setup>
import { ref, computed, watch } from 'vue';
import { Button, Input, Label, Switch, UpgradeBadge, Select, SelectTrigger, SelectValue, SelectContent, SelectItem, Tooltip, RadioGroup, RadioGroupItem, Dialog, Checkbox } from '@/components/ui';
import { Info } from 'lucide-vue-next';
import TriggerSelect from '@/components/TriggerSelect.vue';
import KeyValueEditor from '@/components/KeyValueEditor.vue';
import { usePro } from '@/composables/usePro';
import { useSyncWarning } from '@/composables/useSyncWarning';

const { proActive } = usePro();
const { dontShowAgain, isWarningDismissed, applyDismiss, resetDontShowAgain } = useSyncWarning();

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

const errors = ref({});
const showSyncWarning = ref(false);
const pendingSyncValue = ref(false);

// ── watches ───────────────────────────────────────────────────────────────────

watch(() => props.webhook, (webhook) => {
  if (webhook) {
    form.value = {
      name:               webhook.name || '',
      endpoint_url:       webhook.endpoint_url || '',
      http_method:        webhook.http_method || 'POST',
      auth_header:        webhook.auth_header || '',
      custom_headers:     webhook.custom_headers || [],
      url_params:         webhook.url_params || [],
      is_enabled:         webhook.is_enabled ?? true,
      triggers:           webhook.triggers || [],
      retry_limit:        webhook.retry_limit != null ? String(webhook.retry_limit) : '',
      backoff_strategy:   webhook.backoff_strategy ?? 'default',
      backoff_base_delay: webhook.backoff_base_delay != null ? String(webhook.backoff_base_delay) : '',
      backoff_max_delay:  webhook.backoff_max_delay != null ? String(webhook.backoff_max_delay) : '',
      is_synchronous:     webhook.is_synchronous ?? false,
    };
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

const urlParamsPreview = computed(() => {
  const params = form.value.url_params.filter(p => p.key)
  if (!params.length) return null

  const base = form.value.endpoint_url || ''
  const qs = params.map(p => {
    let displayVal = p.value || ''
    if (isDotPath(p.value)) {
      const resolved = props.examplePayload
        ? resolveByPath(props.examplePayload, p.value)
        : undefined
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

  if (form.value.triggers.length === 0) {
    errors.value.triggers = 'At least one trigger is required';
  }

  return Object.keys(errors.value).length === 0;
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
    emit('submit', data);
  }
};
</script>

<template>
  <form class="space-y-6" @submit.prevent="handleSubmit">
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
      <p class="text-sm text-muted-foreground">
        The URL where webhook payloads will be sent. Supports
        <code class="font-mono text-xs" v-pre>{{ $payload.field }}</code>
        templates <span class="text-xs font-medium">(Pro)</span> — resolved against the final post-glue payload.
      </p>
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
      <KeyValueEditor v-model="form.custom_headers" :examplePayload="examplePayload" keyPlaceholder="Header name" />
      <p class="text-sm text-muted-foreground">
        Values support dot-notation paths into the outgoing payload (e.g. <code class="text-xs">event.id</code>) or static strings.
      </p>
    </div>

    <!-- URL Query Parameters -->
    <div class="space-y-2 border-t pt-5">
      <Label>
        {{ ['GET', 'DELETE'].includes(form.http_method) ? 'URL Query Parameters' : 'Additional URL Query Parameters' }}
      </Label>
      <KeyValueEditor v-model="form.url_params" :examplePayload="examplePayload" keyPlaceholder="Param name" />
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
    <div class="space-y-2 border-t pt-5">
      <Label>Triggers</Label>
      <TriggerSelect v-model="form.triggers" />
      <p v-if="errors.triggers" class="text-sm text-destructive">{{ errors.triggers }}</p>
      <p class="text-sm text-muted-foreground">WordPress actions that will trigger this webhook</p>
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
