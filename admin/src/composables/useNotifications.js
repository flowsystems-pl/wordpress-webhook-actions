import { ref, computed } from 'vue'
import api from '@/lib/api'
import { __ } from '@/i18n'

// Shared, memoised catalog + channel list so the Notifications view, the
// webhook form card and the rule editor never fetch the same thing twice in
// a session. Call refreshChannels() after a channel write.
const channelTypes = ref([])
const events = ref([])
const defaults = ref({})
const channels = ref([])
const loaded = ref(false)
let catalogPromise = null
let channelsPromise = null

export const EVENT_HINTS = {
  success: () => __('Every successful delivery. Noisy on a busy site — combine with a throttle or a digest.'),
  recovered: () => __('A delivery that succeeded after at least one failed attempt.'),
  failed_attempt: () => __('Any single failed attempt, before retries are exhausted. Filter by attempt number or HTTP code.'),
  retry_scheduled: () => __('A failed attempt that will be retried, with the time of the next try.'),
  permanently_failed: () => __('The delivery gave up: out of attempts, or a non-retryable error such as a 4xx.'),
  skipped: () => __('The webhook did not run because its conditions were not met.'),
}

export const SEVERITY_CLASS = {
  success: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border-emerald-500/30',
  warning: 'bg-amber-500/10 text-amber-700 dark:text-amber-400 border-amber-500/30',
  critical: 'bg-red-500/10 text-red-700 dark:text-red-400 border-red-500/30',
  neutral: 'bg-muted text-muted-foreground border-border',
}

export const EVENT_SEVERITY = {
  success: 'success',
  recovered: 'success',
  failed_attempt: 'warning',
  retry_scheduled: 'warning',
  permanently_failed: 'critical',
  skipped: 'neutral',
}

export function useNotifications() {
  const loadCatalog = async () => {
    if (!catalogPromise) {
      catalogPromise = api.notifications.channelTypes().then((data) => {
        channelTypes.value = data.types || []
        events.value = data.events || []
        defaults.value = data.defaults || {}
        return data
      }).catch((e) => {
        catalogPromise = null
        throw e
      })
    }
    return catalogPromise
  }

  const loadChannels = async () => {
    if (!channelsPromise) {
      channelsPromise = api.notifications.channels.list().then((list) => {
        channels.value = list
        loaded.value = true
        return list
      }).catch((e) => {
        channelsPromise = null
        throw e
      })
    }
    return channelsPromise
  }

  const refreshChannels = async () => {
    channelsPromise = null
    return loadChannels()
  }

  const load = async () => Promise.all([loadCatalog(), loadChannels()])

  const typeByKey = computed(() => Object.fromEntries(channelTypes.value.map((t) => [t.type, t])))
  const channelById = computed(() => Object.fromEntries(channels.value.map((c) => [c.id, c])))
  const eventLabel = (key) => events.value.find((e) => e.key === key)?.label || key
  const typeLabel = (key) => typeByKey.value[key]?.label || key

  return {
    channelTypes,
    events,
    defaults,
    channels,
    loaded,
    typeByKey,
    channelById,
    load,
    loadCatalog,
    loadChannels,
    refreshChannels,
    eventLabel,
    typeLabel,
  }
}

export const formatThrottle = (seconds) => {
  if (!seconds) return __('none')
  if (seconds % 86400 === 0) return `${seconds / 86400} d`
  if (seconds % 3600 === 0) return `${seconds / 3600} h`
  if (seconds % 60 === 0) return `${seconds / 60} min`
  return `${seconds} s`
}

// Summarise a rule's filters for list rows.
export const describeFilters = (filters) => {
  const parts = []
  if (!filters) return parts
  if (filters.attempts?.length) parts.push(__('attempt') + ' ' + filters.attempts.join(', '))
  if (filters.http_codes?.length) parts.push(filters.http_codes.join(', '))
  if (filters.reason) parts.push(filters.reason === 'exhausted' ? __('out of attempts') : __('non-retryable'))
  if (filters.triggers?.length) parts.push(filters.triggers.join(', '))
  if (filters.only_after_retry) parts.push(__('only after a retry'))
  if (filters.include_tests) parts.push(__('incl. tests'))
  return parts
}
