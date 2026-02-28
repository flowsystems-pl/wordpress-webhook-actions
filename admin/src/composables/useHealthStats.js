import { ref, computed, onMounted, onUnmounted } from 'vue'
import { api } from '../lib/api'

const stats = ref(null)
const loading = ref(false)
const error = ref(null)
let refreshInterval = null

export function useHealthStats() {
  const fetchStats = async () => {
    loading.value = true
    error.value = null
    try {
      stats.value = await api.health.stats()
    } catch (e) {
      error.value = e.message || 'Failed to fetch health stats'
    } finally {
      loading.value = false
    }
  }

  const startAutoRefresh = (intervalMs = 60000) => {
    stopAutoRefresh()
    refreshInterval = setInterval(fetchStats, intervalMs)
  }

  const stopAutoRefresh = () => {
    if (refreshInterval) {
      clearInterval(refreshInterval)
      refreshInterval = null
    }
  }

  const successRate = computed(() => stats.value?.success_rate ?? 0)

  const hasData = computed(() => stats.value?.has_data ?? false)

  const webhooks = computed(() => stats.value?.webhooks ?? { total: 0, active: 0 })

  const logs = computed(() => stats.value?.logs ?? {
    total: 0,
    total_all_time: 0,
    success: 0,
    error: 0,
    pending: 0,
    retry: 0,
  })

  const queue = computed(() => stats.value?.queue ?? {
    pending: 0,
    processing: 0,
    completed: 0,
    failed: 0,
    total: 0,
    due_now: 0,
  })

  const velocity = computed(() => stats.value?.velocity ?? {
    last_hour: 0,
    last_day: 0,
    avg_duration_ms: 0,
  })

  const observability = computed(() => stats.value?.observability ?? {
    avg_attempts_per_event: 0,
    oldest_pending_age_seconds: null,
    queue_stuck: false,
    wp_cron_only: false,
  })

  onMounted(() => {
    if (!stats.value) {
      fetchStats()
    }
    startAutoRefresh()
  })

  onUnmounted(() => {
    stopAutoRefresh()
  })

  return {
    stats,
    loading,
    error,
    fetchStats,
    startAutoRefresh,
    stopAutoRefresh,
    successRate,
    hasData,
    webhooks,
    logs,
    queue,
    velocity,
    observability,
  }
}
