<script setup>
import { ref, onMounted, computed, watch } from 'vue'
import { Card, Button, Badge, Alert, Input } from '@/components/ui'
import { Play, Trash2, RefreshCw, Clock, RotateCcw, ChevronLeft, ChevronRight, Loader2 } from 'lucide-vue-next'
import api from '@/lib/api'
import { useHealthStats } from '@/composables/useHealthStats'

const { fetchStats: refreshHealthStats } = useHealthStats()

const items = ref([])
const total = ref(0)
const page = ref(1)
const perPage = ref(20)
const eventUuidFilter = ref('')
const targetUrlFilter = ref('')
const stats = ref({ pending: 0, processing: 0, completed: 0, failed: 0, total: 0, due_now: 0 })
const loading = ref(true)
const statsLoading = ref(false)
const error = ref(null)
const executingId = ref(null)
const deletingId = ref(null)
const retryingId = ref(null)
const processing = ref(false)

const totalPages = computed(() => Math.ceil(total.value / perPage.value))

const loadStats = async () => {
  statsLoading.value = true
  try {
    stats.value = await api.queue.stats()
    refreshHealthStats()
  } catch (e) {
    console.error('Failed to load queue stats:', e)
  } finally {
    statsLoading.value = false
  }
}

const loadQueue = async () => {
  loading.value = true
  error.value = null

  try {
    const params = { page: page.value, per_page: perPage.value }
    if (eventUuidFilter.value) params.event_uuid = eventUuidFilter.value
    if (targetUrlFilter.value) params.target_url = targetUrlFilter.value

    const queueResponse = await api.queue.list(params)
    items.value = queueResponse.items || queueResponse || []
    total.value = queueResponse.total || items.value.length
  } catch (e) {
    error.value = e.message
    console.error('Failed to load queue:', e)
  } finally {
    loading.value = false
  }
}

const executeItem = async (item) => {
  executingId.value = item.id
  try {
    await api.queue.execute({ id: item.id })
    await Promise.all([loadQueue(), loadStats()])
  } catch (e) {
    error.value = e.message
    console.error('Failed to execute:', e)
  } finally {
    executingId.value = null
  }
}

const deleteItem = async (item) => {
  deletingId.value = item.id
  try {
    await api.queue.delete({ id: item.id })
    await Promise.all([loadQueue(), loadStats()])
  } catch (e) {
    error.value = e.message
    console.error('Failed to delete:', e)
  } finally {
    deletingId.value = null
  }
}

const retryItem = async (item) => {
  retryingId.value = item.id
  try {
    await api.queue.retry({ id: item.id })
    await Promise.all([loadQueue(), loadStats()])
  } catch (e) {
    error.value = e.message
    console.error('Failed to retry:', e)
  } finally {
    retryingId.value = null
  }
}

const processQueue = async () => {
  processing.value = true
  try {
    const result = await api.dispatcher.process({ batch_size: 10 })
    await Promise.all([loadQueue(), loadStats()])
    if (result.result?.processed > 0) {
      // Could show a toast here
    }
  } catch (e) {
    error.value = e.message
    console.error('Failed to process queue:', e)
  } finally {
    processing.value = false
  }
}

const getStatusBadgeVariant = (status) => {
  switch (status) {
    case 'pending': return 'secondary'
    case 'processing': return 'warning'
    case 'completed': return 'success'
    case 'failed': return 'destructive'
    default: return 'secondary'
  }
}

const prevPage = () => {
  if (page.value > 1) {
    page.value--
  }
}

const nextPage = () => {
  if (page.value < totalPages.value) {
    page.value++
  }
}

const resetPage = () => {
  if (page.value === 1) {
    loadQueue()
  } else {
    page.value = 1
  }
}

watch(page, loadQueue)
watch(eventUuidFilter, resetPage)
watch(targetUrlFilter, resetPage)

onMounted(() => {
  loadQueue()
  loadStats()
})
</script>

<template>
  <div>
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
      <div>
        <h2 class="text-xl font-semibold">Queue</h2>
        <p class="text-muted-foreground text-sm">Database-backed webhook job queue</p>
      </div>
      <div class="flex gap-2">
        <Button variant="default" @click="processQueue" :loading="processing" class="text-xs sm:text-sm">
          <Play class="w-4 h-4 mr-1 sm:mr-2" />
          Process Now
        </Button>
        <Button variant="outline" @click="loadQueue" :loading="loading" class="text-xs sm:text-sm">
          <RefreshCw class="w-4 h-4 mr-1 sm:mr-2" />
          Refresh
        </Button>
      </div>
    </div>

    <!-- Stats -->
    <div class="relative mb-6">
      <div v-if="statsLoading" class="absolute inset-0 z-10 flex items-center justify-center rounded-md bg-background/60 backdrop-blur-[1px]">
        <Loader2 class="h-4 w-4 animate-spin text-muted-foreground" />
      </div>
      <div class="grid grid-cols-3 sm:grid-cols-5 gap-2 sm:gap-4">
        <Card class="p-2 sm:p-4">
          <div class="text-lg sm:text-2xl font-bold tabular-nums">{{ stats.total }}</div>
          <div class="text-xs sm:text-sm text-muted-foreground">Total</div>
        </Card>
        <Card class="p-2 sm:p-4">
          <div class="text-lg sm:text-2xl font-bold tabular-nums text-blue-600">{{ stats.pending }}</div>
          <div class="text-xs sm:text-sm text-muted-foreground">Pending</div>
        </Card>
        <Card class="p-2 sm:p-4">
          <div class="text-lg sm:text-2xl font-bold tabular-nums text-yellow-600">{{ stats.processing }}</div>
          <div class="text-xs sm:text-sm text-muted-foreground">Processing</div>
        </Card>
        <Card class="p-2 sm:p-4">
          <div class="text-lg sm:text-2xl font-bold tabular-nums text-orange-600">{{ stats.due_now }}</div>
          <div class="text-xs sm:text-sm text-muted-foreground">Due Now</div>
        </Card>
        <Card class="p-2 sm:p-4">
          <div class="text-lg sm:text-2xl font-bold tabular-nums text-red-600">{{ stats.failed }}</div>
          <div class="text-xs sm:text-sm text-muted-foreground">Failed</div>
        </Card>
      </div>
    </div>

    <!-- Filters -->
    <div class="flex flex-wrap items-center gap-3 mb-4">
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

    <!-- Error -->
    <Alert v-if="error" variant="destructive" class="mb-4">
      {{ error }}
    </Alert>

    <!-- Initial loading (no data yet) -->
    <Card v-if="loading && items.length === 0" class="p-8 text-center">
      <RefreshCw class="w-8 h-8 mx-auto text-muted-foreground mb-4 animate-spin" />
      <p class="text-muted-foreground">Loading queue...</p>
    </Card>

    <!-- Empty state -->
    <Card v-else-if="!loading && items.length === 0" class="p-8 text-center">
      <Clock class="w-12 h-12 mx-auto text-muted-foreground mb-4" />
      <h3 class="text-lg font-medium mb-2">No queued jobs</h3>
      <p class="text-muted-foreground">
        When webhooks are triggered, jobs will appear here for processing.
      </p>
    </Card>

    <!-- Queue list -->
    <div v-else class="relative">
      <div v-if="loading" class="absolute inset-0 z-10 flex items-center justify-center rounded-md bg-background/60 backdrop-blur-[1px]">
        <Loader2 class="h-6 w-6 animate-spin text-muted-foreground" />
      </div>
      <div class="space-y-3">
      <Card v-for="item in items" :key="item.id" class="p-4">
        <div class="flex flex-col sm:flex-row items-start justify-between gap-4">
          <div class="flex-1 min-w-0 max-w-full">
            <div class="flex flex-wrap items-center gap-2 mb-2">
              <span class="font-medium truncate">{{ item.webhook_name || 'Unknown Webhook' }}</span>
              <Badge :variant="getStatusBadgeVariant(item.status)">{{ item.status }}</Badge>
              <Badge v-if="item.attempts > 0" variant="outline">
                Attempt {{ item.attempts + 1 }}/{{ item.max_attempts }}
              </Badge>
              <Badge v-if="item.is_due && item.status === 'pending'" variant="warning">Due</Badge>
            </div>

            <div class="text-sm text-muted-foreground space-y-1">
              <div class="flex items-center gap-2">
                <span class="font-medium">Trigger:</span>
                <code class="px-1.5 py-0.5 bg-muted rounded text-xs">{{ item.trigger_name }}</code>
              </div>
              <div v-if="item.event_uuid" class="flex items-center gap-2">
                <span class="font-medium">Event UUID:</span>
                <Badge variant="secondary" class="font-mono rounded text-xs tracking-tight">{{ item.event_uuid }}</Badge>
              </div>
              <div class="flex items-center gap-2">
                <Clock class="w-4 h-4" />
                <span>{{ item.scheduled_at_human }}</span>
                <span class="text-muted-foreground/60">({{ item.scheduled_at }})</span>
              </div>
              <div v-if="item.webhook_url" class="truncate">
                <span class="font-medium">URL:</span>
                <span class="ml-1">{{ item.webhook_url }}</span>
              </div>
              <div v-if="item.locked_by" class="text-xs text-muted-foreground">
                Locked by: {{ item.locked_by }}
              </div>
            </div>
          </div>

          <div class="flex items-center gap-2 shrink-0">
            <!-- Execute button for pending/failed jobs -->
            <Button
              v-if="item.status === 'pending' || item.status === 'failed'"
              variant="outline"
              size="sm"
              @click="executeItem(item)"
              :loading="executingId === item.id"
              :disabled="deletingId === item.id || retryingId === item.id"
              title="Execute now"
            >
              <Play class="w-4 h-4 mr-1" />
              Execute
            </Button>

            <!-- Retry button for failed jobs -->
            <Button
              v-if="item.status === 'failed'"
              variant="outline"
              size="sm"
              @click="retryItem(item)"
              :loading="retryingId === item.id"
              :disabled="executingId === item.id || deletingId === item.id"
              title="Reset and retry"
            >
              <RotateCcw class="w-4 h-4 mr-1" />
              Retry
            </Button>

            <!-- Delete button -->
            <Button
              v-if="item.status !== 'processing'"
              variant="ghost"
              size="sm"
              @click="deleteItem(item)"
              :loading="deletingId === item.id"
              :disabled="executingId === item.id || retryingId === item.id"
              title="Remove from queue"
              class="text-destructive hover:text-destructive"
            >
              <Trash2 class="w-4 h-4" />
            </Button>
          </div>
        </div>
      </Card>
    </div>
    </div>

    <!-- Pagination -->
    <div v-if="total > perPage" class="flex items-center justify-between mt-4">
      <div class="text-sm text-muted-foreground">
        Showing {{ (page - 1) * perPage + 1 }} to {{ Math.min(page * perPage, total) }} of {{ total }}
      </div>
      <div class="flex gap-2">
        <Button
          variant="outline"
          size="sm"
          :disabled="page <= 1"
          @click="prevPage"
        >
          <ChevronLeft class="h-4 w-4" />
        </Button>
        <Button
          variant="outline"
          size="sm"
          :disabled="page >= totalPages"
          @click="nextPage"
        >
          <ChevronRight class="h-4 w-4" />
        </Button>
      </div>
    </div>

    <!-- Info -->
    <Alert v-if="items.length > 0" class="mt-6">
      Jobs are processed every minute by WP-Cron. Failed jobs are automatically retried with exponential backoff.
      Use "Process Now" to manually trigger batch processing.
    </Alert>
  </div>
</template>
