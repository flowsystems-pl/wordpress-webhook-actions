<script setup>
import { ref, onMounted, watch } from 'vue'
import { Card, Select, Alert, Input, Button } from '@/components/ui'
import { Loader2 } from 'lucide-vue-next'
import LogsTable from '@/components/LogsTable.vue'
import api from '@/lib/api'

const logs = ref([])
const total = ref(0)
const page = ref(1)
const perPage = ref(20)
const loading = ref(true)
const error = ref(null)
const stats = ref(null)

const statusFilter = ref('')
const statusOptions = [
  { value: '', label: 'All statuses' },
  { value: 'success', label: 'Success' },
  { value: 'error', label: 'Error' },
  { value: 'retry', label: 'Retry' },
  { value: 'pending', label: 'Pending' },
  { value: 'permanently_failed', label: 'Permanently Failed' },
]

const eventUuidFilter = ref('')
const targetUrlFilter = ref('')

const selectedIds = ref([])
const bulkRetrying = ref(false)

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

const handleBulkRetry = async () => {
  if (!selectedIds.value.length) return
  bulkRetrying.value = true
  error.value = null
  try {
    await api.logs.bulkRetry(selectedIds.value)
    selectedIds.value = []
    await loadLogs()
  } catch (e) {
    console.error('Failed to bulk retry:', e)
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
        <div class="text-lg sm:text-2xl font-bold text-red-600">{{ stats.error }}</div>
        <div class="text-xs sm:text-sm text-muted-foreground">Errors</div>
      </Card>
      <Card class="p-2 sm:p-4">
        <div class="text-lg sm:text-2xl font-bold text-yellow-600">{{ stats.retry }}</div>
        <div class="text-xs sm:text-sm text-muted-foreground">Retries</div>
      </Card>
    </div>

    <!-- Filters -->
    <div class="flex flex-wrap items-center gap-3 mb-4">
      <Select
        v-model="statusFilter"
        :options="statusOptions"
        class="w-full sm:w-48"
      />
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
        {{ bulkRetrying ? 'Retrying...' : `Retry ${selectedIds.length} selected` }}
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

    <!-- Table -->
    <LogsTable
      :logs="logs"
      :total="total"
      :page="page"
      :per-page="perPage"
      :loading="loading"
      :selected-ids="selectedIds"
      @page-change="handlePageChange"
      @delete="handleDelete"
      @retry="handleRetry"
      @update:selected-ids="selectedIds = $event"
    />
  </div>
</template>
