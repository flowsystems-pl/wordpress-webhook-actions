<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import { Sparkles, RefreshCw, ChevronDown, Search, AlertTriangle, Info } from 'lucide-vue-next'
import { Button, Input, Label, Switch, Popover, Tooltip } from '@/components/ui'
import api from '@/lib/api'
import { SEVERITY_CLASS, useNotifications } from '@/composables/useNotifications'
import { __, sprintf } from '@/i18n'

// Subject / title / body / short with a live preview, a field picker that
// inserts {{ path }} at the caret, and "Draft with AI".
//
// The fields are pre-filled with the event's default template, so editing
// means changing a message, not writing one from memory. Only fields that
// differ from that default are emitted; a template where nothing differs is
// emitted as null (= use the default), exactly as an untouched rule was
// before. A partial saved template shows the default in its other fields.
const props = defineProps({
  modelValue: { type: Object, default: null },
  event: { type: String, required: true },
  webhookId: { type: Number, default: null },
  channelTypes: { type: Array, default: () => [] },
  aiAvailable: { type: Boolean, default: true },
})
const emit = defineEmits(['update:modelValue'])

const { defaults, loadCatalog } = useNotifications()
loadCatalog().catch(() => {})
const EMPTY = { subject: '', title: '', body: '', short: '' }
const defaultsFor = (event) => ({ ...EMPTY, ...(defaults.value?.[event] || {}) })

const local = ref({ subject: '', title: '', body: '', short: '', include_fields: true })
const preview = ref(null)
const lint = ref(null)
const paths = ref([])
const source = ref('sample')
const previewing = ref(false)
const previewError = ref(null)
const activeField = ref('body')
const editors = {}

const fromModel = (value) => {
  const v = value || {}
  const d = defaultsFor(props.event)
  local.value = {
    subject: v.subject || d.subject,
    title: v.title || d.title,
    body: v.body || d.body,
    short: v.short || d.short,
    include_fields: v.include_fields !== false,
  }
}
fromModel(props.modelValue)
watch(() => props.modelValue, (v) => {
  const current = toModel()
  if (JSON.stringify(current) !== JSON.stringify(v)) fromModel(v)
})

const toModel = () => {
  const out = {}
  const d = defaultsFor(props.event)
  for (const k of ['subject', 'title', 'body', 'short']) {
    const v = local.value[k]
    if (v.trim() !== '' && v !== d[k]) out[k] = v
  }
  if (!local.value.include_fields) out.include_fields = false
  return Object.keys(out).length ? out : null
}

const isCustom = computed(() => toModel() !== null)

// The catalog loads asynchronously and the event can change: a field that
// still holds the previous default (or nothing) follows the new default; a
// field the user changed keeps its text.
let shownDefaults = defaultsFor(props.event)
watch([() => defaults.value, () => props.event], () => {
  const next = defaultsFor(props.event)
  for (const k of ['subject', 'title', 'body', 'short']) {
    if (local.value[k] === '' || local.value[k] === shownDefaults[k]) local.value[k] = next[k]
  }
  shownDefaults = next
}, { deep: true })

let previewTimer = null
let previewSeq = 0
const schedulePreview = () => {
  clearTimeout(previewTimer)
  previewTimer = setTimeout(runPreview, 400)
}

// Previews overlap while someone types; only the newest response may land,
// or a slow older one would put stale lint warnings back on a fixed template.
const runPreview = async () => {
  const seq = ++previewSeq
  previewing.value = true
  previewError.value = null
  try {
    const data = await api.notifications.preview({
      event: props.event,
      template: toModel() || {},
      webhook_id: props.webhookId || undefined,
      channel_types: props.channelTypes,
    })
    if (seq !== previewSeq) return
    preview.value = data.message
    lint.value = data.lint
    paths.value = data.paths || []
    source.value = data.source
  } catch (e) {
    if (seq !== previewSeq) return
    previewError.value = e.message
  } finally {
    if (seq === previewSeq) previewing.value = false
  }
}

watch(local, () => {
  emit('update:modelValue', toModel())
  schedulePreview()
}, { deep: true })
watch(() => [props.event, props.webhookId, props.channelTypes.join(',')], schedulePreview)
runPreview()
onBeforeUnmount(() => clearTimeout(previewTimer))

// --- Field picker -----------------------------------------------------------
const pickerOpen = ref(false)
const pickerSearch = ref('')
const filteredPaths = computed(() => {
  const q = pickerSearch.value.toLowerCase()
  const list = q ? paths.value.filter((p) => p.path.toLowerCase().includes(q)) : paths.value
  return list.slice(0, 150)
})
const insertPath = (path) => {
  const key = activeField.value
  const el = editors[key]
  const token = `{{ ${path} }}`
  const value = local.value[key] || ''
  if (el && typeof el.selectionStart === 'number') {
    const start = el.selectionStart
    const end = el.selectionEnd
    local.value[key] = value.slice(0, start) + token + value.slice(end)
    requestAnimationFrame(() => {
      el.focus()
      el.selectionStart = el.selectionEnd = start + token.length
    })
  } else {
    local.value[key] = value + (value ? ' ' : '') + token
  }
  pickerOpen.value = false
}
const registerEditor = (key) => (el) => { editors[key] = el?.$el?.querySelector?.('input, textarea') || el }

// --- Draft with AI ---------------------------------------------------------
const drafting = ref(false)
const draftError = ref(null)
const instructions = ref('')
const showInstructions = ref(false)
const draft = async () => {
  drafting.value = true
  draftError.value = null
  try {
    const data = await api.notifications.aiDraft({
      event: props.event,
      channel_types: props.channelTypes,
      webhook_id: props.webhookId || undefined,
      instructions: instructions.value || undefined,
      current_template: toModel() || undefined,
    })
    fromModel(data.template)
    preview.value = data.message
    lint.value = data.lint
  } catch (e) {
    draftError.value = e.message
  } finally {
    drafting.value = false
  }
}

const resetToDefault = () => {
  local.value = { ...defaultsFor(props.event), include_fields: true }
}

const severityClass = computed(() => SEVERITY_CLASS[preview.value?.severity] || SEVERITY_CLASS.neutral)
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div class="flex items-center gap-2">
        <Label class="text-sm font-medium">{{ __('Message') }}</Label>
        <span v-if="!isCustom" class="text-xs text-muted-foreground">{{ __('using the default for this event') }}</span>
        <Tooltip :content="__('Placeholders: {{ webhook.name }}, {{ delivery.error_message | truncate:120 }}, {{ payload.order.id }}, {{ args.1.email }}. Modifiers: upper, lower, truncate:N, json, default:\'…\', date:\'Y-m-d H:i\', number:N, money, count, join:\', \'.')" max-width="360px">
          <Info class="h-3.5 w-3.5 text-muted-foreground cursor-help" />
        </Tooltip>
      </div>
      <div class="flex items-center gap-1.5">
        <Popover :open="pickerOpen" content-class="p-0 w-80" @update:open="pickerOpen = $event">
          <template #trigger>
            <Button type="button" variant="outline" size="sm">
              {{ __('Insert field') }} <ChevronDown class="ml-1 h-3.5 w-3.5" />
            </Button>
          </template>
          <div class="p-2 border-b">
            <div class="relative">
              <Search class="absolute left-2 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground" />
              <Input v-model="pickerSearch" class="pl-7 h-8 text-sm" :placeholder="__('Search fields…')" />
            </div>
          </div>
          <div class="max-h-72 overflow-y-auto">
            <button
              v-for="p in filteredPaths"
              :key="p.path"
              type="button"
              class="w-full text-left px-3 py-1.5 hover:bg-muted text-xs font-mono flex items-center justify-between gap-2"
              @click="insertPath(p.path)"
            >
              <span class="truncate">{{ p.path }}</span>
              <span class="text-muted-foreground truncate max-w-[40%]">{{ p.sample }}</span>
            </button>
            <div v-if="filteredPaths.length === 0" class="px-3 py-4 text-xs text-muted-foreground">
              {{ source === 'sample' ? __('No captured payload yet — trigger the webhook once to see its fields here.') : __('No matching field.') }}
            </div>
          </div>
        </Popover>
        <Tooltip :content="aiAvailable ? __('Ask the site\'s AI provider to write this message from the captured payload') : __('Connect an AI provider under Build with AI to draft messages')">
          <Button type="button" variant="outline" size="sm" :disabled="drafting || !aiAvailable" @click="showInstructions ? draft() : (showInstructions = true)">
            <Sparkles class="mr-1 h-3.5 w-3.5" />{{ drafting ? __('Drafting…') : __('Draft with AI') }}
          </Button>
        </Tooltip>
        <Button v-if="isCustom" type="button" variant="ghost" size="sm" :title="__('Back to the default message')" @click="resetToDefault">
          <RefreshCw class="h-3.5 w-3.5" />
        </Button>
      </div>
    </div>

    <div v-if="showInstructions" class="flex flex-wrap gap-2 items-center rounded-md border border-border bg-muted/30 p-2">
      <Input v-model="instructions" class="flex-1 min-w-[200px] h-8 text-sm" :placeholder="__('Optional: mention the order number and customer email, keep it short…')" @keydown.enter.prevent="draft" />
      <Button type="button" size="sm" :disabled="drafting" @click="draft"><Sparkles class="mr-1 h-3.5 w-3.5" />{{ drafting ? __('Drafting…') : __('Draft') }}</Button>
    </div>
    <div v-if="draftError" class="text-xs text-destructive">{{ draftError }}</div>

    <div class="grid gap-3 lg:grid-cols-2">
      <div class="space-y-3">
        <div class="space-y-1">
          <Label class="text-xs text-muted-foreground" for="tpl-subject">{{ __('Subject (email)') }}</Label>
          <Input id="tpl-subject" :ref="registerEditor('subject')" v-model="local.subject" spellcheck="false" class="font-mono text-sm" :placeholder="preview?.subject || ''" @focus="activeField = 'subject'" />
        </div>
        <div class="space-y-1">
          <Label class="text-xs text-muted-foreground" for="tpl-title">{{ __('Title (chat cards, push)') }}</Label>
          <Input id="tpl-title" :ref="registerEditor('title')" v-model="local.title" spellcheck="false" class="font-mono text-sm" :placeholder="preview?.title || ''" @focus="activeField = 'title'" />
        </div>
        <div class="space-y-1">
          <Label class="text-xs text-muted-foreground" for="tpl-body">{{ __('Body') }}</Label>
          <textarea
            id="tpl-body"
            :ref="registerEditor('body')"
            v-model="local.body"
            rows="5"
            spellcheck="false"
            class="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-mono ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            :placeholder="preview?.body || ''"
            @focus="activeField = 'body'"
          />
        </div>
        <div class="space-y-1">
          <Label class="text-xs text-muted-foreground" for="tpl-short">{{ __('One-liner (SMS, push)') }}</Label>
          <Input id="tpl-short" :ref="registerEditor('short')" v-model="local.short" spellcheck="false" class="font-mono text-sm" :placeholder="preview?.short || ''" @focus="activeField = 'short'" />
        </div>
        <div class="flex items-center gap-2">
          <Switch v-model="local.include_fields" />
          <Label class="text-xs">{{ __('Append the standard facts (webhook, trigger, attempt, HTTP, error, log link)') }}</Label>
        </div>
      </div>

      <div class="space-y-2">
        <div class="flex items-center justify-between">
          <Label class="text-xs text-muted-foreground">{{ __('Preview') }}</Label>
          <span class="text-[11px] text-muted-foreground">
            {{ source === 'log' ? __('from a real delivery') : source === 'example' ? __('from the captured payload') : __('sample data') }}
            <span v-if="previewing"> · …</span>
          </span>
        </div>
        <div v-if="previewError" class="text-xs text-destructive">{{ previewError }}</div>
        <div v-else-if="preview" class="rounded-lg border bg-card overflow-hidden text-sm">
          <div class="px-3 py-2 border-b text-[11px] uppercase tracking-wide font-semibold" :class="severityClass">{{ preview.event_label }}</div>
          <div class="p-3 space-y-2">
            <div class="text-xs text-muted-foreground truncate" :title="preview.subject">{{ preview.subject }}</div>
            <div class="font-semibold text-foreground">{{ preview.title }}</div>
            <div class="whitespace-pre-wrap text-foreground/90 text-xs leading-relaxed">{{ preview.body }}</div>
            <dl v-if="preview.fields?.length" class="grid grid-cols-[auto,1fr] gap-x-3 gap-y-0.5 text-xs border-t pt-2">
              <template v-for="f in preview.fields" :key="f.label">
                <dt class="text-muted-foreground">{{ f.label }}</dt>
                <dd class="text-foreground break-words">{{ f.value }}</dd>
              </template>
            </dl>
            <div class="text-[11px] text-muted-foreground border-t pt-2 truncate">{{ __('SMS') }}: {{ preview.short }}</div>
          </div>
        </div>

        <div v-if="lint && (lint.unknown_paths.length || lint.unknown_roots.length || lint.unknown_modifiers.length || lint.warnings.length)" class="space-y-1 text-xs">
          <div v-for="p in lint.unknown_paths" :key="'p' + p" class="flex items-start gap-1.5 text-amber-700 dark:text-amber-400">
            <AlertTriangle class="h-3.5 w-3.5 mt-0.5 shrink-0" /><span>{{ sprintf(__('%s is not in the captured payload and will render empty.'), p) }}</span>
          </div>
          <div v-for="r in lint.unknown_roots" :key="'r' + r" class="flex items-start gap-1.5 text-destructive">
            <AlertTriangle class="h-3.5 w-3.5 mt-0.5 shrink-0" /><span>{{ sprintf(__('Unknown root "%s" — use webhook, event, delivery, payload, original, args or site.'), r) }}</span>
          </div>
          <div v-for="m in lint.unknown_modifiers" :key="'m' + m" class="flex items-start gap-1.5 text-destructive">
            <AlertTriangle class="h-3.5 w-3.5 mt-0.5 shrink-0" /><span>{{ sprintf(__('Unknown modifier "%s".'), m) }}</span>
          </div>
          <div v-for="w in lint.warnings" :key="'w' + w" class="flex items-start gap-1.5 text-muted-foreground">
            <Info class="h-3.5 w-3.5 mt-0.5 shrink-0" /><span>{{ w }}</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
