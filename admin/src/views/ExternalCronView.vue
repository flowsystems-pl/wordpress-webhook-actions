<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { Timer, Play, Pause, RefreshCw, Info, AlertTriangle } from 'lucide-vue-next'
import { Button, Card, Input, Label, Alert, UpgradeBadge, RadioGroup, RadioGroupItem, Switch, Slider } from '@/components/ui'
import ExternalCronChart from '@/components/ExternalCronChart.vue'
import { usePro } from '@/composables/usePro'
import { useExternalCron } from '@/composables/useExternalCron'
import { __, sprintf } from '@/i18n'

const { proActive } = usePro()
const { settings, stats, loading, saving, error, statsStale, load, fetchStats, saveSettings, pause, resume } = useExternalCron(proActive)

const localEnabled   = ref(true)
const localMode      = ref('plugin_endpoint')
const localInterval  = ref(60)
const localBatchSize = ref(10)

const minInterval = computed(() => localMode.value === 'wp_cron' ? 60 : 20)

const formatInterval = (s) => {
  if (s < 60) return sprintf(__('%ds'), s)
  if (s < 3600) {
    const m = Math.floor(s / 60)
    const r = s % 60
    return r > 0 ? sprintf(__('%1$dm %2$ds'), m, r) : sprintf(__('%dm'), m)
  }
  const h = Math.floor(s / 3600)
  const m = Math.floor((s % 3600) / 60)
  return m > 0 ? sprintf(__('%1$dh %2$dm'), h, m) : sprintf(__('%dh'), h)
}

// Fire: always visible, grey at 1h, full colour at 61s, grows below 60s
const fireSize = computed(() => {
  if (localInterval.value >= 60) return 1
  return 1 + (60 - localInterval.value) / 40  // 1rem at 60s → 2rem at 20s
})

const fireGrayscale = computed(() => {
  if (localInterval.value <= 60) return 0
  return Math.round(Math.min(1, (localInterval.value - 60) / (3600 - 60)) * 100)
})

const fireStyle = computed(() => ({
  fontSize:   `${fireSize.value}rem`,
  lineHeight: '1',
  filter:     `grayscale(${fireGrayscale.value}%)`,
  transition: 'font-size 0.25s ease, filter 0.4s ease',
}))

const clampInterval = () => {
  localInterval.value = Math.max(minInterval.value, Math.min(3600, localInterval.value || minInterval.value))
}

const clampBatch = () => {
  localBatchSize.value = Math.max(1, Math.min(100, localBatchSize.value || 1))
}

watch(settings, (val) => {
  localEnabled.value   = val.enabled    ?? true
  localMode.value      = val.mode       ?? 'plugin_endpoint'
  localInterval.value  = val.interval   ?? 60
  localBatchSize.value = val.batch_size ?? 10
}, { immediate: true })

watch(localMode, (mode) => {
  const min = mode === 'wp_cron' ? 60 : 20
  if (localInterval.value < min) localInterval.value = min
})

const lastPingError = computed(() => {
  const beat = stats.value.beats?.[0]
  if (beat && beat.status !== 1) return beat.msg || __('Unknown error')
  return null
})

const monitorStatusLabel = computed(() => {
  if (!settings.value.monitor_id) return __('Not configured')
  return settings.value.monitor_active ? __('Active') : __('Paused')
})

const monitorStatusClass = computed(() =>
  settings.value.monitor_active
    ? 'bg-green-500/15 text-green-600 border-green-500/30 dark:text-green-400'
    : 'bg-yellow-500/15 text-yellow-600 border-yellow-500/30 dark:text-yellow-400'
)

const handleSave = () => saveSettings({
  enabled:    localEnabled.value,
  mode:       localMode.value,
  interval:   localInterval.value,
  batch_size: localBatchSize.value,
})

onMounted(() => { if (proActive.value) load() })

watch(proActive, (active) => { if (active) load() })
</script>

<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div class="flex items-center gap-2">
        <Timer class="w-5 h-5 text-muted-foreground" />
        <h2 class="text-lg font-semibold">{{ __('External Cron') }}</h2>
        <UpgradeBadge v-if="!proActive" />
      </div>
      <div v-if="proActive" class="flex items-center gap-2">
        <span v-if="statsStale" class="text-xs text-amber-500 flex items-center gap-1">
          <AlertTriangle class="w-3 h-3" /> {{ __('Stats unavailable') }}
        </span>
        <Button variant="ghost" size="sm" @click="fetchStats(true)" :disabled="loading">
          <RefreshCw class="w-4 h-4" :class="{ 'animate-spin': loading, 'text-amber-500': statsStale }" />
        </Button>
      </div>
    </div>

    <Alert v-if="error" variant="destructive">{{ error }}</Alert>

    <Alert v-if="proActive && lastPingError" variant="destructive">
      {{ __('Last ping failed:') }} <span class="font-mono text-xs">{{ lastPingError }}</span>
    </Alert>

    <!-- Monitor status bar (pro only, monitor must exist) -->
    <Card v-if="proActive && settings.monitor_id" class="p-4">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <span :class="['inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold border', monitorStatusClass]">
            {{ monitorStatusLabel }}
          </span>
          <span v-if="stats.beats?.length" class="text-sm text-muted-foreground">
            {{ sprintf(__('Last stats pull: %s'), new Date(stats.beats[0].time).toLocaleString()) }}
          </span>
        </div>
        <div class="flex gap-2">
          <Button v-if="settings.monitor_active" variant="outline" size="sm" @click="pause">
            <Pause class="w-4 h-4 mr-1.5" /> {{ __('Pause') }}
          </Button>
          <Button v-else variant="outline" size="sm" @click="resume">
            <Play class="w-4 h-4 mr-1.5" /> {{ __('Resume') }}
          </Button>
        </div>
      </div>
    </Card>

    <!-- Heartbeat history -->
    <Card class="p-6 space-y-3">
      <div class="flex items-center justify-between">
        <Label class="text-base">{{ __('External Cron History') }}</Label>
        <UpgradeBadge v-if="!proActive" />
      </div>
      <div :class="{ 'opacity-50 pointer-events-none select-none': !proActive }">
        <ExternalCronChart
          :beats="stats.beats"
          :uptime24h="stats.uptime_24h"
          :avg-ping="stats.avg_ping"
        />
      </div>
    </Card>

    <!-- Settings -->
    <Card class="p-6 space-y-6">
      <!-- Wrapper dims everything when not pro -->
      <div :class="{ 'opacity-50 pointer-events-none select-none': !proActive }">
        <!-- Enable toggle -->
        <div class="flex items-center justify-between mb-6">
          <Label>{{ __('Enable External Cron') }}</Label>
          <Switch v-model="localEnabled" :disabled="!proActive" />
        </div>

        <div :class="{ 'opacity-50 pointer-events-none': !localEnabled }" class="space-y-6 transition-opacity">
          <!-- Target -->
          <div class="space-y-2">
            <Label>{{ __('Target') }}</Label>
            <RadioGroup v-model="localMode" class="space-y-2">
              <div class="flex items-start gap-3">
                <RadioGroupItem id="mode-plugin" value="plugin_endpoint" class="mt-0.5" />
                <div>
                  <Label for="mode-plugin" class="cursor-pointer font-medium">{{ __('Plugin queue endpoint') }}</Label>
                  <p class="text-xs text-muted-foreground mt-0.5" v-html="sprintf(__('%1$s/wp-json/fswa/v1/cron/process?token=…%2$s — processes only the webhook queue. Min interval: 20s.'), '<code class=&quot;font-mono&quot;>', '</code>')"></p>
                </div>
              </div>
              <div class="flex items-start gap-3">
                <RadioGroupItem id="mode-wp" value="wp_cron" class="mt-0.5" />
                <div>
                  <Label for="mode-wp" class="cursor-pointer font-medium">{{ __('WP-Cron') }}</Label>
                  <p class="text-xs text-muted-foreground mt-0.5" v-html="sprintf(__('%1$s/wp-cron.php?doing_wp_cron%2$s — runs all WordPress scheduled tasks. Min interval: 60s. %1$sDISABLE_WP_CRON%2$s is added to %1$swp-config.php%2$s automatically.'), '<code class=&quot;font-mono&quot;>', '</code>')"></p>
                </div>
              </div>
            </RadioGroup>

            <div
              v-if="localMode === 'wp_cron'"
              class="flex items-start gap-2 text-xs p-3 rounded-md bg-muted border border-border"
            >
              <Info class="w-4 h-4 mt-0.5 shrink-0 text-muted-foreground" />
              <span v-if="settings.disable_wp_cron_added" v-html="sprintf(__('%1$sDISABLE_WP_CRON%2$s is active — WP-Cron auto-spawn is disabled.'), '<code class=&quot;font-mono&quot;>', '</code>')"></span>
              <span v-else-if="!settings.wp_config_writable" v-html="sprintf(__('%1$swp-config.php%2$s is not writable. Add this line manually before the “That’s all” comment: %3$sdefine( \'DISABLE_WP_CRON\', true );%4$s'), '<code class=&quot;font-mono&quot;>', '</code>', '<code class=&quot;block mt-1 font-bold font-mono&quot;>', '</code>')"></span>
              <span v-else class="text-muted-foreground" v-html="sprintf(__('%1$sDISABLE_WP_CRON%2$s will be written to %1$swp-config.php%2$s on save.'), '<code class=&quot;font-mono&quot;>', '</code>')"></span>
            </div>
          </div>

          <!-- Interval -->
          <div class="relative space-y-2">
            <div class="flex items-center justify-between">
              <div class="relative flex items-center">
                <Label>{{ __('Interval') }}</Label>
                <span
                  class="absolute left-full ml-1 pointer-events-none origin-left"
                  :style="fireStyle"
                >🔥</span>
              </div>
              <span class="text-xs text-muted-foreground">{{ formatInterval(localInterval) }}</span>
            </div>
            <div class="flex items-center gap-3">
              <div class="flex-1">
                <Slider v-model="localInterval" :min="minInterval" :max="3600" :step="5" />
              </div>
              <Input
                type="number"
                v-model.number="localInterval"
                :min="minInterval"
                :max="3600"
                class="w-20 text-center"
                @blur="clampInterval"
                @keydown.enter="clampInterval"
              />
            </div>
            <div class="flex justify-between text-xs text-muted-foreground">
              <span>{{ minInterval }}s</span><span>30m</span><span>1h</span>
            </div>

            <!-- IP whitelist warning, slides in below 60s -->
            <Transition
              enter-active-class="transition-all duration-300 ease-out"
              enter-from-class="opacity-0 -translate-y-1"
              leave-active-class="transition-all duration-200 ease-in"
              leave-to-class="opacity-0 -translate-y-1"
            >
              <div
                v-if="localInterval < 60"
                class="flex items-start gap-2 p-3 rounded-md border text-xs bg-amber-500/10 border-amber-500/25 text-amber-700 dark:text-amber-400"
              >
                <Info class="w-4 h-4 mt-0.5 shrink-0" />
                <span v-html="sprintf(__('You might want to whitelist the External Cron IP for your WAF or server security: %1$s72.62.157.193%2$s'), '<strong class=&quot;font-mono select-all&quot;>', '</strong>')"></span>
              </div>
            </Transition>
          </div>

          <!-- Batch size (plugin endpoint only) -->
          <div v-if="localMode === 'plugin_endpoint'" class="space-y-2">
            <div class="flex items-center justify-between">
              <div>
                <Label>{{ __('Queue batch size') }}</Label>
                <p class="text-xs text-muted-foreground mt-0.5">{{ __('Jobs processed per cron run.') }}</p>
              </div>
            </div>
            <div class="flex items-center gap-3">
              <div class="flex-1">
                <Slider v-model="localBatchSize" :min="1" :max="100" :step="1" />
              </div>
              <Input
                type="number"
                v-model.number="localBatchSize"
                :min="1"
                :max="100"
                class="w-20 text-center"
                @blur="clampBatch"
                @keydown.enter="clampBatch"
              />
            </div>
            <div class="flex justify-between text-xs text-muted-foreground">
              <span>1</span><span>50</span><span>100</span>
            </div>
          </div>
        </div>
      </div>

      <Button @click="handleSave" :disabled="saving || !proActive">
        {{ saving ? __('Saving…') : __('Save') }}
      </Button>
    </Card>
  </div>
</template>
