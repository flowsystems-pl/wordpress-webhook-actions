<script setup>
import { ref, computed } from 'vue'
import { ChevronLeft, ChevronRight, ChevronDown, Eye, Trash2, ArrowRight, RotateCcw, Play, CheckCircle2, XCircle, Loader2, Copy, Check } from 'lucide-vue-next'
import { Badge, Button, Checkbox, Dialog } from '@/components/ui'
import { formatUtcDate } from '@/lib/dates'
import { useCopyToClipboard } from '@/composables/useCopyToClipboard'

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

const emit = defineEmits(['page-change', 'delete', 'retry', 'replay', 'update:selectedIds'])

const totalPages = computed(() => Math.ceil(props.total / props.perPage))

const selectedLog = ref(null)
const showDetails = ref(false)

const pendingDeleteLog = ref(null)

const statusVariant = (status) => {
  const variants = {
    success:           'success',
    error:             'destructive',
    retry:             'warning',
    pending:           'secondary',
    skipped:           'warning',
    permanently_failed: 'destructive',
    test:              'outline',
  }
  return variants[status] || 'default'
}

const isRetryable  = (status) => status === 'error' || status === 'permanently_failed'
const isReplayable = (status) => status === 'success' || status === 'test' || status === 'skipped'

const formatDate = formatUtcDate

const lastAttempt = computed(() => {
  const history = selectedLog.value?.attempt_history
  return history?.length ? history[history.length - 1] : null
})

const lastAttemptError = computed(() => {
  return lastAttempt.value?.error_message || null
})

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
  openAttempts.value.clear()
}

const closeDetails = () => {
  showDetails.value = false
  selectedLog.value = null
  openAttempts.value.clear()
}

const openAttempts = ref(new Set())
const toggleAttempt = (index) => {
  if (openAttempts.value.has(index)) {
    openAttempts.value.delete(index)
  } else {
    openAttempts.value.add(index)
  }
  openAttempts.value = new Set(openAttempts.value)
}

// Collapse state — persisted in localStorage so preferences survive across log opens
const readBool = (key, def) => {
  const v = localStorage.getItem(key)
  return v !== null ? v === 'true' : def
}
const writeBool = (key, val) => localStorage.setItem(key, String(val))

const payloadCollapsed       = ref(readBool('fswa_log_detail_payload_collapsed', true))
const originalPayloadCollapsed = ref(readBool('fswa_log_detail_original_collapsed', true))

const togglePayloadCollapsed = () => {
  payloadCollapsed.value = !payloadCollapsed.value
  writeBool('fswa_log_detail_payload_collapsed', payloadCollapsed.value)
}
const toggleOriginalCollapsed = () => {
  originalPayloadCollapsed.value = !originalPayloadCollapsed.value
  writeBool('fswa_log_detail_original_collapsed', originalPayloadCollapsed.value)
}

const isNoBodyMethod = computed(() => {
  const m = selectedLog.value?.http_method?.toUpperCase()
  return m === 'GET' || m === 'DELETE'
})

const queryParams = computed(() => {
  const url = selectedLog.value?.request_url
  if (!url) return []
  try {
    const parsed = new URL(url)
    const params = []
    parsed.searchParams.forEach((value, key) => params.push({ key, value }))
    return params
  } catch {
    return []
  }
})

defineExpose({ openDetails })

const handleDelete = (log) => {
  pendingDeleteLog.value = log
}

const confirmDelete = () => {
  if (pendingDeleteLog.value) {
    emit('delete', pendingDeleteLog.value.id)
    pendingDeleteLog.value = null
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

const { copiedKey, copy } = useCopyToClipboard()
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
              <Checkbox
                :model-value="allOnPageSelected"
                @update:model-value="toggleSelectAll"
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
              <Checkbox
                :model-value="isSelected(log.id)"
                @update:model-value="() => toggleSelect(log.id)"
              />
            </td>
            <td class="px-4 py-3">
              <div class="flex items-center gap-1 flex-wrap">
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
              <div class="flex items-center gap-1.5 flex-wrap">
                <span>{{ log.webhook_name || `#${log.webhook_id}` }}</span>
                <Badge v-if="log.http_method" variant="outline" class="text-xs font-mono">{{ log.http_method }}</Badge>
              </div>
              <div v-if="log.target_url" class="text-xs text-muted-foreground font-mono truncate max-w-[200px]" :title="log.target_url">{{ log.target_url }}</div>
              <div v-if="log.webhook_uuid" class="text-xs text-muted-foreground font-mono mt-0.5">X-Webhook-Id: {{ log.webhook_uuid }}</div>
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
                <Button
                  v-if="isReplayable(log.status)"
                  size="icon"
                  variant="ghost"
                  class="text-green-600 hover:text-green-700"
                  title="Replay"
                  @click="emit('replay', log)"
                >
                  <Play class="h-4 w-4" />
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

    <!-- Delete Confirm Dialog -->
    <Dialog
      :open="!!pendingDeleteLog"
      title="Delete log?"
      description="This action cannot be undone."
      @close="pendingDeleteLog = null"
    >
      <template #footer>
        <div class="flex gap-2">
          <Button variant="destructive" @click="confirmDelete">Delete</Button>
          <Button variant="outline" @click="pendingDeleteLog = null">Cancel</Button>
        </div>
      </template>
    </Dialog>

    <!-- Details Dialog -->
    <Dialog
      :open="showDetails"
      title="Log Details"
      @close="closeDetails"
    >
      <div v-if="selectedLog" class="space-y-4 max-h-[60vh] overflow-y-auto">
        <!-- Status + Method -->
        <div class="flex gap-6">
          <div>
            <div class="text-sm font-medium mb-1">Status</div>
            <div class="flex items-center gap-2 flex-wrap">
              <Badge :variant="statusVariant(selectedLog.status)">
                {{ selectedLog.status }}
              </Badge>
              <Badge v-if="selectedLog.mapping_applied" variant="outline" class="text-xs">
                <ArrowRight class="h-3 w-3 mr-0.5" />
                Mapping Applied
              </Badge>
            </div>
          </div>
          <div v-if="selectedLog.http_method">
            <div class="text-sm font-medium mb-1">Method</div>
            <Badge variant="outline" class="font-mono">{{ selectedLog.http_method }}</Badge>
          </div>
        </div>

        <!-- Skipped reason -->
        <div
          v-if="selectedLog.status === 'skipped' && selectedLog.error_message"
          class="flex items-start gap-2 text-sm p-3 bg-amber-50 text-amber-800 dark:bg-amber-950 dark:text-amber-300 rounded-md border border-amber-200 dark:border-amber-800"
        >
          <span class="font-medium shrink-0">Skipped:</span>
          <span class="font-mono break-all">{{ selectedLog.error_message }}</span>
        </div>

        <!-- Next Attempt -->
        <div v-if="selectedLog.status === 'retry' && selectedLog.next_attempt_at" class="flex items-center gap-2 text-sm p-3 bg-yellow-500/10 text-yellow-700 dark:text-yellow-400 rounded-md">
          <Loader2 class="h-4 w-4 shrink-0 animate-spin" />
          Next attempt scheduled for {{ formatDate(selectedLog.next_attempt_at) }}
        </div>

        <!-- Error Message -->
        <div v-if="lastAttemptError">
          <div class="text-sm font-medium mb-1">
            Error Message
            <span v-if="selectedLog.attempt_history?.length" class="text-muted-foreground font-normal">(last attempt)</span>
          </div>
          <div class="text-sm p-3 bg-destructive/10 text-destructive rounded-md font-mono break-all">
            {{ lastAttemptError }}
          </div>
        </div>

        <!-- Event Identity -->
        <div v-if="selectedLog.event_uuid || selectedLog.webhook_uuid" class="grid grid-cols-1 gap-2">
          <div v-if="selectedLog.event_uuid">
            <div class="flex items-center justify-between mb-1">
              <div class="text-sm font-medium">Event UUID</div>
              <button @click="copy(selectedLog.event_uuid, 'detail-uuid')" class="shrink-0 rounded p-1 hover:bg-background transition-colors" title="Copy Event UUID">
                <Check v-if="copiedKey === 'detail-uuid'" class="h-3.5 w-3.5 text-green-500" />
                <Copy v-else class="h-3.5 w-3.5 text-muted-foreground" />
              </button>
            </div>
            <div class="text-xs font-mono p-2 bg-muted rounded-md break-all">{{ selectedLog.event_uuid }}</div>
          </div>
          <div v-if="selectedLog.webhook_uuid">
            <div class="flex items-center justify-between mb-1">
              <div class="text-sm font-medium">X-Webhook-Id</div>
              <button @click="copy(selectedLog.webhook_uuid, 'detail-wh-id')" class="shrink-0 rounded p-1 hover:bg-background transition-colors" title="Copy X-Webhook-Id">
                <Check v-if="copiedKey === 'detail-wh-id'" class="h-3.5 w-3.5 text-green-500" />
                <Copy v-else class="h-3.5 w-3.5 text-muted-foreground" />
              </button>
            </div>
            <div class="text-xs font-mono p-2 bg-muted rounded-md break-all">{{ selectedLog.webhook_uuid }}</div>
          </div>
        </div>

        <!-- Attempt History -->
        <div v-if="selectedLog.attempt_history && selectedLog.attempt_history.length > 0">
          <div class="text-sm font-medium mb-2">
            Attempt History
            <span class="text-muted-foreground font-normal">({{ selectedLog.attempt_history.length }})</span>
          </div>
          <div class="border rounded-md divide-y overflow-hidden">
            <div
              v-for="(attempt, index) in selectedLog.attempt_history"
              :key="index"
            >
              <!-- Trigger -->
              <button
                type="button"
                class="w-full flex items-center gap-3 px-3 py-2 text-xs text-left hover:bg-muted/50 transition-colors"
                @click="toggleAttempt(index)"
              >
                <CheckCircle2 v-if="attempt.status === 'success'" class="h-3.5 w-3.5 text-green-500 shrink-0" />
                <XCircle v-else class="h-3.5 w-3.5 text-red-500 shrink-0" />
                <span class="font-medium">Attempt #{{ attempt.attempt + 1 }}</span>
                <span v-if="attempt.http_code" class="text-muted-foreground">HTTP {{ attempt.http_code }}</span>
                <span v-if="attempt.duration_ms != null" class="text-muted-foreground">{{ attempt.duration_ms }}ms</span>
                <span class="text-muted-foreground ml-auto shrink-0">{{ formatDate(attempt.attempted_at) }}</span>
                <ChevronDown
                  class="h-3.5 w-3.5 text-muted-foreground shrink-0 transition-transform"
                  :class="{ 'rotate-180': openAttempts.has(index) }"
                />
              </button>
              <!-- Content -->
              <div v-if="openAttempts.has(index)" class="px-3 py-2 bg-muted/30 text-xs space-y-2 border-t">
                <div class="grid grid-cols-2 gap-x-4 gap-y-1">
                  <div>
                    <span class="text-muted-foreground">Status</span>
                    <div class="font-medium capitalize">{{ attempt.status }}</div>
                  </div>
                  <div>
                    <span class="text-muted-foreground">HTTP Code</span>
                    <div class="font-medium">{{ attempt.http_code ?? '—' }}</div>
                  </div>
                  <div>
                    <span class="text-muted-foreground">Duration</span>
                    <div class="font-medium">{{ attempt.duration_ms != null ? `${attempt.duration_ms}ms` : '—' }}</div>
                  </div>
                  <div>
                    <span class="text-muted-foreground">Will Retry</span>
                    <div class="font-medium">{{ attempt.should_retry ? 'Yes' : 'No' }}</div>
                  </div>
                  <div class="col-span-2">
                    <span class="text-muted-foreground">Attempted At</span>
                    <div class="font-medium font-mono">{{ formatDate(attempt.attempted_at) }}</div>
                  </div>
                </div>
                <div v-if="attempt.error_message">
                  <span class="text-muted-foreground">Error</span>
                  <div class="mt-0.5 text-destructive font-mono break-all">{{ attempt.error_message }}</div>
                </div>
                <div v-if="attempt.response_body != null">
                  <div class="flex items-center justify-between">
                    <span class="text-muted-foreground">Response Body</span>
                    <button @click="copy(formatJson(attempt.response_body), `attempt-${index}-resp`)" class="shrink-0 rounded p-0.5 hover:bg-background transition-colors" title="Copy response body">
                      <Check v-if="copiedKey === `attempt-${index}-resp`" class="h-3 w-3 text-green-500" />
                      <Copy v-else class="h-3 w-3 text-muted-foreground" />
                    </button>
                  </div>
                  <pre class="mt-0.5 p-2 bg-muted rounded-md overflow-x-auto font-mono break-all whitespace-pre-wrap">{{ formatJson(attempt.response_body) }}</pre>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Request Headers -->
        <div v-if="selectedLog.request_headers && Object.keys(selectedLog.request_headers).length">
          <div class="flex items-center justify-between mb-1">
            <div class="text-sm font-medium">Request Headers</div>
            <button @click="copy(formatJson(selectedLog.request_headers), 'detail-headers')" class="shrink-0 rounded p-1 hover:bg-background transition-colors" title="Copy headers">
              <Check v-if="copiedKey === 'detail-headers'" class="h-3.5 w-3.5 text-green-500" />
              <Copy v-else class="h-3.5 w-3.5 text-muted-foreground" />
            </button>
          </div>
          <div class="rounded-md border divide-y text-xs">
            <div
              v-for="(value, key) in selectedLog.request_headers"
              :key="key"
              class="flex px-3 py-1.5 gap-3"
            >
              <span class="font-mono text-muted-foreground shrink-0 min-w-[160px]">{{ key }}</span>
              <span class="font-mono break-all">{{ value }}</span>
            </div>
          </div>
        </div>

        <!-- Query Parameters -->
        <div v-if="queryParams.length">
          <div class="flex items-center justify-between mb-1">
            <div class="text-sm font-medium">Query Parameters</div>
          </div>
          <div class="rounded-md border divide-y text-xs">
            <div
              v-for="param in queryParams"
              :key="param.key"
              class="flex px-3 py-1.5 gap-3"
            >
              <span class="font-mono text-muted-foreground shrink-0 min-w-[160px]">{{ param.key }}</span>
              <span class="font-mono break-all">{{ param.value }}</span>
            </div>
          </div>
        </div>

        <!-- Request Payload (Transformed if mapping applied) -->
        <div>
          <div class="flex items-center justify-between mb-1">
            <button
              type="button"
              class="flex items-center gap-1.5 text-sm font-medium hover:text-foreground/80 transition-colors"
              @click="togglePayloadCollapsed"
            >
              <ChevronDown
                class="h-3.5 w-3.5 transition-transform"
                :class="{ 'rotate-180': !payloadCollapsed }"
              />
              {{ selectedLog.mapping_applied ? 'Transformed Payload (Sent)' : 'Request Payload' }}
            </button>
            <button @click="copy(formatJson(selectedLog.request_payload), 'detail-request')" class="shrink-0 rounded p-1 hover:bg-background transition-colors" title="Copy payload">
              <Check v-if="copiedKey === 'detail-request'" class="h-3.5 w-3.5 text-green-500" />
              <Copy v-else class="h-3.5 w-3.5 text-muted-foreground" />
            </button>
          </div>
          <template v-if="!payloadCollapsed">
            <div v-if="isNoBodyMethod" class="text-xs text-muted-foreground p-3 bg-muted/50 rounded-md border border-dashed mb-2">
              Not sent as request body for GET / DELETE. Without URL parameters configured, the full payload is appended as <code class="font-mono">?payload=&lt;json&gt;</code>.
            </div>
            <pre class="text-xs p-3 bg-muted rounded-md overflow-x-auto">{{ formatJson(selectedLog.request_payload) }}</pre>
          </template>
        </div>

        <!-- Original Payload (if mapping was applied) -->
        <div v-if="selectedLog.mapping_applied && selectedLog.original_payload">
          <div class="flex items-center justify-between mb-1">
            <button
              type="button"
              class="flex items-center gap-1.5 text-sm font-medium hover:text-foreground/80 transition-colors"
              @click="toggleOriginalCollapsed"
            >
              <ChevronDown
                class="h-3.5 w-3.5 transition-transform"
                :class="{ 'rotate-180': !originalPayloadCollapsed }"
              />
              Original Payload
            </button>
            <button @click="copy(formatJson(selectedLog.original_payload), 'detail-original')" class="shrink-0 rounded p-1 hover:bg-background transition-colors" title="Copy original payload">
              <Check v-if="copiedKey === 'detail-original'" class="h-3.5 w-3.5 text-green-500" />
              <Copy v-else class="h-3.5 w-3.5 text-muted-foreground" />
            </button>
          </div>
          <pre v-if="!originalPayloadCollapsed" class="text-xs p-3 bg-muted rounded-md overflow-x-auto">{{ formatJson(selectedLog.original_payload) }}</pre>
        </div>

        <!-- Response Body -->
        <div v-if="lastAttempt?.response_body ?? selectedLog.response_body">
          <div class="flex items-center justify-between mb-1">
            <div class="text-sm font-medium">
              Response Body
              <span v-if="lastAttempt?.response_body" class="text-muted-foreground font-normal">(last attempt)</span>
            </div>
            <button @click="copy(formatJson(lastAttempt?.response_body ?? selectedLog.response_body), 'detail-response')" class="shrink-0 rounded p-1 hover:bg-background transition-colors" title="Copy response body">
              <Check v-if="copiedKey === 'detail-response'" class="h-3.5 w-3.5 text-green-500" />
              <Copy v-else class="h-3.5 w-3.5 text-muted-foreground" />
            </button>
          </div>
          <pre class="text-xs p-3 bg-muted rounded-md overflow-x-auto">{{ formatJson(lastAttempt?.response_body ?? selectedLog.response_body) }}</pre>
        </div>

        <!-- Meta -->
        <div class="grid grid-cols-2 gap-4 text-sm">
          <div>
            <div class="font-medium">HTTP Code</div>
            <div class="text-muted-foreground">
              {{ (lastAttempt?.http_code ?? selectedLog.http_code) || 'N/A' }}
              <span v-if="lastAttempt?.http_code" class="text-xs text-muted-foreground/60">(last attempt)</span>
            </div>
          </div>
          <div>
            <div class="font-medium">Duration</div>
            <div class="text-muted-foreground">
              {{ (lastAttempt?.duration_ms ?? selectedLog.duration_ms) != null ? `${lastAttempt?.duration_ms ?? selectedLog.duration_ms}ms` : 'N/A' }}
              <span v-if="lastAttempt?.duration_ms != null" class="text-xs text-muted-foreground/60">(last attempt)</span>
            </div>
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
          <Button
            v-if="isReplayable(selectedLog?.status)"
            variant="outline"
            class="text-green-600 border-green-600 hover:bg-green-50"
            @click="() => { emit('replay', selectedLog); closeDetails() }"
          >
            <Play class="h-4 w-4 mr-1.5" /> Replay
          </Button>
          <Button variant="outline" @click="closeDetails">Close</Button>
        </div>
      </template>
    </Dialog>
  </div>
</template>
