<script setup>
import { ref, onMounted, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { ArrowLeft } from 'lucide-vue-next'
import { Button, Card, Alert } from '@/components/ui'
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

    <!-- Table -->
    <LogsTable
      :logs="logs"
      :total="total"
      :page="page"
      :per-page="perPage"
      :loading="loading"
      :show-webhook="false"
      @page-change="handlePageChange"
      @delete="handleDelete"
    />
  </div>
</template>
