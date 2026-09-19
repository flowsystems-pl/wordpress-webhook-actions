<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { Plus, Pencil, Trash2, Send, BellOff, Bell, CheckCircle2, AlertTriangle, GripVertical } from 'lucide-vue-next'
import { Button, Badge, Dialog, Tooltip, Switch } from '@/components/ui'
import RuleEditor from './RuleEditor.vue'
import api from '@/lib/api'
import { useNotifications, SEVERITY_CLASS, EVENT_SEVERITY, describeFilters, formatThrottle } from '@/composables/useNotifications'
import { __, sprintf } from '@/i18n'

// A list of rules for one scope (site-wide, or one webhook), with create /
// edit / delete / test / enable. Optional mute mode renders the site-wide
// rules inside a webhook form with a per-webhook mute switch instead.
const props = defineProps({
  webhookId: { type: Number, default: null },
  aiAvailable: { type: Boolean, default: true },
  muteMode: { type: Boolean, default: false },
  mutedIds: { type: Array, default: () => [] },
  compact: { type: Boolean, default: false },
})
const emit = defineEmits(['update:mutedIds', 'count'])

const { channelById, eventLabel, load } = useNotifications()

const rules = ref([])
const loading = ref(true)
const error = ref(null)
const showEditor = ref(false)
const editing = ref(null)
const testing = ref({})
const testResult = ref({})

const fetchRules = async () => {
  loading.value = true
  try {
    await load()
    rules.value = await api.notifications.rules.list(props.webhookId ? { webhook_id: props.webhookId } : {})
    emit('count', rules.value.length)
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}
onMounted(fetchRules)
watch(() => props.webhookId, fetchRules)

const openCreate = () => { editing.value = null; showEditor.value = true }
const openEdit = (rule) => { editing.value = rule; showEditor.value = true }

const toggleEnabled = async (rule) => {
  try {
    const saved = await api.notifications.rules.update(rule.id, { is_enabled: !rule.is_enabled })
    Object.assign(rule, saved)
  } catch (e) {
    error.value = e.message
  }
}

const isMuted = (rule) => props.mutedIds.includes(rule.id)
const toggleMute = (rule) => {
  const next = isMuted(rule) ? props.mutedIds.filter((id) => id !== rule.id) : [...props.mutedIds, rule.id]
  emit('update:mutedIds', next)
}

const sendTest = async (rule) => {
  testing.value = { ...testing.value, [rule.id]: true }
  testResult.value = { ...testResult.value, [rule.id]: null }
  try {
    const data = await api.notifications.rules.test(rule.id, props.webhookId ? { webhook_id: props.webhookId } : {})
    const failed = data.results.filter((r) => !r.sent)
    testResult.value = { ...testResult.value, [rule.id]: failed.length ? { ok: false, error: failed.map((r) => `${r.channel}: ${r.error}`).join('; ') } : { ok: true } }
  } catch (e) {
    testResult.value = { ...testResult.value, [rule.id]: { ok: false, error: e.message } }
  } finally {
    testing.value = { ...testing.value, [rule.id]: false }
  }
}

const showDelete = ref(false)
const toDelete = ref(null)
const deleting = ref(false)
const openDelete = (rule) => { toDelete.value = rule; showDelete.value = true }
const handleDelete = async () => {
  deleting.value = true
  try {
    await api.notifications.rules.delete(toDelete.value.id)
    showDelete.value = false
    await fetchRules()
  } catch (e) {
    error.value = e.message
  } finally {
    deleting.value = false
  }
}

// Reorder (site-wide list only): simple move up/down via drag handle click.
const dragIndex = ref(null)
const onDragStart = (i) => { dragIndex.value = i }
const onDrop = async (i) => {
  if (dragIndex.value === null || dragIndex.value === i) return
  const list = [...rules.value]
  const [moved] = list.splice(dragIndex.value, 1)
  list.splice(i, 0, moved)
  rules.value = list
  dragIndex.value = null
  try { await api.notifications.rules.reorder(list.map((r) => r.id)) } catch (e) { error.value = e.message }
}

const channelNames = (rule) => rule.channel_ids.map((id) => channelById.value[id]?.name || `#${id}`)
const severityClass = (rule) => SEVERITY_CLASS[EVENT_SEVERITY[rule.event]] || SEVERITY_CLASS.neutral

const title = computed(() => props.muteMode ? __('Site-wide rules') : (props.webhookId ? __('Rules for this webhook') : __('Site-wide rules')))
</script>

<template>
  <div class="space-y-3">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div>
        <h3 v-if="!compact" class="text-sm font-medium text-foreground">{{ title }}</h3>
        <p v-if="!muteMode && !compact" class="text-xs text-muted-foreground">
          {{ webhookId ? __('Fire only for this webhook, on top of whatever site-wide rules it inherits.') : __('Apply to every webhook set to inherit. A webhook can mute any of them individually.') }}
        </p>
      </div>
      <Button v-if="!muteMode" size="sm" @click="openCreate"><Plus class="mr-1.5 h-4 w-4" />{{ __('New rule') }}</Button>
    </div>

    <div v-if="error" class="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">{{ error }}</div>
    <div v-if="loading" class="text-sm text-muted-foreground">{{ __('Loading…') }}</div>

    <div v-else-if="rules.length === 0" class="rounded-md border border-dashed border-border p-6 text-center text-sm text-muted-foreground">
      {{ muteMode ? __('No site-wide rules are defined.') : __('No rules yet. A rule says when to send, where, and what.') }}
    </div>

    <ul v-else class="space-y-2">
      <li
        v-for="(rule, i) in rules"
        :key="rule.id"
        class="rounded-md border bg-card p-3"
        :class="[(!rule.is_enabled || (muteMode && isMuted(rule))) ? 'opacity-60' : '', 'border-border']"
        :draggable="!muteMode && !webhookId"
        @dragstart="onDragStart(i)"
        @dragover.prevent
        @drop="onDrop(i)"
      >
        <div class="flex items-start gap-3">
          <GripVertical v-if="!muteMode && !webhookId" class="h-4 w-4 mt-1 text-muted-foreground/50 cursor-grab shrink-0" />
          <div class="min-w-0 flex-1 space-y-1">
            <div class="flex flex-wrap items-center gap-1.5">
              <span class="rounded-md border px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide" :class="severityClass(rule)">{{ eventLabel(rule.event) }}</span>
              <span v-if="rule.name" class="text-sm font-medium text-foreground">{{ rule.name }}</span>
              <Badge v-if="!rule.is_enabled" variant="outline">{{ __('off') }}</Badge>
              <Badge v-if="rule.digest" variant="secondary">{{ rule.digest === 'hourly' ? __('hourly digest') : __('daily digest') }}</Badge>
              <Badge v-if="rule.template" variant="outline">{{ __('custom message') }}</Badge>
            </div>
            <div class="text-xs text-muted-foreground flex flex-wrap gap-x-3 gap-y-0.5">
              <span>→ {{ channelNames(rule).join(', ') || __('no channel') }}</span>
              <span v-for="p in describeFilters(rule.filters)" :key="p" class="font-mono">{{ p }}</span>
              <span v-if="rule.throttle_seconds">{{ sprintf(__('quiet %s'), formatThrottle(rule.throttle_seconds)) }}</span>
            </div>
            <div v-if="testResult[rule.id]?.ok" class="flex items-center gap-1.5 text-xs text-emerald-600 dark:text-emerald-400"><CheckCircle2 class="h-3.5 w-3.5" />{{ __('Test sent to every channel.') }}</div>
            <div v-else-if="testResult[rule.id] && !testResult[rule.id].ok" class="flex items-start gap-1.5 text-xs text-destructive"><AlertTriangle class="h-3.5 w-3.5 mt-0.5 shrink-0" /><span class="break-words">{{ testResult[rule.id].error }}</span></div>
          </div>

          <div class="flex items-center gap-1 shrink-0">
            <template v-if="muteMode">
              <Tooltip :content="isMuted(rule) ? __('Muted for this webhook — click to unmute') : __('Active for this webhook — click to mute')">
                <Button type="button" variant="ghost" size="sm" @click="toggleMute(rule)">
                  <BellOff v-if="isMuted(rule)" class="h-4 w-4 text-muted-foreground" />
                  <Bell v-else class="h-4 w-4" />
                </Button>
              </Tooltip>
            </template>
            <template v-else>
              <Tooltip :content="__('Send a test now')">
                <Button type="button" variant="ghost" size="sm" :disabled="testing[rule.id]" @click="sendTest(rule)"><Send class="h-4 w-4" /></Button>
              </Tooltip>
              <Switch :model-value="rule.is_enabled" @update:model-value="toggleEnabled(rule)" />
              <Button type="button" variant="ghost" size="sm" :title="__('Edit')" @click="openEdit(rule)"><Pencil class="h-4 w-4" /></Button>
              <Button type="button" variant="ghost" size="sm" :title="__('Delete')" class="text-destructive hover:text-destructive" @click="openDelete(rule)"><Trash2 class="h-4 w-4" /></Button>
            </template>
          </div>
        </div>
      </li>
    </ul>

    <RuleEditor :open="showEditor" :rule="editing" :webhook-id="webhookId" :ai-available="aiAvailable" @close="showEditor = false" @saved="fetchRules" />

    <Dialog :open="showDelete" :title="__('Delete rule?')" @close="showDelete = false">
      <p class="text-sm text-muted-foreground">{{ __('Nothing will be sent for this rule any more. Past notifications stay in the log.') }}</p>
      <template #footer>
        <Button variant="outline" @click="showDelete = false">{{ __('Cancel') }}</Button>
        <Button variant="destructive" :disabled="deleting" @click="handleDelete">{{ deleting ? __('Deleting…') : __('Delete') }}</Button>
      </template>
    </Dialog>
  </div>
</template>
