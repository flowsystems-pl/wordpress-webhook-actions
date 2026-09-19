<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { AlertTriangle } from 'lucide-vue-next'
import { Button, Dialog, Input, Label, Switch, Checkbox, Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/components/ui'
import TemplateEditor from './TemplateEditor.vue'
import api from '@/lib/api'
import { useNotifications, EVENT_HINTS } from '@/composables/useNotifications'
import { __, sprintf } from '@/i18n'

// One rule: event → filters → channels → message → quiet controls.
// Used for site-wide rules (webhookId null) and per-webhook rules alike.
const props = defineProps({
  open: Boolean,
  rule: { type: Object, default: null },
  webhookId: { type: Number, default: null },
  aiAvailable: { type: Boolean, default: true },
})
const emit = defineEmits(['close', 'saved'])

const { events, channels, load, typeLabel } = useNotifications()

const form = reactive({
  name: '',
  event: 'permanently_failed',
  is_enabled: true,
  channel_ids: [],
  attempts: '',
  http_codes: [],
  http_custom: '',
  reason: 'any',
  triggers: '',
  only_after_retry: false,
  include_tests: false,
  template: null,
  throttle_value: '',
  throttle_unit: '60',
  digest: 'none',
})
const submitting = ref(false)
const error = ref(null)

const isEditing = computed(() => !!props.rule)
const isGlobal = computed(() => !props.webhookId)
const isFailure = computed(() => ['failed_attempt', 'retry_scheduled', 'permanently_failed'].includes(form.event))
const showAttempts = computed(() => ['failed_attempt', 'retry_scheduled'].includes(form.event))
const showReason = computed(() => form.event === 'permanently_failed')
const showAfterRetry = computed(() => form.event === 'success')
const eventHint = computed(() => EVENT_HINTS[form.event]?.() || '')
const successWarning = computed(() => isGlobal.value && form.event === 'success')

const CODE_CHIPS = ['4xx', '5xx', '429', 'transport']

const selectedTypes = computed(() => form.channel_ids.map((id) => channels.value.find((c) => c.id === id)?.type).filter(Boolean))

const toggleChannel = (id) => {
  const i = form.channel_ids.indexOf(id)
  if (i === -1) form.channel_ids.push(id)
  else form.channel_ids.splice(i, 1)
}
const toggleCode = (code) => {
  const i = form.http_codes.indexOf(code)
  if (i === -1) form.http_codes.push(code)
  else form.http_codes.splice(i, 1)
}

const secondsToForm = (seconds) => {
  if (!seconds) return { value: '', unit: '60' }
  if (seconds % 86400 === 0) return { value: String(seconds / 86400), unit: '86400' }
  if (seconds % 3600 === 0) return { value: String(seconds / 3600), unit: '3600' }
  if (seconds % 60 === 0) return { value: String(seconds / 60), unit: '60' }
  return { value: String(seconds), unit: '1' }
}

const reset = async () => {
  await load()
  error.value = null
  submitting.value = false
  const r = props.rule
  const f = r?.filters || {}
  form.name = r?.name || ''
  form.event = r?.event || 'permanently_failed'
  form.is_enabled = r ? r.is_enabled : true
  form.channel_ids = r ? [...r.channel_ids] : (channels.value.length === 1 ? [channels.value[0].id] : [])
  form.attempts = (f.attempts || []).join(', ')
  const chips = (f.http_codes || []).filter((c) => CODE_CHIPS.includes(c))
  form.http_codes = chips
  form.http_custom = (f.http_codes || []).filter((c) => !CODE_CHIPS.includes(c)).join(', ')
  form.reason = f.reason || 'any'
  form.triggers = (f.triggers || []).join(', ')
  form.only_after_retry = !!f.only_after_retry
  form.include_tests = !!f.include_tests
  form.template = r?.template || null
  const t = secondsToForm(r?.throttle_seconds || (!r && isGlobal.value && form.event === 'success' ? 3600 : 0))
  form.throttle_value = t.value
  form.throttle_unit = t.unit
  form.digest = r?.digest || 'none'
}
watch(() => props.open, (open) => { if (open) reset() })

// Pre-fill the one-hour quiet time when a global rule switches to "success".
watch(() => form.event, (event) => {
  if (!props.rule && isGlobal.value && event === 'success' && form.throttle_value === '') {
    form.throttle_value = '1'
    form.throttle_unit = '3600'
  }
})

const payload = () => {
  const filters = {}
  if (showAttempts.value && form.attempts.trim()) filters.attempts = form.attempts.split(/[,\s]+/).filter(Boolean).map(Number).filter((n) => n >= 1)
  const codes = [...form.http_codes, ...form.http_custom.split(/[,\s]+/).filter(Boolean)]
  if (isFailure.value && codes.length) filters.http_codes = codes
  if (showReason.value && form.reason !== 'any') filters.reason = form.reason
  if (form.triggers.trim()) filters.triggers = form.triggers.split(/[,\s]+/).filter(Boolean)
  if (showAfterRetry.value && form.only_after_retry) filters.only_after_retry = true
  if (form.include_tests) filters.include_tests = true

  const throttle = form.throttle_value !== '' ? Math.max(0, parseInt(form.throttle_value, 10) || 0) * parseInt(form.throttle_unit, 10) : null

  return {
    webhook_id: props.webhookId || 0,
    name: form.name.trim(),
    event: form.event,
    is_enabled: form.is_enabled,
    channel_ids: form.channel_ids,
    filters,
    template: form.template,
    throttle_seconds: throttle || null,
    digest: form.digest === 'none' ? null : form.digest,
  }
}

const submit = async () => {
  error.value = null
  if (form.channel_ids.length === 0) {
    error.value = __('Pick at least one channel.')
    return
  }
  submitting.value = true
  try {
    const data = payload()
    const saved = isEditing.value
      ? await api.notifications.rules.update(props.rule.id, data)
      : await api.notifications.rules.create(data)
    emit('saved', saved)
    emit('close')
  } catch (e) {
    error.value = e.message
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <Dialog
    :open="open"
    :title="isEditing ? __('Edit rule') : (isGlobal ? __('New site-wide rule') : __('New rule for this webhook'))"
    :description="isGlobal ? __('Applies to every webhook that inherits site-wide rules.') : __('Applies to this webhook only.')"
    content-class="max-w-3xl"
    @close="emit('close')"
  >
    <div class="space-y-5">
      <div v-if="error" class="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">{{ error }}</div>

      <!-- Event -->
      <div class="grid gap-3 sm:grid-cols-[1fr,1.2fr]">
        <div class="space-y-1.5">
          <Label>{{ __('When') }}</Label>
          <Select v-model="form.event">
            <SelectTrigger><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem v-for="e in events" :key="e.key" :value="e.key">{{ e.label }}</SelectItem>
            </SelectContent>
          </Select>
          <p class="text-xs text-muted-foreground">{{ eventHint }}</p>
        </div>
        <div class="space-y-1.5">
          <Label for="rule-name">{{ __('Name (optional)') }}</Label>
          <Input id="rule-name" v-model="form.name" :placeholder="__('Page on-call when HubSpot dies')" />
        </div>
      </div>

      <div v-if="successWarning" class="flex items-start gap-2 rounded-md border border-amber-500/50 bg-amber-500/10 p-3 text-xs text-amber-800 dark:text-amber-300">
        <AlertTriangle class="h-4 w-4 mt-0.5 shrink-0" />
        <span>{{ __('A site-wide rule on every success fires for every delivery of every webhook. A quiet time of one hour per webhook is pre-filled below; consider a daily digest instead.') }}</span>
      </div>

      <!-- Filters -->
      <div class="space-y-3 rounded-md border border-border p-3">
        <Label class="text-xs uppercase tracking-wide text-muted-foreground">{{ __('Only when') }}</Label>

        <div v-if="showAttempts" class="space-y-1">
          <Label class="text-xs" for="rule-attempts">{{ __('Attempt number is') }}</Label>
          <Input id="rule-attempts" v-model="form.attempts" class="w-64 font-mono text-sm" :placeholder="__('any — or e.g. 1, 3')" />
          <p class="text-xs text-muted-foreground">{{ __('Attempt 1 is the first try. Leave empty to fire on every failed attempt.') }}</p>
        </div>

        <div v-if="isFailure" class="space-y-1">
          <Label class="text-xs">{{ __('HTTP response is') }}</Label>
          <div class="flex flex-wrap items-center gap-1.5">
            <button
              v-for="code in CODE_CHIPS"
              :key="code"
              type="button"
              class="rounded-full border px-2.5 py-0.5 text-xs font-mono transition-colors"
              :class="form.http_codes.includes(code) ? 'bg-primary text-primary-foreground border-primary' : 'bg-background text-muted-foreground border-border hover:bg-muted'"
              @click="toggleCode(code)"
            >{{ code === 'transport' ? __('no response') : code }}</button>
            <Input v-model="form.http_custom" class="w-40 h-7 font-mono text-xs" :placeholder="__('503, 500-504')" />
          </div>
          <p class="text-xs text-muted-foreground">{{ __('Nothing selected = any failure. "No response" covers DNS, TLS and timeouts.') }}</p>
        </div>

        <div v-if="showReason" class="space-y-1">
          <Label class="text-xs">{{ __('Reason') }}</Label>
          <Select v-model="form.reason">
            <SelectTrigger class="w-72"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="any">{{ __('Any') }}</SelectItem>
              <SelectItem value="exhausted">{{ __('Out of attempts (retries used up)') }}</SelectItem>
              <SelectItem value="non_retryable">{{ __('Not retryable (4xx, 3xx, config error)') }}</SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div v-if="isGlobal" class="space-y-1">
          <Label class="text-xs" for="rule-triggers">{{ __('Trigger matches') }}</Label>
          <Input id="rule-triggers" v-model="form.triggers" class="font-mono text-sm" :placeholder="__('any — or e.g. woocommerce_*, wpcf7_mail_sent')" />
          <p class="text-xs text-muted-foreground">{{ __('Comma-separated hook names; * matches anything.') }}</p>
        </div>

        <div class="flex flex-wrap gap-4">
          <label v-if="showAfterRetry" class="flex items-center gap-2 text-xs cursor-pointer">
            <Checkbox v-model="form.only_after_retry" />{{ __('Only when it took more than one attempt') }}
          </label>
          <label class="flex items-center gap-2 text-xs cursor-pointer">
            <Checkbox v-model="form.include_tests" />{{ __('Also for test dispatches') }}
          </label>
        </div>
      </div>

      <!-- Channels -->
      <div class="space-y-2">
        <Label>{{ __('Send to') }}</Label>
        <div v-if="channels.length === 0" class="text-sm text-muted-foreground">
          {{ __('No channels yet. Add one under Notifications → Channels first.') }}
        </div>
        <div v-else class="flex flex-wrap gap-2">
          <button
            v-for="c in channels"
            :key="c.id"
            type="button"
            class="rounded-md border px-3 py-1.5 text-sm text-left transition-colors"
            :class="form.channel_ids.includes(c.id) ? 'border-primary bg-primary/10 text-foreground' : 'border-border bg-background text-muted-foreground hover:bg-muted'"
            :disabled="!c.is_enabled"
            @click="toggleChannel(c.id)"
          >
            <span class="font-medium">{{ c.name }}</span>
            <span class="ml-1.5 text-xs opacity-70">{{ typeLabel(c.type) }}</span>
          </button>
        </div>
      </div>

      <!-- Template -->
      <TemplateEditor
        v-model="form.template"
        :event="form.event"
        :webhook-id="webhookId"
        :channel-types="selectedTypes"
        :ai-available="aiAvailable"
      />

      <!-- Quiet controls -->
      <div class="grid gap-3 sm:grid-cols-2 rounded-md border border-border p-3">
        <div class="space-y-1">
          <Label class="text-xs">{{ __('Quiet time per webhook') }}</Label>
          <div class="flex items-center gap-2">
            <Input v-model="form.throttle_value" type="number" min="0" class="w-24" :placeholder="__('none')" />
            <Select v-model="form.throttle_unit">
              <SelectTrigger class="w-32"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="1">{{ __('seconds') }}</SelectItem>
                <SelectItem value="60">{{ __('minutes') }}</SelectItem>
                <SelectItem value="3600">{{ __('hours') }}</SelectItem>
                <SelectItem value="86400">{{ __('days') }}</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <p class="text-xs text-muted-foreground">{{ __('At most one message per webhook in this window; the rest are recorded as throttled.') }}</p>
        </div>
        <div class="space-y-1">
          <Label class="text-xs">{{ __('Digest') }}</Label>
          <Select v-model="form.digest">
            <SelectTrigger><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="none">{{ __('Send each message right away') }}</SelectItem>
              <SelectItem value="hourly">{{ __('Bundle into an hourly digest') }}</SelectItem>
              <SelectItem value="daily">{{ __('Bundle into a daily digest') }}</SelectItem>
            </SelectContent>
          </Select>
          <p class="text-xs text-muted-foreground">{{ __('One summary per channel listing everything that happened in the period.') }}</p>
        </div>
      </div>

      <div class="flex items-center gap-2">
        <Switch v-model="form.is_enabled" />
        <Label>{{ __('Rule enabled') }}</Label>
      </div>
    </div>

    <template #footer>
      <Button variant="outline" @click="emit('close')">{{ __('Cancel') }}</Button>
      <Button :disabled="submitting" @click="submit">{{ submitting ? __('Saving…') : (isEditing ? __('Save rule') : __('Create rule')) }}</Button>
    </template>
  </Dialog>
</template>
