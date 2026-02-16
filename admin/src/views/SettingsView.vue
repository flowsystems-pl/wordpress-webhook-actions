<script setup>
import { ref, onMounted } from 'vue'
import { Download, Trash2, Archive, Copy, RefreshCw, Clock, Check } from 'lucide-vue-next'
import { Button, Card, Label, Select, Switch, Alert, Dialog } from '@/components/ui'
import api from '@/lib/api'

const settings = ref({
  log_retention_days: 30,
  archive_logs: true,
})
const info = ref(null)
const archive = ref(null)
const cronInfo = ref(null)
const loading = ref(true)
const saving = ref(false)
const error = ref(null)
const success = ref(null)
const showClearDialog = ref(false)
const clearing = ref(false)
const regeneratingToken = ref(false)
const copiedField = ref(null)

const retentionOptions = [
  { value: 7, label: '7 days' },
  { value: 14, label: '14 days' },
  { value: 30, label: '30 days' },
  { value: 60, label: '60 days' },
  { value: 90, label: '90 days' },
]

const loadData = async () => {
  loading.value = true
  error.value = null

  try {
    const [settingsData, infoData, archiveData, cronData] = await Promise.all([
      api.settings.get(),
      api.settings.info(),
      api.settings.archive(),
      api.cron.info(),
    ])

    settings.value = settingsData
    info.value = infoData
    archive.value = archiveData
    cronInfo.value = cronData
  } catch (e) {
    error.value = e.message
    console.error('Failed to load settings:', e)
  } finally {
    loading.value = false
  }
}

const saveSettings = async () => {
  saving.value = true
  error.value = null
  success.value = null

  try {
    await api.settings.update(settings.value)
    success.value = 'Settings saved successfully'
    setTimeout(() => {
      success.value = null
    }, 3000)
  } catch (e) {
    error.value = e.message
    console.error('Failed to save settings:', e)
  } finally {
    saving.value = false
  }
}

const downloadArchive = async () => {
  try {
    const result = await api.settings.downloadArchive()
    if (result.download_url) {
      window.open(result.download_url, '_blank')
    }
  } catch (e) {
    error.value = e.message
    console.error('Failed to download archive:', e)
  }
}

const clearLogs = async () => {
  clearing.value = true
  error.value = null

  try {
    await api.settings.clearLogs()
    showClearDialog.value = false
    success.value = 'All logs have been cleared'
    await loadData()
    setTimeout(() => {
      success.value = null
    }, 3000)
  } catch (e) {
    error.value = e.message
    console.error('Failed to clear logs:', e)
  } finally {
    clearing.value = false
  }
}

const regenerateCronToken = async () => {
  regeneratingToken.value = true
  error.value = null

  try {
    const result = await api.cron.regenerateToken()
    cronInfo.value.token = result.token
    cronInfo.value.cron_url = result.cron_url
    cronInfo.value.cron_command = `*/1 * * * * curl -fsS '${result.cron_url}' >/dev/null 2>&1`
    success.value = 'Cron token regenerated. Update your crontab with the new command.'
    setTimeout(() => {
      success.value = null
    }, 5000)
  } catch (e) {
    error.value = e.message
    console.error('Failed to regenerate token:', e)
  } finally {
    regeneratingToken.value = false
  }
}

const copyToClipboard = async (text, field) => {
  try {
    await navigator.clipboard.writeText(text)
    copiedField.value = field
    setTimeout(() => {
      copiedField.value = null
    }, 2000)
  } catch (e) {
    console.error('Failed to copy:', e)
  }
}

onMounted(loadData)
</script>

<template>
  <div>
    <!-- Header -->
    <div class="mb-6">
      <h2 class="text-xl font-semibold">Settings</h2>
      <p class="text-muted-foreground">Configure plugin behavior</p>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="text-center py-8 text-muted-foreground">
      Loading settings...
    </div>

    <template v-else>
      <!-- Alerts -->
      <Alert v-if="error" variant="destructive" class="mb-4">
        {{ error }}
      </Alert>

      <Alert v-if="success" variant="success" class="mb-4">
        {{ success }}
      </Alert>

      <div class="grid gap-6 max-w-2xl">
        <!-- Cron Configuration -->
        <Card v-if="cronInfo" class="p-6">
          <h3 class="text-lg font-medium mb-4">
            <Clock class="inline h-5 w-5 mr-2" />
            Queue Processor (Cron)
          </h3>

          <div class="space-y-4">
            <div>
              <p class="text-sm text-muted-foreground mb-4">
                For reliable webhook delivery, set up a system cron job to process the queue every minute.
                WP-Cron serves as a fallback when system cron isn't configured.
              </p>
            </div>

            <!-- Cron Command -->
            <div class="space-y-2">
              <Label>Crontab Command</Label>
              <div class="flex gap-2">
                <code class="flex-1 p-3 bg-muted rounded text-xs font-mono break-all select-all">
                  {{ cronInfo.cron_command }}
                </code>
                <Button
                  variant="outline"
                  size="sm"
                  @click="copyToClipboard(cronInfo.cron_command, 'command')"
                  class="shrink-0"
                >
                  <Check v-if="copiedField === 'command'" class="h-4 w-4" />
                  <Copy v-else class="h-4 w-4" />
                </Button>
              </div>
              <p class="text-xs text-muted-foreground">
                Add this line to your server's crontab: <code class="bg-muted px-1 rounded">crontab -e</code>
              </p>
            </div>

            <!-- Cron URL -->
            <div class="space-y-2">
              <Label>Cron URL</Label>
              <div class="flex gap-2">
                <code class="flex-1 p-3 bg-muted rounded text-xs font-mono break-all select-all">
                  {{ cronInfo.cron_url }}
                </code>
                <Button
                  variant="outline"
                  size="sm"
                  @click="copyToClipboard(cronInfo.cron_url, 'url')"
                  class="shrink-0"
                >
                  <Check v-if="copiedField === 'url'" class="h-4 w-4" />
                  <Copy v-else class="h-4 w-4" />
                </Button>
              </div>
            </div>

            <!-- Status Grid -->
            <div class="grid grid-cols-2 gap-4 text-sm mt-4 pt-4 border-t">
              <div>
                <div class="font-medium">Last Cron Run</div>
                <div class="text-muted-foreground">{{ cronInfo.last_run_human }}</div>
              </div>
              <div>
                <div class="font-medium">WP-Cron Fallback</div>
                <div class="text-muted-foreground">
                  <span v-if="cronInfo.wp_cron_disabled" class="text-yellow-600">Disabled</span>
                  <span v-else class="text-green-600">Active</span>
                </div>
              </div>
            </div>

            <!-- Regenerate Token -->
            <div class="pt-4 border-t">
              <Button
                variant="outline"
                :loading="regeneratingToken"
                @click="regenerateCronToken"
              >
                <RefreshCw class="mr-2 h-4 w-4" />
                Regenerate Token
              </Button>
              <p class="mt-2 text-xs text-muted-foreground">
                Regenerating the token will invalidate the current cron URL.
                You'll need to update your crontab.
              </p>
            </div>
          </div>
        </Card>

        <!-- Log Retention -->
        <Card class="p-6">
          <h3 class="text-lg font-medium mb-4">Log Retention</h3>

          <div class="space-y-4">
            <div class="space-y-2">
              <Label>Keep logs for</Label>
              <Select
                v-model="settings.log_retention_days"
                :options="retentionOptions"
                class="w-48"
              />
              <p class="text-sm text-muted-foreground">
                Logs older than this will be automatically deleted
              </p>
            </div>

            <div class="flex items-center space-x-2">
              <Switch v-model="settings.archive_logs" />
              <Label>Archive logs before deletion</Label>
            </div>
            <p class="text-sm text-muted-foreground">
              When enabled, logs are exported to JSON files before being deleted
            </p>
          </div>

          <div class="mt-6">
            <Button :loading="saving" @click="saveSettings">
              Save Settings
            </Button>
          </div>
        </Card>

        <!-- Archive Info -->
        <Card v-if="archive" class="p-6">
          <h3 class="text-lg font-medium mb-4">
            <Archive class="inline h-5 w-5 mr-2" />
            Log Archive
          </h3>

          <div v-if="archive.exists" class="space-y-4">
            <div class="grid grid-cols-2 gap-4 text-sm">
              <div>
                <div class="font-medium">Size</div>
                <div class="text-muted-foreground">{{ archive.size_human }}</div>
              </div>
              <div>
                <div class="font-medium">Files</div>
                <div class="text-muted-foreground">{{ archive.files_count }}</div>
              </div>
              <div>
                <div class="font-medium">Oldest</div>
                <div class="text-muted-foreground">{{ archive.oldest_date || 'N/A' }}</div>
              </div>
              <div>
                <div class="font-medium">Newest</div>
                <div class="text-muted-foreground">{{ archive.newest_date || 'N/A' }}</div>
              </div>
            </div>

            <Button variant="outline" @click="downloadArchive">
              <Download class="mr-2 h-4 w-4" />
              Download Archive (ZIP)
            </Button>
          </div>

          <div v-else class="text-muted-foreground">
            No archive files yet
          </div>
        </Card>

        <!-- Plugin Info -->
        <Card v-if="info" class="p-6">
          <h3 class="text-lg font-medium mb-4">Plugin Info</h3>

          <div class="grid grid-cols-2 gap-4 text-sm">
            <div>
              <div class="font-medium">Plugin Version</div>
              <div class="text-muted-foreground">{{ info.version }}</div>
            </div>
            <div>
              <div class="font-medium">Database Version</div>
              <div class="text-muted-foreground">{{ info.db_version }}</div>
            </div>
            <div>
              <div class="font-medium">Total Logs</div>
              <div class="text-muted-foreground">{{ info.logs_count }}</div>
            </div>
            <div>
              <div class="font-medium">Oldest Log</div>
              <div class="text-muted-foreground">{{ info.oldest_log || 'N/A' }}</div>
            </div>
          </div>
        </Card>

        <!-- Danger Zone -->
        <Card class="p-6 border-destructive">
          <h3 class="text-lg font-medium text-destructive mb-4">Danger Zone</h3>

          <div class="space-y-4">
            <div>
              <Button variant="destructive" @click="showClearDialog = true">
                <Trash2 class="mr-2 h-4 w-4" />
                Clear All Logs
              </Button>
              <p class="mt-2 text-sm text-muted-foreground">
                This will permanently delete all logs from the database
              </p>
            </div>
          </div>
        </Card>
      </div>
    </template>

    <!-- Clear Logs Dialog -->
    <Dialog
      :open="showClearDialog"
      title="Clear All Logs"
      description="This action cannot be undone. All logs will be permanently deleted."
      @close="showClearDialog = false"
    >
      <p class="text-muted-foreground">
        Are you sure you want to delete all {{ info?.logs_count || 0 }} logs?
      </p>

      <template #footer>
        <Button variant="outline" @click="showClearDialog = false">
          Cancel
        </Button>
        <Button variant="destructive" :loading="clearing" @click="clearLogs">
          Delete All Logs
        </Button>
      </template>
    </Dialog>
  </div>
</template>
