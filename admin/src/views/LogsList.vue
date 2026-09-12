<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { Card, Select, SelectTrigger, SelectValue, SelectContent, SelectItem, Alert, Input, Button, DateTimePicker, Dialog } from '@/components/ui'
import { pickerLocalToUtcDb } from '@/lib/dates'
import { Loader2 } from 'lucide-vue-next'
import LogsTable from '@/components/LogsTable.vue'
import api from '@/lib/api'
import { useChains } from '@/composables/useChains'
import { __, sprintf } from '@/i18n'

const router = useRouter()

const logs = ref([])
const total = ref(0)
const page = ref(1)
const perPage = ref(20)
const loading = ref(true)
const error = ref(null)
const stats = ref(null)

const statusFilter = ref('')
const statusOptions = [
  { value: 'all', label: __('All statuses') },
  { value: 'success', label: __('Success') },
  { value: 'error', label: __('Error') },
  { value: 'retry', label: __('Retry') },
  { value: 'pending', label: __('Pending') },
  { value: 'test',               label: __('Test') },
  { value: 'skipped',            label: __('Skipped') },
  { value: 'permanently_failed', label: __('Permanently Failed') },
]

// Radix-vue Select requires non-empty string values; map '' <-> 'all'
const statusFilterSelect = computed({
  get: () => statusFilter.value || 'all',
  set: (val) => { statusFilter.value = val === 'all' ? '' : val },
})

const webhookUuidFilter = ref('')
const eventUuidFilter = ref('')
const targetUrlFilter = ref('')
const dateFromFilter = ref('')
const dateToFilter = ref('')
const chainFilter = ref('')

const { chains, refresh: refreshChains } = useChains()
const chainFilterSelect = computed({
  get: () => chainFilter.value || 'all',
  set: (val) => { chainFilter.value = val === 'all' ? '' : val },
})

const selectedIds = ref([])
const bulkRetrying = ref(false)
// What the last retry/replay re-queued. A retry or replay only marks the
// queue job pending; the delivery itself happens when the queue next drains,
// which on a quiet site without External Cron can be a long wait that looks
// like "nothing happened". So every path that queues something opens the
// same dialog with a "Run now" that executes those exact jobs.
const queued = ref(null)   // { jobIds: [], logIds: [], kind: 'retry' | 'replay' | 'mixed', skipped: 0 }
const runningQueued = ref(false)
const logsTable = ref(null)

const openQueued = ({ jobIds, logIds, kind, skipped = 0 }) => {
  const ids = jobIds.filter(Boolean)
  if (!ids.length && !skipped) return
  queued.value = { jobIds: ids, logIds, kind, skipped }
}

const queuedTitle = computed(() => {
  if (!queued.value) return ''
  const n = queued.value.jobIds.length
  if (n === 0) return __('Nothing was queued')
  if (queued.value.kind === 'retry')  return n === 1 ? __('Retry queued') : sprintf(__('%d retries queued'), n)
  if (queued.value.kind === 'replay') return n === 1 ? __('Event replayed') : sprintf(__('%d events replayed'), n)
  return sprintf(__('%d deliveries queued'), n)
})

const queuedDescription = computed(() => {
  if (!queued.value) return ''
  const parts = []
  if (queued.value.jobIds.length) {
    parts.push(__('The delivery runs when the queue next drains — on the next cron tick. On a quiet site that can take a while, so you can also run it right now.'))
  }
  if (queued.value.skipped) {
    parts.push(sprintf(__('%d of the selected entries could not be queued: no queue job is left for them.'), queued.value.skipped))
  }
  return parts.join(' ')
})

const loadLogs = async () => {
  loading.value = true
  error.value = null

  try {
    const params = {
      page: page.value,
      per_page: perPage.value,
    }

    if (statusFilter.value) {
      params.status = statusFilter.value
    }

    if (webhookUuidFilter.value) {
      params.webhook_uuid = webhookUuidFilter.value
    }

    if (eventUuidFilter.value) {
      params.event_uuid = eventUuidFilter.value
    }

    if (targetUrlFilter.value) {
      params.target_url = targetUrlFilter.value
    }

    if (dateFromFilter.value) {
      params.date_from = pickerLocalToUtcDb(dateFromFilter.value)
    }

    if (dateToFilter.value) {
      params.date_to = pickerLocalToUtcDb(dateToFilter.value)
    }

    if (chainFilter.value) {
      params.chain_id = chainFilter.value
    }

    const result = await api.logs.list(params)
    logs.value = result.items
    total.value = result.total
  } catch (e) {
    error.value = e.message
    console.error('Failed to load logs:', e)
  } finally {
    loading.value = false
  }
}

const loadStats = async () => {
  try {
    stats.value = await api.logs.stats({ days: 7 })
  } catch (e) {
    console.error('Failed to load stats:', e)
  }
}

const handlePageChange = (newPage) => {
  page.value = newPage
}

const handleDelete = async (id) => {
  try {
    await api.logs.delete(id)
    await loadLogs()
    await loadStats()
  } catch (e) {
    console.error('Failed to delete log:', e)
  }
}

const handleRetry = async (id) => {
  try {
    const res = await api.logs.retry(id)
    await loadLogs()
    openQueued({ jobIds: [res?.job_id], logIds: [id], kind: 'retry' })
  } catch (e) {
    console.error('Failed to retry log:', e)
    error.value = e.message
  }
}

const handleReplay = async (log) => {
  try {
    const res = await api.logs.replay(log.id)
    await loadLogs()
    openQueued({ jobIds: [res?.job_id], logIds: [log.id], kind: 'replay' })
  } catch (e) {
    error.value = e.message
  }
}

// Execute the queued jobs one after another (each is a real HTTP delivery).
// A job the cron already picked up answers rest_job_completed — that is the
// outcome we wanted, not an error.
const runQueuedNow = async () => {
  if (!queued.value?.jobIds.length || runningQueued.value) return
  runningQueued.value = true
  const { jobIds, logIds } = queued.value
  try {
    for (const id of jobIds) {
      try {
        await api.queue.execute({ id })
      } catch (e) {
        if (e.code !== 'rest_job_completed') throw e
      }
    }
    queued.value = null
    await loadLogs()
    await loadStats()
    if (logIds.length === 1) {
      const log = logs.value.find(l => l.id === logIds[0])
      if (log) logsTable.value?.openDetails(log)
    }
  } catch (e) {
    error.value = e.message
  } finally {
    runningQueued.value = false
  }
}

// Same split the handler uses: failed entries are retried (their queue job
// is re-armed), delivered or skipped ones are replayed (a fresh job).
const RETRY_STATUSES  = ['error', 'permanently_failed']
const REPLAY_STATUSES = ['success', 'skipped']

const bulkActionLabel = computed(() => {
  const hasRetry  = selectedIds.value.some(id => RETRY_STATUSES.includes(logs.value.find(l => l.id === id)?.status))
  const hasReplay = selectedIds.value.some(id => REPLAY_STATUSES.includes(logs.value.find(l => l.id === id)?.status))
  if (hasRetry && hasReplay) return sprintf(__('Retry / Replay %d selected'), selectedIds.value.length)
  if (hasReplay) return sprintf(__('Replay %d selected'), selectedIds.value.length)
  return sprintf(__('Retry %d selected'), selectedIds.value.length)
})

const handleBulkRetry = async () => {
  if (!selectedIds.value.length) return
  bulkRetrying.value = true
  error.value = null
  try {
    const retryIds  = []
    const replayIds = []
    for (const id of selectedIds.value) {
      const status = logs.value.find(l => l.id === id)?.status
      if (RETRY_STATUSES.includes(status)) retryIds.push(id)
      else if (REPLAY_STATUSES.includes(status)) replayIds.push(id)
    }
    const jobIds = []
    let skipped  = 0
    if (retryIds.length) {
      const res = await api.logs.bulkRetry(retryIds)
      jobIds.push(...(res?.job_ids ?? []))
      skipped += res?.skipped ?? 0
    }
    const replays = await Promise.all(replayIds.map(id => api.logs.replay(id)))
    jobIds.push(...replays.map(r => r?.job_id))
    selectedIds.value = []
    await loadLogs()
    const kind = retryIds.length && replayIds.length ? 'mixed' : (replayIds.length ? 'replay' : 'retry')
    openQueued({ jobIds, logIds: [...retryIds, ...replayIds], kind, skipped })
  } catch (e) {
    console.error('Failed to bulk action:', e)
    error.value = e.message
  } finally {
    bulkRetrying.value = false
  }
}

const resetPage = () => {
  if (page.value === 1) {
    loadLogs()
  } else {
    page.value = 1
  }
}

watch(page, () => {
  loadLogs()
})

watch(statusFilter, resetPage)
watch(webhookUuidFilter, resetPage)
watch(eventUuidFilter, resetPage)
watch(targetUrlFilter, resetPage)
watch(dateFromFilter, resetPage)
watch(dateToFilter, resetPage)
watch(chainFilter, resetPage)

onMounted(() => {
  loadLogs()
  loadStats()
  refreshChains().catch(() => {})
})
</script>

<template>
  <div>
    <!-- Header -->
    <div class="mb-6">
      <h2 class="text-xl font-semibold">{{ __('Logs') }}</h2>
      <p class="text-muted-foreground text-sm">{{ __('View webhook delivery logs') }}</p>
    </div>

    <!-- Stats -->
    <div v-if="stats" class="grid grid-cols-2 sm:grid-cols-5 gap-2 sm:gap-4 mb-6">
      <Card class="p-2 sm:p-4">
        <div class="text-lg sm:text-2xl font-bold">{{ stats.total }}</div>
        <div class="text-xs sm:text-sm text-muted-foreground">{{ __('Total (7 days)') }}</div>
      </Card>
      <Card class="p-2 sm:p-4">
        <div class="text-lg sm:text-2xl font-bold text-green-600">{{ stats.success }}</div>
        <div class="text-xs sm:text-sm text-muted-foreground">{{ __('Success') }}</div>
      </Card>
      <Card class="p-2 sm:p-4">
        <div class="text-lg sm:text-2xl font-bold text-red-600">{{ stats.error + (stats.permanently_failed ?? 0) }}</div>
        <div class="text-xs sm:text-sm text-muted-foreground">{{ __('Errors') }}</div>
      </Card>
      <Card class="p-2 sm:p-4">
        <div class="text-lg sm:text-2xl font-bold text-yellow-600">{{ stats.retry }}</div>
        <div class="text-xs sm:text-sm text-muted-foreground">{{ __('Retries') }}</div>
      </Card>
      <Card class="p-2 sm:p-4">
        <div class="text-lg sm:text-2xl font-bold text-amber-600">{{ stats.skipped ?? 0 }}</div>
        <div class="text-xs sm:text-sm text-muted-foreground">{{ __('Skipped') }}</div>
      </Card>
    </div>

    <!-- Filters -->
    <div class="flex flex-wrap items-center gap-3 mb-4">
      <Select v-model="statusFilterSelect">
        <SelectTrigger class="w-full sm:w-48">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem v-for="option in statusOptions" :key="option.value" :value="option.value">
            {{ option.label }}
          </SelectItem>
        </SelectContent>
      </Select>
      <Select v-if="chains.length" v-model="chainFilterSelect">
        <SelectTrigger class="w-full sm:w-56">
          <SelectValue :placeholder="__('All chains')" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="all">{{ __('All chains') }}</SelectItem>
          <SelectItem v-for="c in chains" :key="c.id" :value="String(c.id)">
            {{ c.name }}
          </SelectItem>
        </SelectContent>
      </Select>
      <Input
        v-model="webhookUuidFilter"
        :placeholder="__('Filter by X-Webhook-ID…')"
        class="w-full sm:w-72"
      />
      <Input
        v-model="eventUuidFilter"
        :placeholder="__('Filter by event UUID…')"
        class="w-full sm:w-72"
      />
      <Input
        v-model="targetUrlFilter"
        :placeholder="__('Filter by target URL…')"
        class="w-full sm:w-64"
      />
      <DateTimePicker
        v-model="dateFromFilter"
        :placeholder="__('From date & time')"
        class="w-full sm:w-52"
      />
      <DateTimePicker
        v-model="dateToFilter"
        :placeholder="__('To date & time')"
        class="w-full sm:w-52"
      />
      <Loader2 v-if="loading" class="h-4 w-4 animate-spin text-muted-foreground shrink-0" />
    </div>

    <!-- Bulk actions -->
    <div v-if="selectedIds.length > 0" class="flex items-center gap-3 mb-4 p-2 bg-muted/50 rounded-md">
      <span class="text-sm text-muted-foreground">{{ sprintf(__('%d selected'), selectedIds.length) }}</span>
      <Button
        size="sm"
        variant="default"
        :disabled="bulkRetrying"
        @click="handleBulkRetry"
      >
        {{ bulkRetrying ? __('Processing…') : bulkActionLabel }}
      </Button>
      <Button
        size="sm"
        variant="ghost"
        @click="selectedIds = []"
      >
        {{ __('Clear selection') }}
      </Button>
    </div>

    <!-- Error -->
    <Alert v-if="error" variant="destructive" class="mb-4">
      {{ error }}
    </Alert>

    <!-- Queued dialog: a retry or replay was re-queued; offer to run it now -->
    <Dialog
      :open="!!queued"
      :title="queuedTitle"
      :description="queuedDescription"
      @close="queued = null"
    >
      <template #footer>
        <div class="flex gap-2">
          <Button v-if="queued?.jobIds.length" :disabled="runningQueued" @click="runQueuedNow">
            {{ runningQueued ? __('Running…') : (queued?.jobIds.length === 1 ? __('Run now') : sprintf(__('Run %d now'), queued?.jobIds.length ?? 0)) }}
          </Button>
          <Button variant="outline" @click="() => { queued = null; router.push({ name: 'Queue' }) }">
            {{ __('Go to Queue') }}
          </Button>
          <Button variant="outline" @click="queued = null">{{ __('Close') }}</Button>
        </div>
      </template>
    </Dialog>

    <!-- Table -->
    <LogsTable
      ref="logsTable"
      :logs="logs"
      :total="total"
      :page="page"
      :per-page="perPage"
      :loading="loading"
      :selected-ids="selectedIds"
      @page-change="handlePageChange"
      @delete="handleDelete"
      @retry="handleRetry"
      @replay="handleReplay"
      @update:selected-ids="selectedIds = $event"
    />
  </div>
</template>
