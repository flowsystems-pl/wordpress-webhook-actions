import { ref, watch, onUnmounted } from 'vue'
import { useIntervalFn } from '@vueuse/core'
import api from '@/lib/api'

export function useExternalCron(enabled = ref(true)) {
  const settings   = ref({ mode: 'wp', interval: 60, monitor_id: null, monitor_active: false })
  const stats      = ref({ beats: [], uptime_24h: null, avg_ping: null })
  const loading    = ref(false)
  const saving     = ref(false)
  const error      = ref(null)
  const statsStale = ref(false)

  const fetchSettings = async () => {
    try {
      settings.value = await api.externalCron.getSettings()
    } catch (e) {
      if (e.code !== 'license_inactive') error.value = e.message
    }
  }

  const fetchStats = async (manual = false) => {
    try {
      const fresh = await api.externalCron.getStats()
      if (!fresh.beats?.length && stats.value.beats?.length && !manual) {
        // Auto-poll returned empty while we have data — keep existing, flag stale
        statsStale.value = true
      } else {
        stats.value      = fresh
        statsStale.value = false
      }
    } catch (_) {}
  }

  const saveSettings = async (data) => {
    saving.value = true
    error.value  = null
    try {
      settings.value = await api.externalCron.saveSettings(data)
    } catch (e) {
      error.value = e.message
    } finally {
      saving.value = false
    }
  }

  const pause = async () => {
    try {
      await api.externalCron.pause()
      settings.value = { ...settings.value, monitor_active: false }
    } catch (e) {
      error.value = e.message
    }
  }

  const resume = async () => {
    try {
      await api.externalCron.resume()
      settings.value = { ...settings.value, monitor_active: true }
    } catch (e) {
      error.value = e.message
    }
  }

  const load = async () => {
    loading.value = true
    await Promise.all([fetchSettings(), fetchStats()])
    loading.value = false
  }

  const { pause: stopInterval, resume: startInterval } = useIntervalFn(fetchStats, 30000, { immediate: false })

  watch(enabled, (val) => {
    if (val) startInterval()
    else stopInterval()
  }, { immediate: true })

  onUnmounted(stopInterval)

  return { settings, stats, loading, saving, error, statsStale, load, fetchStats, saveSettings, pause, resume }
}
