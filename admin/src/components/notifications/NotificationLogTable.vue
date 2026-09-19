<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { ChevronLeft, ChevronRight, RotateCcw, RefreshCw } from 'lucide-vue-next'
import { Button, Badge, Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/components/ui'
import api from '@/lib/api'
import { formatUtcDate } from '@/lib/dates'
import { useNotifications } from '@/composables/useNotifications'
import { __, sprintf } from '@/i18n'

const props = defineProps({
  webhookId: { type: Number, default: null },
  logId: { type: Number, default: null },
})

const { eventLabel, typeLabel } = useNotifications()

const items = ref([])
const total = ref(0)
const pending = ref(0)
const page = ref(1)
const perPage = 25
const status = ref('all')
const loading = ref(false)
const error = ref(null)
const resending = ref({})

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage)))

const fetch = async () => {
  loading.value = true
  error.value = null
  try {
    const data = await api.notifications.log({
      page: page.value,
      per_page: perPage,
      status: status.value === 'all' ? undefined : status.value,
      webhook_id: props.webhookId || undefined,
      log_id: props.logId || undefined,
    })
    items.value = data.items
    total.value = data.total
    pending.value = data.pending
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}
onMounted(fetch)
watch([page, status], fetch)
watch(() => [props.webhookId, props.logId], () => { page.value = 1; fetch() })

const resend = async (row) => {
  resending.value = { ...resending.value, [row.id]: true }
  try {
    await api.notifications.resend(row.id)
    await fetch()
  } catch (e) {
    error.value = e.message
  } finally {
    resending.value = { ...resending.value, [row.id]: false }
  }
}

const statusVariant = (s) => ({ sent: 'default', failed: 'destructive', pending: 'secondary', sending: 'secondary', throttled: 'outline', digested: 'outline', in_digest: 'outline' })[s] || 'outline'
const statusLabel = (s) => ({
  sent: __('sent'), failed: __('failed'), pending: __('pending'), sending: __('sending'),
  throttled: __('throttled'), digested: __('waiting for digest'), in_digest: __('in digest'),
})[s] || s
</script>

<template>
  <div class="space-y-3">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div class="text-xs text-muted-foreground">
        {{ sprintf(__('%d entries'), total) }}<span v-if="pending"> · {{ sprintf(__('%d pending'), pending) }}</span>
      </div>
      <div class="flex items-center gap-2">
        <Select v-model="status">
          <SelectTrigger class="w-44 h-8 text-xs"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem value="all">{{ __('All statuses') }}</SelectItem>
            <SelectItem value="sent">{{ __('Sent') }}</SelectItem>
            <SelectItem value="failed">{{ __('Failed') }}</SelectItem>
            <SelectItem value="pending">{{ __('Pending') }}</SelectItem>
            <SelectItem value="throttled">{{ __('Throttled') }}</SelectItem>
            <SelectItem value="digested">{{ __('Waiting for digest') }}</SelectItem>
          </SelectContent>
        </Select>
        <Button variant="outline" size="sm" :disabled="loading" @click="fetch"><RefreshCw class="h-3.5 w-3.5" /></Button>
      </div>
    </div>

    <div v-if="error" class="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">{{ error }}</div>

    <div class="rounded-md border border-border overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-border bg-muted/50 text-left text-xs font-medium text-muted-foreground">
            <th class="px-3 py-2">{{ __('When') }}</th>
            <th class="px-3 py-2">{{ __('Status') }}</th>
            <th class="px-3 py-2">{{ __('Event') }}</th>
            <th v-if="!webhookId" class="px-3 py-2">{{ __('Webhook') }}</th>
            <th class="px-3 py-2">{{ __('Rule') }}</th>
            <th class="px-3 py-2">{{ __('Channel') }}</th>
            <th class="px-3 py-2">{{ __('Subject') }}</th>
            <th class="px-3 py-2 text-right"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="loading && items.length === 0"><td :colspan="webhookId ? 7 : 8" class="px-3 py-6 text-center text-muted-foreground">{{ __('Loading…') }}</td></tr>
          <tr v-else-if="items.length === 0"><td :colspan="webhookId ? 7 : 8" class="px-3 py-6 text-center text-muted-foreground">{{ __('Nothing sent yet.') }}</td></tr>
          <tr v-for="row in items" :key="row.id" class="border-b border-border last:border-0 hover:bg-muted/30">
            <td class="px-3 py-2 whitespace-nowrap text-muted-foreground">{{ formatUtcDate(row.created_at) }}</td>
            <td class="px-3 py-2">
              <Badge :variant="statusVariant(row.status)">{{ statusLabel(row.status) }}</Badge>
              <div v-if="row.error" class="mt-1 max-w-xs text-xs text-destructive break-words">{{ row.error }}</div>
            </td>
            <td class="px-3 py-2 whitespace-nowrap">{{ eventLabel(row.event) }}</td>
            <td v-if="!webhookId" class="px-3 py-2">{{ row.webhook_name || '—' }}</td>
            <td class="px-3 py-2">{{ row.rule_name || (row.rule_id ? `#${row.rule_id}` : '—') }}</td>
            <td class="px-3 py-2">
              <span v-if="row.channel_name">{{ row.channel_name }} <span class="text-xs text-muted-foreground">{{ typeLabel(row.channel_type) }}</span></span>
              <span v-else class="text-muted-foreground">—</span>
            </td>
            <td class="px-3 py-2 max-w-xs truncate" :title="row.subject">{{ row.subject }}</td>
            <td class="px-3 py-2 text-right whitespace-nowrap">
              <Button v-if="row.status === 'failed'" variant="ghost" size="sm" :disabled="resending[row.id]" :title="__('Send again')" @click="resend(row)"><RotateCcw class="h-4 w-4" /></Button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="totalPages > 1" class="flex items-center justify-end gap-2 text-xs text-muted-foreground">
      <span>{{ sprintf(__('Page %1$d of %2$d'), page, totalPages) }}</span>
      <Button variant="outline" size="sm" :disabled="page <= 1" @click="page--"><ChevronLeft class="h-4 w-4" /></Button>
      <Button variant="outline" size="sm" :disabled="page >= totalPages" @click="page++"><ChevronRight class="h-4 w-4" /></Button>
    </div>
  </div>
</template>
