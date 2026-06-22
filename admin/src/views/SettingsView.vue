<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { Download, Trash2, Archive, Copy, RefreshCw, Clock, Check, RotateCcw, Info } from 'lucide-vue-next'
import { Button, Card, Input, Label, Select, SelectTrigger, SelectValue, SelectContent, SelectItem, Switch, Alert, Dialog, UpgradeBadge, RadioGroup, RadioGroupItem, Tooltip } from '@/components/ui'
import api from '@/lib/api'
import { usePro } from '@/composables/usePro'
import { useCopyToClipboard } from '@/composables/useCopyToClipboard'
import { __, _n, sprintf } from '@/i18n'

const { proActive } = usePro()

const settings = ref({
  log_retention_days: 30,
  archive_logs: true,
  menu_under_tools: false,
  activity_log_retention_days: 90,
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
const { copiedKey: copiedField, copy: copyToClipboard } = useCopyToClipboard()

const proSettings = ref({
  global_max_attempts: '',
  backoff_strategy: '',
  backoff_base_delay: '',
  backoff_max_delay: '',
})
const savingProSettings = ref(false)

const ordinal = (n) => {
  const s = ['th', 'st', 'nd', 'rd']
  const v = n % 100
  return n + (s[(v - 20) % 10] || s[v] || s[0])
}

const formatDelay = (seconds) => {
  if (seconds < 60) return sprintf(_n('%d second', '%d seconds', seconds), seconds)
  if (seconds < 3600) {
    const m = Math.floor(seconds / 60)
    const s = seconds % 60
    const mStr = sprintf(_n('%d minute', '%d minutes', m), m)
    return s > 0 ? sprintf(__('%1$s %2$ds'), mStr, s) : mStr
  }
  const h = Math.floor(seconds / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  const hStr = sprintf(_n('%d hour', '%d hours', h), h)
  return m > 0 ? sprintf(__('%1$s %2$d min'), hStr, m) : hStr
}

watch(() => proSettings.value.backoff_strategy, (val) => {
  if (val === '') {
    proSettings.value.backoff_base_delay = ''
    proSettings.value.backoff_max_delay  = ''
  }
})

const backoffPreview = computed(() => {
  const maxAttempts = proSettings.value.global_max_attempts !== ''
    ? Math.max(2, parseInt(proSettings.value.global_max_attempts, 10))
    : 5
  const strategy  = proSettings.value.backoff_strategy || 'exponential'
  const baseDelay = proSettings.value.backoff_base_delay !== ''
    ? parseInt(proSettings.value.backoff_base_delay, 10)
    : (strategy === 'exponential' ? 30 : 60)
  const maxDelay  = proSettings.value.backoff_max_delay !== ''
    ? parseInt(proSettings.value.backoff_max_delay, 10)
    : 3600

  const delays = []
  for (let n = 1; n < maxAttempts; n++) {
    let d
    if (strategy === 'linear')      d = n * baseDelay
    else if (strategy === 'fixed')  d = baseDelay
    else                            d = Math.min(Math.pow(2, n) * baseDelay, maxDelay)
    delays.push(d)
  }

  const peak = Math.max(...delays, 1)
  return delays.map((d, i) => ({
    delay: d,
    label: sprintf(__('Wait %s'), formatDelay(d)),
    height: Math.max(4, Math.round((d / peak) * 56)),
    retryLabel: sprintf(__('%s Retry'), ordinal(i + 1)),
  }))
})

const retentionOptions = [
  { value: '7', label: sprintf(_n('%d day', '%d days', 7), 7) },
  { value: '14', label: sprintf(_n('%d day', '%d days', 14), 14) },
  { value: '30', label: sprintf(_n('%d day', '%d days', 30), 30) },
  { value: '60', label: sprintf(_n('%d day', '%d days', 60), 60) },
  { value: '90', label: sprintf(_n('%d day', '%d days', 90), 90) },
]

// Radix-vue Select requires string values; convert to/from number
const retentionDays = computed({
  get: () => String(settings.value.log_retention_days ?? 30),
  set: (val) => { settings.value.log_retention_days = parseInt(val, 10) },
})

const activityRetentionDays = computed({
  get: () => String(settings.value.activity_log_retention_days ?? 90),
  set: (val) => { settings.value.activity_log_retention_days = parseInt(val, 10) },
})

const savedMenuUnderTools = ref(false)
const isPlayground = window.location.hostname === 'playground.wordpress.net'

const loadData = async () => {
  loading.value = true
  error.value = null

  try {
    const promises = [
      api.settings.get(),
      api.settings.info(),
      api.settings.archive(),
      api.cron.info(),
    ]

    if (proActive.value) {
      promises.push(api.proSettings.get())
    }

    const [settingsData, infoData, archiveData, cronData, proSettingsData] = await Promise.all(promises)

    settings.value = settingsData
    savedMenuUnderTools.value = settingsData.menu_under_tools
    info.value = infoData
    archive.value = archiveData
    cronInfo.value = cronData

    if (proSettingsData) {
      proSettings.value.global_max_attempts = proSettingsData.global_max_attempts != null
        ? String(proSettingsData.global_max_attempts) : ''
      proSettings.value.backoff_strategy    = proSettingsData.backoff_strategy ?? ''
      proSettings.value.backoff_base_delay  = proSettingsData.backoff_base_delay != null
        ? String(proSettingsData.backoff_base_delay) : ''
      proSettings.value.backoff_max_delay   = proSettingsData.backoff_max_delay != null
        ? String(proSettingsData.backoff_max_delay) : ''
    }
  } catch (e) {
    error.value = e.message
    console.error('Failed to load settings:', e)
  } finally {
    loading.value = false
  }
}

const saveProSettings = async () => {
  savingProSettings.value = true
  error.value = null
  success.value = null

  try {
    const s = proSettings.value
    await api.proSettings.update({
      global_max_attempts: s.global_max_attempts !== '' ? parseInt(s.global_max_attempts, 10) : null,
      backoff_strategy:    s.backoff_strategy !== '' ? s.backoff_strategy : null,
      backoff_base_delay:  s.backoff_base_delay !== '' ? parseInt(s.backoff_base_delay, 10) : null,
      backoff_max_delay:   s.backoff_max_delay !== '' ? parseInt(s.backoff_max_delay, 10) : null,
    })
    success.value = __('Retry settings saved')
    setTimeout(() => { success.value = null }, 3000)
  } catch (e) {
    error.value = e.message
    console.error('Failed to save retry settings:', e)
  } finally {
    savingProSettings.value = false
  }
}

const saveSettings = async () => {
  saving.value = true
  error.value = null
  success.value = null

  const menuPositionChanged = settings.value.menu_under_tools !== savedMenuUnderTools.value

  try {
    await api.settings.update(settings.value)
    if (menuPositionChanged && !isPlayground) {
      window.location.reload()
      return
    }
    success.value = __('Settings saved successfully')
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
    success.value = __('All logs have been cleared')
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
    success.value = __('Cron token regenerated. Update your crontab with the new command.')
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

onMounted(loadData)
</script>

<template>
  <div>
    <!-- Header -->
    <div class="mb-6">
      <h2 class="text-xl font-semibold">{{ __('Settings') }}</h2>
      <p class="text-muted-foreground">{{ __('Configure plugin behavior') }}</p>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="text-center py-8 text-muted-foreground">
      {{ __('Loading settings...') }}
    </div>

    <template v-else>
      <!-- Alerts -->
      <Alert v-if="error" variant="destructive" class="mb-4">
        {{ error }}
      </Alert>

      <Alert v-if="success" variant="success" class="mb-4">
        {{ success }}
      </Alert>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
        <!-- Left column -->
        <div class="space-y-6">
          <!-- Cron Configuration -->
          <Card v-if="cronInfo" class="p-6">
            <h3 class="text-lg font-medium mb-4">
              <Clock class="inline h-5 w-5 mr-2" />
              {{ __('Queue Processor (Cron)') }}
            </h3>

            <div class="space-y-4">
              <div>
                <p class="text-sm text-muted-foreground mb-4">
                  <span v-if="cronInfo.action_scheduler_active">
                    {{ __('Action Scheduler detected — queue processing is managed automatically. You can still configure an external cron URL as a direct trigger.') }}
                  </span>
                  <span v-else>
                    {{ __("For reliable webhook delivery, set up a system cron job to process the queue every minute. WP-Cron serves as a fallback when system cron isn't configured.") }}
                  </span>
                </p>
              </div>

              <!-- Cron Command -->
              <div class="space-y-2">
                <Label>{{ __('Crontab Command') }}</Label>
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
                <p class="text-xs text-muted-foreground"
                   v-html="sprintf(__('Add this line to your server\'s crontab: %1$scrontab -e%2$s'), '<code class=&quot;bg-muted px-1 rounded&quot;>', '</code>')">
                </p>
              </div>

              <!-- Cron URL -->
              <div class="space-y-2">
                <Label>{{ __('Cron URL') }}</Label>
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
                  <div class="font-medium">{{ __('Last Cron Run') }}</div>
                  <div class="text-muted-foreground">{{ cronInfo.last_run_human }}</div>
                </div>
                <div>
                  <div class="font-medium">{{ __('Scheduler') }}</div>
                  <div>
                    <span v-if="cronInfo.action_scheduler_active" class="text-green-600">{{ __('Action Scheduler') }}</span>
                    <span v-else class="text-muted-foreground">{{ __('WP-Cron') }}</span>
                  </div>
                </div>
                <div v-if="!cronInfo.action_scheduler_active">
                  <div class="font-medium">{{ __('WP-Cron Fallback') }}</div>
                  <div class="text-muted-foreground">
                    <span v-if="cronInfo.wp_cron_disabled" class="text-yellow-600">{{ __('Disabled') }}</span>
                    <span v-else class="text-green-600">{{ __('Active') }}</span>
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
                  {{ __('Regenerate Token') }}
                </Button>
                <p class="mt-2 text-xs text-muted-foreground">
                  {{ __("Regenerating the token will invalidate the current cron URL. You'll need to update your crontab.") }}
                </p>
              </div>
            </div>
          </Card>

          <!-- Plugin Info -->
          <Card v-if="info" class="p-6">
            <h3 class="text-lg font-medium mb-4">{{ __('Plugin Info') }}</h3>

            <div class="grid grid-cols-2 gap-4 text-sm">
              <div>
                <div class="font-medium">{{ __('Plugin Version') }}</div>
                <div class="text-muted-foreground">{{ info.version }}</div>
              </div>
              <div>
                <div class="font-medium">{{ __('Database Version') }}</div>
                <div class="text-muted-foreground">{{ info.db_version }}</div>
              </div>
              <div>
                <div class="font-medium">{{ __('Total Logs') }}</div>
                <div class="text-muted-foreground">{{ info.logs_count }}</div>
              </div>
              <div>
                <div class="font-medium">{{ __('Oldest Log') }}</div>
                <div class="text-muted-foreground">{{ info.oldest_log || __('N/A') }}</div>
              </div>
            </div>
          </Card>
        </div>

        <!-- Right column -->
        <div class="space-y-6">
          <!-- Log Retention -->
          <Card class="p-6">
            <h3 class="text-lg font-medium mb-4">{{ __('Log Retention') }}</h3>

            <div class="space-y-4">
              <div class="space-y-2">
                <Label>{{ __('Keep webhook logs for') }}</Label>
                <Select v-model="retentionDays">
                  <SelectTrigger class="w-48">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem v-for="option in retentionOptions" :key="option.value" :value="option.value">
                      {{ option.label }}
                    </SelectItem>
                  </SelectContent>
                </Select>
                <p class="text-sm text-muted-foreground">
                  {{ __('Webhook delivery logs older than this will be automatically deleted.') }}
                </p>
              </div>

              <div class="space-y-2">
                <Label>{{ __('Keep activity log for') }}</Label>
                <Select v-model="activityRetentionDays">
                  <SelectTrigger class="w-48">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem v-for="option in retentionOptions" :key="option.value" :value="option.value">
                      {{ option.label }}
                    </SelectItem>
                  </SelectContent>
                </Select>
                <p class="text-sm text-muted-foreground">
                  {{ __('Admin action history older than this will be automatically deleted.') }}
                </p>
              </div>

              <div class="flex items-center space-x-2">
                <Switch v-model="settings.archive_logs" />
                <Label>{{ __('Archive logs before deletion') }}</Label>
              </div>
              <p class="text-sm text-muted-foreground">
                {{ __('When enabled, logs are exported to JSON files before being deleted.') }}
              </p>

            </div>

            <div class="mt-6">
              <Button :loading="saving" @click="saveSettings">
                {{ __('Save Settings') }}
              </Button>
            </div>
          </Card>

          <!-- Admin Menu -->
          <Card class="p-6">
            <h3 class="text-lg font-medium mb-4">{{ __('Admin Menu') }}</h3>

            <div class="space-y-4">
              <div class="flex items-center space-x-2">
                <Switch v-model="settings.menu_under_tools" />
                <Label>{{ __('Show menu under Tools') }}</Label>
              </div>
              <p class="text-sm text-muted-foreground">
                {{ __('Move the admin menu item under Tools instead of the top-level sidebar.') }}
                <span v-if="isPlayground" class="block mt-1 text-yellow-600">
                  {{ __('Page reload is disabled on WordPress Playground — the menu will update on next WP menu item click.') }}
                </span>
              </p>
            </div>

            <div class="mt-6">
              <Button :loading="saving" @click="saveSettings">
                {{ __('Save Settings') }}
              </Button>
            </div>
          </Card>

          <!-- Retry Settings (Pro) -->
          <Card class="p-6 space-y-6">
            <div class="flex items-center gap-2 mb-4">
              <h3 class="text-lg font-medium">
                <RotateCcw class="inline h-5 w-5 mr-2" />
                {{ __('Retry Settings') }}
              </h3>
              <UpgradeBadge v-if="!proActive" />
            </div>

            <template v-if="proActive">
              <div class="space-y-6">
                <!-- Max Attempts -->
                <div class="space-y-2">
                  <div class="flex items-center gap-1.5">
                    <Label for="global_max_attempts">{{ __('Max Attempts') }}</Label>
                    <Tooltip :content="__('Total delivery attempts per webhook, including the first try. Once this number is reached the job is permanently failed.')" side="right">
                      <Info class="h-3.5 w-3.5 text-muted-foreground cursor-help shrink-0" />
                    </Tooltip>
                  </div>
                  <Input
                    id="global_max_attempts"
                    v-model="proSettings.global_max_attempts"
                    type="number"
                    min="1"
                    max="100"
                    :placeholder="__('5 (plugin default)')"
                    class="w-48"
                  />
                  <p class="text-sm text-muted-foreground">
                    {{ __('Total delivery attempts per webhook (including the first try). Leave empty for the plugin default of 5.') }}
                  </p>
                </div>

                <div class="border-t pt-5 space-y-4">
                  <!-- Backoff Strategy -->
                  <div class="space-y-3">
                    <Label>{{ __('Backoff Strategy') }}</Label>
                    <RadioGroup v-model="proSettings.backoff_strategy" class="space-y-2">
                      <div class="flex items-start gap-2">
                        <RadioGroupItem id="strategy-default" value="" class="mt-0.5" />
                        <label for="strategy-default" class="cursor-pointer">
                          <div class="text-sm font-medium">{{ __('Plugin default') }}</div>
                          <div class="text-xs text-muted-foreground">{{ __('Exponential — base 30 s, cap 3600 s') }}</div>
                        </label>
                      </div>
                      <div class="flex items-start gap-2">
                        <RadioGroupItem id="strategy-exponential" value="exponential" class="mt-0.5" />
                        <label for="strategy-exponential" class="cursor-pointer">
                          <div class="text-sm font-medium">{{ __('Exponential') }}</div>
                          <div class="text-xs text-muted-foreground">{{ __('Delay doubles each retry: 2ⁿ × base, capped at max') }}</div>
                        </label>
                      </div>
                      <div class="flex items-start gap-2">
                        <RadioGroupItem id="strategy-linear" value="linear" class="mt-0.5" />
                        <label for="strategy-linear" class="cursor-pointer">
                          <div class="text-sm font-medium">{{ __('Linear') }}</div>
                          <div class="text-xs text-muted-foreground">{{ __('Delay grows evenly: n × base seconds') }}</div>
                        </label>
                      </div>
                      <div class="flex items-start gap-2">
                        <RadioGroupItem id="strategy-fixed" value="fixed" class="mt-0.5" />
                        <label for="strategy-fixed" class="cursor-pointer">
                          <div class="text-sm font-medium">{{ __('Fixed') }}</div>
                          <div class="text-xs text-muted-foreground">{{ __('Same delay every retry: base seconds') }}</div>
                        </label>
                      </div>
                    </RadioGroup>
                  </div>

                  <!-- Delay inputs (shown when a strategy is explicitly selected) -->
                  <div v-if="proSettings.backoff_strategy !== ''" class="grid grid-cols-2 gap-4">
                    <div class="space-y-2">
                      <div class="flex items-center gap-1.5">
                      <Label for="backoff_base_delay">{{ __('Base Delay (seconds)') }}</Label>
                      <Tooltip :content="__('The base number of seconds used to calculate each retry delay. Exact role depends on the strategy: multiplier for exponential, interval for linear, constant for fixed.')" side="right">
                        <Info class="h-3.5 w-3.5 text-muted-foreground cursor-help shrink-0" />
                      </Tooltip>
                    </div>
                      <Input
                        id="backoff_base_delay"
                        v-model="proSettings.backoff_base_delay"
                        type="number"
                        min="1"
                        max="86400"
                        :placeholder="proSettings.backoff_strategy === 'exponential' ? '30' : '60'"
                      />
                    </div>
                    <div v-if="proSettings.backoff_strategy === 'exponential'" class="space-y-2">
                      <div class="flex items-center gap-1.5">
                      <Label for="backoff_max_delay">{{ __('Max Delay (seconds)') }}</Label>
                      <Tooltip :content="__('Cap on the wait between retries. Exponential backoff grows 2ⁿ × base — without a cap delays would grow indefinitely. Any calculated delay above this value is clamped to it.')" side="right">
                        <Info class="h-3.5 w-3.5 text-muted-foreground cursor-help shrink-0" />
                      </Tooltip>
                    </div>
                      <Input
                        id="backoff_max_delay"
                        v-model="proSettings.backoff_max_delay"
                        type="number"
                        min="1"
                        max="86400"
                        placeholder="3600"
                      />
                    </div>
                  </div>
                </div>
              </div>

              <!-- Backoff preview chart -->
              <div v-if="backoffPreview.length" class="border-t pt-5 space-y-2">
                <p class="text-sm font-medium">{{ __('Delay preview') }}</p>
                <div class="flex items-end gap-1.5" style="height: 64px;">
                  <div
                    v-for="item in backoffPreview"
                    :key="item.retry"
                    class="flex-1 bg-accent rounded-sm transition-all duration-300"
                    :style="{ height: item.height + 'px' }"
                  />
                </div>
                <div class="flex gap-1.5">
                  <div
                    v-for="item in backoffPreview"
                    :key="item.retry"
                    class="flex-1 text-center"
                  >
                    <div class="text-xs font-medium truncate">{{ item.label }}</div>
                    <div class="text-xs text-muted-foreground truncate">{{ item.retryLabel }}</div>
                  </div>
                </div>
                <p class="text-xs text-muted-foreground">{{ __('Wait before each retry') }}</p>
              </div>

              <div class="mt-6">
                <Button :loading="savingProSettings" @click="saveProSettings">
                  {{ __('Save Retry Settings') }}
                </Button>
              </div>
            </template>

            <template v-else>
              <p class="text-sm text-muted-foreground">
                {{ __('Configure retry attempts and backoff delay strategy for failed webhooks, globally and per webhook.') }}
              </p>
            </template>
          </Card>

          <!-- Archive Info -->
          <Card v-if="archive" class="p-6">
            <h3 class="text-lg font-medium mb-4">
              <Archive class="inline h-5 w-5 mr-2" />
              {{ __('Log Archive') }}
            </h3>

            <div v-if="archive.exists" class="space-y-4">
              <div class="grid grid-cols-2 gap-4 text-sm">
                <div>
                  <div class="font-medium">{{ __('Size') }}</div>
                  <div class="text-muted-foreground">{{ archive.size_human }}</div>
                </div>
                <div>
                  <div class="font-medium">{{ __('Files') }}</div>
                  <div class="text-muted-foreground">{{ archive.files_count }}</div>
                </div>
                <div>
                  <div class="font-medium">{{ __('Oldest') }}</div>
                  <div class="text-muted-foreground">{{ archive.oldest_date || __('N/A') }}</div>
                </div>
                <div>
                  <div class="font-medium">{{ __('Newest') }}</div>
                  <div class="text-muted-foreground">{{ archive.newest_date || __('N/A') }}</div>
                </div>
              </div>

              <Button variant="outline" @click="downloadArchive">
                <Download class="mr-2 h-4 w-4" />
                {{ __('Download Archive (ZIP)') }}
              </Button>
            </div>

            <div v-else class="text-muted-foreground">
              {{ __('No archive files yet') }}
            </div>
          </Card>

          <!-- Danger Zone -->
          <Card class="p-6 border-destructive">
            <h3 class="text-lg font-medium text-destructive mb-4">{{ __('Danger Zone') }}</h3>

            <div class="space-y-4">
              <div>
                <Button variant="destructive" @click="showClearDialog = true">
                  <Trash2 class="mr-2 h-4 w-4" />
                  {{ __('Clear All Logs') }}
                </Button>
                <p class="mt-2 text-sm text-muted-foreground">
                  {{ __('This will permanently delete all logs from the database.') }}
                </p>
              </div>
            </div>
          </Card>
        </div>
      </div>
    </template>

    <!-- Clear Logs Dialog -->
    <Dialog
      :open="showClearDialog"
      :title="__('Clear All Logs')"
      :description="__('This action cannot be undone. All logs will be permanently deleted.')"
      @close="showClearDialog = false"
    >
      <p class="text-muted-foreground">
        {{ sprintf(_n('Are you sure you want to delete all %d log?', 'Are you sure you want to delete all %d logs?', info?.logs_count || 0), info?.logs_count || 0) }}
      </p>

      <template #footer>
        <Button variant="outline" @click="showClearDialog = false">
          {{ __('Cancel') }}
        </Button>
        <Button variant="destructive" :loading="clearing" @click="clearLogs">
          {{ __('Delete All Logs') }}
        </Button>
      </template>
    </Dialog>
  </div>
</template>
