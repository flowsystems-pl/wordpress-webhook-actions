<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { Card, Select, SelectTrigger, SelectValue, SelectContent, SelectItem, Alert, Input, Button, DateTimePicker, Dialog } from '@/components/ui'
import { pickerLocalToUtcDb } from '@/lib/dates'
import { Loader2 } from 'lucide-vue-next'
import LogsTable from '@/components/LogsTable.vue'
import api from '@/lib/api'

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
  { value: 'all', label: 'All statuses' },
  { value: 'success', label: 'Success' },
  { value: 'error', label: 'Error' },
  { value: 'retry', label: 'Retry' },
  { value: 'pending', label: 'Pending' },
  { value: 'permanently_failed', label: 'Permanently Failed' },
]

// Radix-vue Select requires non-empty string values; map '' <-> 'all'
const statusFilterSelect = computed({
  get: () => statusFilter.value || 'all',
  set: (val) => { statusFilter.value = val === 'all' ? '' : val },
})

const eventUuidFilter = ref('')
const targetUrlFilter = ref('')
const dateFromFilter = ref('')
const dateToFilter = ref('')

const selectedIds = ref([])
const bulkRetrying = ref(false)
const showReplaySuccess = ref(false)
const replayedJobId = ref(null)
const replayedLogId = ref(null)
const logsTable = ref(null)

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
    await api.logs.retry(id)
    await loadLogs()
  } catch (e) {
    console.error('Failed to retry log:', e)
    error.value = e.message
  }
}

const handleReplay = async (log) => {
  try {
    const res = await api.logs.replay(log.id)
    replayedJobId.value = res?.job_id ?? null
    replayedLogId.value = log.id
    await loadLogs()
    showReplaySuccess.value = true
  } catch (e) {
    error.value = e.message
  }
}

const executeReplayedJob = async () => {
  if (!replayedJobId.value) return
  try {
    await api.queue.execute({ id: replayedJobId.value })
    showReplaySuccess.value = false
    await loadLogs()
    const log = logs.value.find(l => l.id === replayedLogId.value)
    if (log) logsTable.value?.openDetails(log)
  } catch (e) {
    error.value = e.message
  }
}

const bulkActionLabel = computed(() => {
  const hasRetry  = selectedIds.value.some(id => logs.value.find(l => l.id === id)?.status === 'error')
  const hasReplay = selectedIds.value.some(id => ['success', 'permanently_failed'].includes(logs.value.find(l => l.id === id)?.status))
  if (hasRetry && hasReplay) return `Retry / Replay ${selectedIds.value.length} selected`
  if (hasReplay) return `Replay ${selectedIds.value.length} selected`
  return `Retry ${selectedIds.value.length} selected`
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
      if (status === 'error' || status === 'permanently_failed') retryIds.push(id)
      else if (status === 'success') replayIds.push(id)
    }
    const calls = []
    if (retryIds.length)  calls.push(api.logs.bulkRetry(retryIds))
    for (const id of replayIds) calls.push(api.logs.replay(id))
    await Promise.all(calls)
    selectedIds.value = []
    await loadLogs()
    if (replayIds.length) showReplaySuccess.value = true
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
watch(eventUuidFilter, resetPage)
watch(targetUrlFilter, resetPage)
watch(dateFromFilter, resetPage)
watch(dateToFilter, resetPage)

onMounted(() => {
  loadLogs()
  loadStats()
})
</script>

<template>
  <div>
    <!-- Header -->
    <div class="mb-6">
      <h2 class="text-xl font-semibold">Logs</h2>
      <p class="text-muted-foreground text-sm">View webhook delivery logs</p>
    </div>

    <!-- Stats -->
    <div v-if="stats" class="grid grid-cols-2 sm:grid-cols-4 gap-2 sm:gap-4 mb-6">
      <Card class="p-2 sm:p-4">
        <div class="text-lg sm:text-2xl font-bold">{{ stats.total }}</div>
        <div class="text-xs sm:text-sm text-muted-foreground">Total (7 days)</div>
      </Card>
      <Card class="p-2 sm:p-4">
        <div class="text-lg sm:text-2xl font-bold text-green-600">{{ stats.success }}</div>
        <div class="text-xs sm:text-sm text-muted-foreground">Success</div>
      </Card>
      <Card class="p-2 sm:p-4">
        <div class="text-lg sm:text-2xl font-bold text-red-600">{{ stats.error + (stats.permanently_failed ?? 0) }}</div>
        <div class="text-xs sm:text-sm text-muted-foreground">Errors</div>
      </Card>
      <Card class="p-2 sm:p-4">
        <div class="text-lg sm:text-2xl font-bold text-yellow-600">{{ stats.retry }}</div>
        <div class="text-xs sm:text-sm text-muted-foreground">Retries</div>
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
      <Input
        v-model="eventUuidFilter"
        placeholder="Filter by event UUID..."
        class="w-full sm:w-72"
      />
      <Input
        v-model="targetUrlFilter"
        placeholder="Filter by target URL..."
        class="w-full sm:w-64"
      />
      <DateTimePicker
        v-model="dateFromFilter"
        placeholder="From date & time"
        class="w-full sm:w-52"
      />
      <DateTimePicker
        v-model="dateToFilter"
        placeholder="To date & time"
        class="w-full sm:w-52"
      />
      <Loader2 v-if="loading" class="h-4 w-4 animate-spin text-muted-foreground shrink-0" />
    </div>

    <!-- Bulk actions -->
    <div v-if="selectedIds.length > 0" class="flex items-center gap-3 mb-4 p-2 bg-muted/50 rounded-md">
      <span class="text-sm text-muted-foreground">{{ selectedIds.length }} selected</span>
      <Button
        size="sm"
        variant="default"
        :disabled="bulkRetrying"
        @click="handleBulkRetry"
      >
        {{ bulkRetrying ? 'Processing...' : bulkActionLabel }}
      </Button>
      <Button
        size="sm"
        variant="ghost"
        @click="selectedIds = []"
      >
        Clear selection
      </Button>
    </div>

    <!-- Error -->
    <Alert v-if="error" variant="destructive" class="mb-4">
      {{ error }}
    </Alert>

    <!-- Replay success dialog -->
    <Dialog
      :open="showReplaySuccess"
      title="Event Replayed"
      description="A new delivery attempt has been queued. The result will appear in this log's attempt history on the next cron run."
      @close="showReplaySuccess = false"
    >
      <template #footer>
        <div class="flex gap-2">
          <Button @click="executeReplayedJob">
            Execute Now
          </Button>
          <Button variant="outline" @click="() => { showReplaySuccess = false; router.push({ name: 'Queue' }) }">
            Go to Queue
          </Button>
          <Button variant="outline" @click="showReplaySuccess = false">Close</Button>
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
