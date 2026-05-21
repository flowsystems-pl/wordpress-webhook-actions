<script setup>
import { ref, onMounted, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { ArrowLeft } from 'lucide-vue-next'
import { Button, Card, Alert, Dialog } from '@/components/ui'
import LogsTable from '@/components/LogsTable.vue'
import api from '@/lib/api'

const router = useRouter()
const route = useRoute()

const webhook = ref(null)
const logs = ref([])
const total = ref(0)
const page = ref(1)
const perPage = ref(20)
const loading = ref(true)
const error = ref(null)
const stats = ref(null)

const showReplaySuccess = ref(false)
const replayedJobId = ref(null)
const replayedLogId = ref(null)
const logsTable = ref(null)

const webhookId = route.params.id

const loadWebhook = async () => {
  try {
    webhook.value = await api.webhooks.get(webhookId)
  } catch (e) {
    console.error('Failed to load webhook:', e)
  }
}

const loadLogs = async () => {
  loading.value = true
  error.value = null

  try {
    const result = await api.webhooks.logs(webhookId, {
      page: page.value,
      per_page: perPage.value,
    })
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
    stats.value = await api.logs.stats({ webhook_id: webhookId, days: 7 })
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
    if (e.code === 'rest_job_completed') {
      showReplaySuccess.value = false
      await loadLogs()
      const log = logs.value.find(l => l.id === replayedLogId.value)
      if (log) logsTable.value?.openDetails(log)
    } else {
      error.value = e.message
    }
  }
}

watch(page, loadLogs)

onMounted(() => {
  loadWebhook()
  loadLogs()
  loadStats()
})
</script>

<template>
  <div>
    <!-- Header -->
    <div class="mb-6">
      <Button variant="ghost" size="sm" class="mb-2" @click="router.push('/webhooks')">
        <ArrowLeft class="mr-2 h-4 w-4" />
        Back to webhooks
      </Button>
      <h2 class="text-xl font-semibold">
        Logs: {{ webhook?.name || `Webhook #${webhookId}` }}
      </h2>
      <p v-if="webhook" class="text-muted-foreground font-mono text-sm truncate">
        {{ webhook.endpoint_url }}
      </p>
    </div>

    <!-- Stats -->
    <div v-if="stats" class="grid grid-cols-4 gap-4 mb-6">
      <Card class="p-4">
        <div class="text-2xl font-bold">{{ stats.total }}</div>
        <div class="text-sm text-muted-foreground">Total (7 days)</div>
      </Card>
      <Card class="p-4">
        <div class="text-2xl font-bold text-green-600">{{ stats.success }}</div>
        <div class="text-sm text-muted-foreground">Success</div>
      </Card>
      <Card class="p-4">
        <div class="text-2xl font-bold text-red-600">{{ stats.error + (stats.permanently_failed ?? 0) }}</div>
        <div class="text-sm text-muted-foreground">Errors</div>
      </Card>
      <Card class="p-4">
        <div class="text-2xl font-bold text-yellow-600">{{ stats.retry }}</div>
        <div class="text-sm text-muted-foreground">Retries</div>
      </Card>
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
          <Button @click="executeReplayedJob">Execute Now</Button>
          <Button variant="outline" @click="() => { showReplaySuccess = false; router.push({ name: 'Queue' }) }">Go to Queue</Button>
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
      :show-webhook="false"
      @page-change="handlePageChange"
      @delete="handleDelete"
      @retry="handleRetry"
      @replay="handleReplay"
    />
  </div>
</template>
