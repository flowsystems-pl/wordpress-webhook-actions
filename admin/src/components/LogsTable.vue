<script setup>
import { ref, computed } from 'vue'
import { ChevronLeft, ChevronRight, Eye, Trash2, ArrowRight, RotateCcw, CheckCircle2, XCircle, Loader2 } from 'lucide-vue-next'
import { Badge, Button, Dialog } from '@/components/ui'

const props = defineProps({
  logs: {
    type: Array,
    default: () => [],
  },
  total: {
    type: Number,
    default: 0,
  },
  page: {
    type: Number,
    default: 1,
  },
  perPage: {
    type: Number,
    default: 20,
  },
  loading: Boolean,
  showWebhook: {
    type: Boolean,
    default: true,
  },
  selectedIds: {
    type: Array,
    default: () => [],
  },
})

const emit = defineEmits(['page-change', 'delete', 'retry', 'update:selectedIds'])

const totalPages = computed(() => Math.ceil(props.total / props.perPage))

const selectedLog = ref(null)
const showDetails = ref(false)

const statusVariant = (status) => {
  const variants = {
    success: 'success',
    error: 'destructive',
    retry: 'warning',
    pending: 'secondary',
    permanently_failed: 'destructive',
  }
  return variants[status] || 'default'
}

const isRetryable = (status) => status === 'error' || status === 'permanently_failed'

const formatDate = (date) => {
  return new Date(date).toLocaleString()
}

const formatJson = (data) => {
  if (!data) return 'null'
  if (typeof data === 'string') {
    try {
      return JSON.stringify(JSON.parse(data), null, 2)
    } catch {
      return data
    }
  }
  return JSON.stringify(data, null, 2)
}

const openDetails = (log) => {
  selectedLog.value = log
  showDetails.value = true
}

const closeDetails = () => {
  showDetails.value = false
  selectedLog.value = null
}

const handleDelete = (log) => {
  if (confirm('Are you sure you want to delete this log?')) {
    emit('delete', log.id)
  }
}

const handleRetry = (log) => {
  emit('retry', log.id)
}

const prevPage = () => {
  if (props.page > 1) {
    emit('page-change', props.page - 1)
  }
}

const nextPage = () => {
  if (props.page < totalPages.value) {
    emit('page-change', props.page + 1)
  }
}

const isSelected = (id) => props.selectedIds.includes(id)

const toggleSelect = (id) => {
  const current = [...props.selectedIds]
  const idx = current.indexOf(id)
  if (idx === -1) {
    current.push(id)
  } else {
    current.splice(idx, 1)
  }
  emit('update:selectedIds', current)
}

const toggleSelectAll = () => {
  const allIds = props.logs.map((l) => l.id)
  const allSelected = allIds.every((id) => props.selectedIds.includes(id))
  if (allSelected) {
    const remaining = props.selectedIds.filter((id) => !allIds.includes(id))
    emit('update:selectedIds', remaining)
  } else {
    const merged = [...new Set([...props.selectedIds, ...allIds])]
    emit('update:selectedIds', merged)
  }
}

const allOnPageSelected = computed(() => {
  return props.logs.length > 0 && props.logs.every((l) => props.selectedIds.includes(l.id))
})
</script>

<template>
  <div>
    <!-- Table -->
    <div class="relative">
      <div v-if="loading && logs.length > 0" class="absolute inset-0 z-10 flex items-center justify-center rounded-md bg-background/60 backdrop-blur-[1px]">
        <Loader2 class="h-6 w-6 animate-spin text-muted-foreground" />
      </div>
      <div class="rounded-md border overflow-x-auto">
      <table class="w-full min-w-[720px]">
        <thead class="bg-muted/50">
          <tr>
            <th class="px-3 py-3 w-8">
              <input
                type="checkbox"
                :checked="allOnPageSelected"
                @change="toggleSelectAll"
                class="rounded"
              />
            </th>
            <th class="px-4 py-3 text-left text-sm font-medium">Status</th>
            <th class="px-4 py-3 text-left text-sm font-medium">Trigger</th>
            <th class="px-4 py-3 text-left text-sm font-medium">Event UUID</th>
            <th v-if="showWebhook" class="px-4 py-3 text-left text-sm font-medium">Webhook</th>
            <th class="px-4 py-3 text-left text-sm font-medium">HTTP Code</th>
            <th class="px-4 py-3 text-left text-sm font-medium">Duration</th>
            <th class="px-4 py-3 text-left text-sm font-medium">Date</th>
            <th class="px-4 py-3 text-right text-sm font-medium">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y">
          <tr v-if="loading && logs.length === 0">
            <td :colspan="showWebhook ? 9 : 8" class="px-4 py-8 text-center text-muted-foreground">
              Loading...
            </td>
          </tr>
          <tr v-else-if="logs.length === 0">
            <td :colspan="showWebhook ? 9 : 8" class="px-4 py-8 text-center text-muted-foreground">
              No logs found
            </td>
          </tr>
          <tr v-for="log in logs" :key="log.id" :class="['hover:bg-muted/50', isSelected(log.id) ? 'bg-muted/30' : '']">
            <td class="px-3 py-3">
              <input
                type="checkbox"
                :checked="isSelected(log.id)"
                @change="toggleSelect(log.id)"
                class="rounded"
              />
            </td>
            <td class="px-4 py-3">
              <div class="flex items-center gap-1">
                <Badge :variant="statusVariant(log.status)">
                  {{ log.status === 'permanently_failed' ? 'perm. failed' : log.status }}
                </Badge>
                <Badge v-if="log.mapping_applied" variant="outline" class="text-xs">
                  <ArrowRight class="h-3 w-3 mr-0.5" />
                  Mapped
                </Badge>
              </div>
            </td>
            <td class="px-4 py-3 text-sm font-mono">
              {{ log.trigger_name }}
            </td>
            <td class="px-4 py-3">
              <Badge
                v-if="log.event_uuid"
                variant="secondary"
                class="font-mono rounded text-xs tracking-tight"
              >{{ log.event_uuid }}</Badge>
              <span v-else class="text-muted-foreground text-sm">-</span>
            </td>
            <td v-if="showWebhook" class="px-4 py-3 text-sm">
              <div>{{ log.webhook_name || `#${log.webhook_id}` }}</div>
              <div v-if="log.target_url" class="text-xs text-muted-foreground font-mono truncate max-w-[200px]" :title="log.target_url">{{ log.target_url }}</div>
            </td>
            <td class="px-4 py-3 text-sm">
              {{ log.http_code || '-' }}
            </td>
            <td class="px-4 py-3 text-sm">
              {{ log.duration_ms ? `${log.duration_ms}ms` : '-' }}
            </td>
            <td class="px-4 py-3 text-sm text-muted-foreground">
              {{ formatDate(log.created_at) }}
            </td>
            <td class="px-4 py-3 text-right">
              <div class="flex justify-end gap-1">
                <Button
                  v-if="isRetryable(log.status)"
                  size="icon"
                  variant="ghost"
                  title="Retry"
                  @click="handleRetry(log)"
                >
                  <RotateCcw class="h-4 w-4" />
                </Button>
                <Button size="icon" variant="ghost" @click="openDetails(log)">
                  <Eye class="h-4 w-4" />
                </Button>
                <Button size="icon" variant="ghost" @click="handleDelete(log)">
                  <Trash2 class="h-4 w-4" />
                </Button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
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

    <!-- Details Dialog -->
    <Dialog
      :open="showDetails"
      title="Log Details"
      @close="closeDetails"
    >
      <div v-if="selectedLog" class="space-y-4 max-h-[60vh] overflow-y-auto">
        <!-- Status -->
        <div>
          <div class="text-sm font-medium mb-1">Status</div>
          <div class="flex items-center gap-2">
            <Badge :variant="statusVariant(selectedLog.status)">
              {{ selectedLog.status }}
            </Badge>
            <Badge v-if="selectedLog.mapping_applied" variant="outline" class="text-xs">
              <ArrowRight class="h-3 w-3 mr-0.5" />
              Mapping Applied
            </Badge>
          </div>
        </div>

        <!-- Error Message -->
        <div v-if="selectedLog.error_message">
          <div class="text-sm font-medium mb-1">Error Message</div>
          <div class="text-sm p-3 bg-destructive/10 text-destructive rounded-md">
            {{ selectedLog.error_message }}
          </div>
        </div>

        <!-- Event Identity -->
        <div v-if="selectedLog.event_uuid" class="grid grid-cols-1 gap-2">
          <div>
            <div class="text-sm font-medium mb-1">Event UUID</div>
            <div class="text-xs font-mono p-2 bg-muted rounded-md break-all">{{ selectedLog.event_uuid }}</div>
          </div>
        </div>

        <!-- Attempt History -->
        <div v-if="selectedLog.attempt_history && selectedLog.attempt_history.length > 0">
          <div class="text-sm font-medium mb-2">Attempt History</div>
          <div class="space-y-2">
            <div
              v-for="(attempt, index) in selectedLog.attempt_history"
              :key="index"
              class="flex items-start gap-3 text-xs p-2 rounded-md border"
            >
              <div class="shrink-0 mt-0.5">
                <CheckCircle2 v-if="attempt.status === 'success'" class="h-3.5 w-3.5 text-green-500" />
                <XCircle v-else class="h-3.5 w-3.5 text-red-500" />
              </div>
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                  <span class="font-medium">#{{ attempt.attempt + 1 }}</span>
                  <span v-if="attempt.http_code" class="text-muted-foreground">HTTP {{ attempt.http_code }}</span>
                  <span v-if="attempt.duration_ms" class="text-muted-foreground">{{ attempt.duration_ms }}ms</span>
                  <span class="text-muted-foreground">{{ attempt.attempted_at }}</span>
                </div>
                <div v-if="attempt.error_message" class="text-destructive mt-0.5 truncate">
                  {{ attempt.error_message }}
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Original Payload (if mapping was applied) -->
        <div v-if="selectedLog.mapping_applied && selectedLog.original_payload">
          <div class="text-sm font-medium mb-1">Original Payload</div>
          <pre class="text-xs p-3 bg-muted rounded-md overflow-x-auto">{{ formatJson(selectedLog.original_payload) }}</pre>
        </div>

        <!-- Request Payload (Transformed if mapping applied) -->
        <div>
          <div class="text-sm font-medium mb-1">
            {{ selectedLog.mapping_applied ? 'Transformed Payload (Sent)' : 'Request Payload' }}
          </div>
          <pre class="text-xs p-3 bg-muted rounded-md overflow-x-auto">{{ formatJson(selectedLog.request_payload) }}</pre>
        </div>

        <!-- Response Body -->
        <div v-if="selectedLog.response_body">
          <div class="text-sm font-medium mb-1">Response Body</div>
          <pre class="text-xs p-3 bg-muted rounded-md overflow-x-auto">{{ formatJson(selectedLog.response_body) }}</pre>
        </div>

        <!-- Meta -->
        <div class="grid grid-cols-2 gap-4 text-sm">
          <div>
            <div class="font-medium">HTTP Code</div>
            <div class="text-muted-foreground">{{ selectedLog.http_code || 'N/A' }}</div>
          </div>
          <div>
            <div class="font-medium">Duration</div>
            <div class="text-muted-foreground">{{ selectedLog.duration_ms ? `${selectedLog.duration_ms}ms` : 'N/A' }}</div>
          </div>
          <div>
            <div class="font-medium">Trigger</div>
            <div class="text-muted-foreground break-all">{{ selectedLog.trigger_name }}</div>
          </div>
          <div>
            <div class="font-medium">Created</div>
            <div class="text-muted-foreground break-all">{{ formatDate(selectedLog.created_at) }}</div>
          </div>
          <div v-if="selectedLog.target_url">
            <div class="font-medium">Target URL</div>
            <div class="text-muted-foreground break-all text-xs">{{ selectedLog.target_url }}</div>
          </div>
        </div>
      </div>

      <template #footer>
        <div class="flex gap-2">
          <Button
            v-if="isRetryable(selectedLog?.status)"
            variant="outline"
            @click="() => { handleRetry(selectedLog); closeDetails() }"
          >
            <RotateCcw class="h-4 w-4 mr-1.5" />
            Retry
          </Button>
          <Button variant="outline" @click="closeDetails">Close</Button>
        </div>
      </template>
    </Dialog>
  </div>
</template>
