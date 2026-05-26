<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { Card, Select, SelectTrigger, SelectValue, SelectContent, SelectItem, Alert, Button, DateTimePicker, Dialog } from '@/components/ui'
import { pickerLocalToUtcDb } from '@/lib/dates'
import { Loader2, ChevronDown, ChevronRight, Trash2 } from 'lucide-vue-next'
import api from '@/lib/api'

const items = ref([])
const total = ref(0)
const page = ref(1)
const perPage = ref(20)
const loading = ref(false)
const error = ref(null)
const expandedRows = ref(new Set())

const actionPrefixFilter = ref('')
const dateFromFilter = ref('')
const dateToFilter = ref('')

const showDeleteDialog = ref(false)
const deleteDays = ref('90')
const deleting = ref(false)
const deleteSuccess = ref(null)

const actionPrefixOptions = [
  { value: 'all', label: 'All actions' },
  { value: 'webhook', label: 'Webhooks' },
  { value: 'snippet', label: 'Snippets' },
  { value: 'chain', label: 'Chains' },
  { value: 'token', label: 'API Tokens' },
  { value: 'settings', label: 'Settings' },
  { value: 'log', label: 'Logs' },
  { value: 'queue', label: 'Queue' },
  { value: 'schema', label: 'Schemas' },
  { value: 'cron', label: 'Cron' },
]

const actionPrefixSelect = computed({
  get: () => actionPrefixFilter.value || 'all',
  set: (val) => { actionPrefixFilter.value = val === 'all' ? '' : val },
})

const totalPages = computed(() => Math.ceil(total.value / perPage.value) || 1)

const loadItems = async () => {
  loading.value = true
  error.value = null

  try {
    const params = {
      page: page.value,
      per_page: perPage.value,
    }

    if (actionPrefixFilter.value) {
      params.action_prefix = actionPrefixFilter.value
    }

    if (dateFromFilter.value) {
      params.date_from = pickerLocalToUtcDb(dateFromFilter.value)
    }

    if (dateToFilter.value) {
      params.date_to = pickerLocalToUtcDb(dateToFilter.value)
    }

    const result = await api.activity.list(params)
    items.value = result.items
    total.value = result.total
  } catch (e) {
    error.value = e.message
    console.error('Failed to load activity log:', e)
  } finally {
    loading.value = false
  }
}

const resetPage = () => {
  if (page.value === 1) loadItems()
  else page.value = 1
}

const toggleRow = (id) => {
  if (expandedRows.value.has(id)) {
    expandedRows.value.delete(id)
  } else {
    expandedRows.value.add(id)
  }
}

const handleDeleteOld = async () => {
  deleting.value = true
  error.value = null
  try {
    await api.activity.deleteOld(parseInt(deleteDays.value, 10))
    showDeleteDialog.value = false
    deleteSuccess.value = `Activity entries older than ${deleteDays.value} days have been deleted.`
    setTimeout(() => { deleteSuccess.value = null }, 4000)
    await loadItems()
  } catch (e) {
    error.value = e.message
  } finally {
    deleting.value = false
  }
}

const formatAction = (action) => {
  return action.replace(/\./g, ' › ').replace(/_/g, ' ')
}

const formatObject = (item) => {
  if (!item.object_type) return '—'
  const parts = [item.object_type]
  if (item.object_name) parts.push(`"${item.object_name}"`)
  else if (item.object_id) parts.push(`#${item.object_id}`)
  return parts.join(' ')
}

const formatActor = (item) => {
  if (item.token_hint) return `Token: ${item.token_hint}`
  if (item.user_id) return `User #${item.user_id}`
  return 'System'
}

const formatDate = (dateStr) => {
  if (!dateStr) return '—'
  return new Date(dateStr + 'Z').toLocaleString()
}

watch(page, loadItems)
watch(actionPrefixFilter, resetPage)
watch(dateFromFilter, resetPage)
watch(dateToFilter, resetPage)

onMounted(loadItems)
</script>

<template>
  <div>
    <!-- Header -->
    <div class="flex items-start justify-between mb-6">
      <div>
        <h2 class="text-xl font-semibold">Activity</h2>
        <p class="text-muted-foreground text-sm">Admin and API action history</p>
      </div>
      <Button variant="outline" size="sm" @click="showDeleteDialog = true">
        <Trash2 class="mr-2 h-4 w-4" />
        Delete old
      </Button>
    </div>

    <Alert v-if="error" variant="destructive" class="mb-4">{{ error }}</Alert>
    <Alert v-if="deleteSuccess" variant="success" class="mb-4">{{ deleteSuccess }}</Alert>

    <!-- Filters -->
    <div class="flex flex-wrap items-center gap-3 mb-4">
      <Select v-model="actionPrefixSelect">
        <SelectTrigger class="w-48">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem v-for="opt in actionPrefixOptions" :key="opt.value" :value="opt.value">
            {{ opt.label }}
          </SelectItem>
        </SelectContent>
      </Select>

      <DateTimePicker v-model="dateFromFilter" placeholder="From date" class="w-44" />
      <DateTimePicker v-model="dateToFilter" placeholder="To date" class="w-44" />

      <Button v-if="actionPrefixFilter || dateFromFilter || dateToFilter" variant="ghost" size="sm"
        @click="actionPrefixFilter = ''; dateFromFilter = ''; dateToFilter = ''">
        Clear filters
      </Button>
    </div>

    <!-- Table -->
    <Card>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b text-left text-muted-foreground">
              <th class="py-2 px-3 w-6"></th>
              <th class="py-2 px-3">Timestamp</th>
              <th class="py-2 px-3">Actor</th>
              <th class="py-2 px-3">Action</th>
              <th class="py-2 px-3">Object</th>
            </tr>
          </thead>
          <tbody>
            <template v-if="loading">
              <tr>
                <td colspan="5" class="py-8 text-center text-muted-foreground">
                  <Loader2 class="inline h-5 w-5 animate-spin mr-2" />Loading…
                </td>
              </tr>
            </template>
            <template v-else-if="!items.length">
              <tr>
                <td colspan="5" class="py-8 text-center text-muted-foreground">No activity recorded yet.</td>
              </tr>
            </template>
            <template v-else v-for="item in items" :key="item.id">
              <tr
                class="border-b hover:bg-muted/40 transition-colors cursor-pointer"
                @click="toggleRow(item.id)"
              >
                <td class="py-2 px-3 text-muted-foreground">
                  <ChevronDown v-if="expandedRows.has(item.id)" class="h-4 w-4" />
                  <ChevronRight v-else class="h-4 w-4" />
                </td>
                <td class="py-2 px-3 whitespace-nowrap font-mono text-xs">{{ formatDate(item.created_at) }}</td>
                <td class="py-2 px-3 whitespace-nowrap">{{ formatActor(item) }}</td>
                <td class="py-2 px-3 whitespace-nowrap font-medium">{{ formatAction(item.action) }}</td>
                <td class="py-2 px-3 text-muted-foreground">{{ formatObject(item) }}</td>
              </tr>
              <tr v-if="expandedRows.has(item.id)" class="bg-muted/20">
                <td colspan="5" class="py-3 px-6 space-y-3">
                  <!-- AI prompt & reasoning (shown first if present) -->
                  <div v-if="item.context?._prompt || item.context?._reason" class="rounded border border-primary/30 bg-primary/5 p-3 space-y-2">
                    <div v-if="item.context._prompt">
                      <div class="text-xs font-semibold text-primary mb-0.5">User prompt</div>
                      <div class="text-sm">{{ item.context._prompt }}</div>
                    </div>
                    <div v-if="item.context._reason">
                      <div class="text-xs font-semibold text-primary mb-0.5">AI reasoning</div>
                      <div class="text-sm text-muted-foreground">{{ item.context._reason }}</div>
                    </div>
                  </div>

                  <!-- Actor metadata -->
                  <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 text-xs">
                    <div v-if="item.ip_address">
                      <div class="text-muted-foreground">IP</div>
                      <div class="font-mono">{{ item.ip_address }}</div>
                    </div>
                    <div v-if="item.token_hint">
                      <div class="text-muted-foreground">Token</div>
                      <div>{{ item.token_hint }}</div>
                    </div>
                    <div v-if="item.user_id">
                      <div class="text-muted-foreground">User ID</div>
                      <div>{{ item.user_id }}</div>
                    </div>
                  </div>

                  <!-- Change context (old/new or meta), excluding AI fields -->
                  <div v-if="item.context && Object.keys(item.context).filter(k => !k.startsWith('_')).length">
                    <div class="text-xs text-muted-foreground mb-1">Changes</div>
                    <pre class="text-xs bg-muted rounded p-3 overflow-x-auto whitespace-pre-wrap break-all">{{ JSON.stringify(Object.fromEntries(Object.entries(item.context).filter(([k]) => !k.startsWith('_'))), null, 2) }}</pre>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </Card>

    <!-- Pagination -->
    <div v-if="total > perPage" class="flex items-center justify-between mt-4 text-sm">
      <span class="text-muted-foreground">{{ total }} entries</span>
      <div class="flex gap-2">
        <Button variant="outline" size="sm" :disabled="page === 1" @click="page--">Previous</Button>
        <span class="px-2 py-1">{{ page }} / {{ totalPages }}</span>
        <Button variant="outline" size="sm" :disabled="page >= totalPages" @click="page++">Next</Button>
      </div>
    </div>

    <!-- Delete Old Dialog -->
    <Dialog
      :open="showDeleteDialog"
      title="Delete Old Activity"
      description="Remove activity entries older than the specified number of days."
      @close="showDeleteDialog = false"
    >
      <div class="space-y-3">
        <label class="text-sm font-medium">Delete entries older than</label>
        <Select v-model="deleteDays">
          <SelectTrigger class="w-48">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="7">7 days</SelectItem>
            <SelectItem value="30">30 days</SelectItem>
            <SelectItem value="60">60 days</SelectItem>
            <SelectItem value="90">90 days</SelectItem>
          </SelectContent>
        </Select>
      </div>
      <template #footer>
        <Button variant="outline" @click="showDeleteDialog = false">Cancel</Button>
        <Button variant="destructive" :loading="deleting" @click="handleDeleteOld">Delete</Button>
      </template>
    </Dialog>
  </div>
</template>
