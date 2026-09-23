<script setup>
import { ref, onMounted } from 'vue'
import { Plus, Pencil, Trash2, Send, AlertTriangle, CheckCircle2, BellRing } from 'lucide-vue-next'
import { Button, Badge, Dialog, Tooltip } from '@/components/ui'
import ChannelForm from './ChannelForm.vue'
import api from '@/lib/api'
import { formatUtcDate } from '@/lib/dates'
import { useNotifications } from '@/composables/useNotifications'
import { __, _n, sprintf } from '@/i18n'

const { channels, load, refreshChannels, typeLabel } = useNotifications()

const loading = ref(true)
const error = ref(null)
const showForm = ref(false)
const editing = ref(null)
const testing = ref({})
const testResult = ref({})

const openCreate = () => { editing.value = null; showForm.value = true }
const openEdit = (channel) => { editing.value = channel; showForm.value = true }

const sendTest = async (channel) => {
  testing.value = { ...testing.value, [channel.id]: true }
  testResult.value = { ...testResult.value, [channel.id]: null }
  try {
    await api.notifications.channels.test(channel.id)
    testResult.value = { ...testResult.value, [channel.id]: { ok: true } }
  } catch (e) {
    testResult.value = { ...testResult.value, [channel.id]: { ok: false, error: e.message } }
  } finally {
    testing.value = { ...testing.value, [channel.id]: false }
    await refreshChannels()
  }
}

// Delete
const showDelete = ref(false)
const toDelete = ref(null)
const deleteInUse = ref(0)
const deleting = ref(false)
const deleteError = ref(null)

const openDelete = (channel) => { toDelete.value = channel; deleteInUse.value = 0; deleteError.value = null; showDelete.value = true }
const handleDelete = async (force = false) => {
  if (!toDelete.value) return
  deleting.value = true
  deleteError.value = null
  try {
    await api.notifications.channels.delete(toDelete.value.id, force)
    showDelete.value = false
    toDelete.value = null
    await refreshChannels()
  } catch (e) {
    if (e.code === 'rest_channel_in_use') {
      deleteInUse.value = e.data?.data?.rules_using || 1
    } else {
      deleteError.value = e.message
    }
  } finally {
    deleting.value = false
  }
}

onMounted(async () => {
  try {
    await load()
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <p class="text-sm text-muted-foreground max-w-2xl">
        {{ __('A channel is somewhere a message can go: an inbox, a Slack or Discord channel, a Telegram chat, a phone number, PagerDuty. Add one, then reference it from rules.') }}
      </p>
      <Button @click="openCreate"><Plus class="mr-2 h-4 w-4" />{{ __('New channel') }}</Button>
    </div>

    <div v-if="error" class="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">{{ error }}</div>
    <div v-if="loading" class="text-sm text-muted-foreground">{{ __('Loading…') }}</div>

    <div v-else-if="channels.length === 0" class="rounded-lg border border-dashed border-border p-12 text-center">
      <BellRing class="mx-auto mb-3 h-10 w-10 text-muted-foreground/50" />
      <h3 class="text-sm font-medium text-foreground">{{ __('No channels yet') }}</h3>
      <p class="mt-1 text-sm text-muted-foreground">{{ __('Start with your email address, or paste a Slack, Discord or Teams webhook URL.') }}</p>
      <Button class="mt-4" @click="openCreate"><Plus class="mr-2 h-4 w-4" />{{ __('Add first channel') }}</Button>
    </div>

    <div v-else class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
      <div
        v-for="channel in channels"
        :key="channel.id"
        class="rounded-lg border bg-card p-4 space-y-3"
        :class="channel.last_error ? 'border-amber-500/50' : 'border-border'"
      >
        <div class="flex items-start justify-between gap-2">
          <div class="min-w-0">
            <div class="font-medium text-foreground truncate">{{ channel.name }}</div>
            <div class="mt-1 flex flex-wrap items-center gap-1.5">
              <Badge variant="secondary">{{ typeLabel(channel.type) }}</Badge>
              <Badge v-if="!channel.is_enabled" variant="outline">{{ __('disabled') }}</Badge>
              <span v-if="channel.hint" class="font-mono text-xs text-muted-foreground">{{ channel.hint }}</span>
            </div>
          </div>
          <div class="flex items-center gap-1 shrink-0">
            <Tooltip :content="__('Send a test message')">
              <Button variant="ghost" size="sm" :disabled="testing[channel.id]" @click="sendTest(channel)">
                <Send class="h-4 w-4" />
              </Button>
            </Tooltip>
            <Button variant="ghost" size="sm" :title="__('Edit')" @click="openEdit(channel)"><Pencil class="h-4 w-4" /></Button>
            <Button variant="ghost" size="sm" :title="__('Delete')" class="text-destructive hover:text-destructive" @click="openDelete(channel)"><Trash2 class="h-4 w-4" /></Button>
          </div>
        </div>

        <div class="text-xs text-muted-foreground space-y-1">
          <div v-if="channel.config?.to">{{ __('To') }}: <span class="text-foreground">{{ channel.config.to }}</span></div>
          <div v-if="channel.config?.chat_id">{{ __('Chat') }}: <span class="font-mono text-foreground">{{ channel.config.chat_id }}</span></div>
          <div v-if="channel.config?.topic">{{ __('Topic') }}: <span class="font-mono text-foreground">{{ channel.config.topic }}</span></div>
          <div v-if="channel.config?.url">{{ __('URL') }}: <span class="font-mono text-foreground break-all">{{ channel.config.url }}</span></div>
          <div v-if="channel.last_sent_at">{{ __('Last sent') }}: {{ formatUtcDate(channel.last_sent_at) }}</div>
        </div>

        <div v-if="testResult[channel.id]?.ok" class="flex items-center gap-1.5 text-xs text-emerald-600 dark:text-emerald-400">
          <CheckCircle2 class="h-3.5 w-3.5" />{{ __('Test message sent.') }}
        </div>
        <div v-else-if="testResult[channel.id] && !testResult[channel.id].ok" class="flex items-start gap-1.5 text-xs text-destructive">
          <AlertTriangle class="h-3.5 w-3.5 mt-0.5 shrink-0" /><span class="break-words">{{ testResult[channel.id].error }}</span>
        </div>
        <div v-else-if="channel.last_error" class="flex items-start gap-1.5 rounded-md bg-amber-500/10 p-2 text-xs text-amber-700 dark:text-amber-400">
          <AlertTriangle class="h-3.5 w-3.5 mt-0.5 shrink-0" />
          <span class="break-words">{{ sprintf(__('Last send failed: %s'), channel.last_error) }}</span>
        </div>
      </div>
    </div>

    <ChannelForm :open="showForm" :channel="editing" @close="showForm = false" />

    <Dialog :open="showDelete" :title="__('Delete channel?')" @close="showDelete = false">
      <p class="text-sm text-muted-foreground">
        {{ sprintf(__('Delete "%s"? Its secret is erased and cannot be recovered.'), toDelete?.name || '') }}
      </p>
      <div v-if="deleteInUse > 0" class="mt-3 rounded-md border border-amber-500/50 bg-amber-500/10 p-3 text-sm">
        {{ sprintf(_n('This channel is used by %d rule. Deleting it removes the channel from that rule.', 'This channel is used by %d rules. Deleting it removes the channel from those rules.', deleteInUse), deleteInUse) }}
      </div>
      <div v-if="deleteError" class="mt-3 text-sm text-destructive">{{ deleteError }}</div>
      <template #footer>
        <Button variant="outline" @click="showDelete = false">{{ __('Cancel') }}</Button>
        <Button v-if="deleteInUse > 0" variant="destructive" :disabled="deleting" @click="handleDelete(true)">{{ __('Delete anyway') }}</Button>
        <Button v-else variant="destructive" :disabled="deleting" @click="handleDelete(false)">{{ deleting ? __('Deleting…') : __('Delete') }}</Button>
      </template>
    </Dialog>
  </div>
</template>
