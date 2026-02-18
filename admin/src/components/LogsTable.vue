<script setup>
import { ref, computed } from 'vue'
import { ChevronLeft, ChevronRight, Eye, Trash2, ArrowRight } from 'lucide-vue-next'
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
})

const emit = defineEmits(['page-change', 'delete'])

const totalPages = computed(() => Math.ceil(props.total / props.perPage))

const selectedLog = ref(null)
const showDetails = ref(false)

const statusVariant = (status) => {
  const variants = {
    success: 'success',
    error: 'destructive',
    retry: 'warning',
    pending: 'secondary',
  }
  return variants[status] || 'default'
}

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
</script>

<template>
  <div>
    <!-- Table -->
    <div class="rounded-md border overflow-x-auto">
      <table class="w-full min-w-[640px]">
        <thead class="bg-muted/50">
          <tr>
            <th class="px-4 py-3 text-left text-sm font-medium">Status</th>
            <th class="px-4 py-3 text-left text-sm font-medium">Trigger</th>
            <th v-if="showWebhook" class="px-4 py-3 text-left text-sm font-medium">Webhook</th>
            <th class="px-4 py-3 text-left text-sm font-medium">HTTP Code</th>
            <th class="px-4 py-3 text-left text-sm font-medium">Duration</th>
            <th class="px-4 py-3 text-left text-sm font-medium">Date</th>
            <th class="px-4 py-3 text-right text-sm font-medium">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y">
          <tr v-if="loading">
            <td :colspan="showWebhook ? 7 : 6" class="px-4 py-8 text-center text-muted-foreground">
              Loading...
            </td>
          </tr>
          <tr v-else-if="logs.length === 0">
            <td :colspan="showWebhook ? 7 : 6" class="px-4 py-8 text-center text-muted-foreground">
              No logs found
            </td>
          </tr>
          <tr v-for="log in logs" :key="log.id" class="hover:bg-muted/50">
            <td class="px-4 py-3">
              <div class="flex items-center gap-1">
                <Badge :variant="statusVariant(log.status)">
                  {{ log.status }}
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
            <td v-if="showWebhook" class="px-4 py-3 text-sm">
              {{ log.webhook_name || `#${log.webhook_id}` }}
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
        </div>
      </div>

      <template #footer>
        <Button variant="outline" @click="closeDetails">Close</Button>
      </template>
    </Dialog>
  </div>
</template>
