import { ref, computed } from 'vue'
import { api } from '../lib/api'

// Shared state per webhook (keyed by webhook ID)
const schemasCache = ref({})
const loadingState = ref({})
const errorState = ref({})
const userTriggersCache = ref(null)

export function useSchemas(webhookId) {
  const webhookIdRef = ref(webhookId)

  const loading = computed(() => loadingState.value[webhookIdRef.value] || false)
  const error = computed(() => errorState.value[webhookIdRef.value] || null)
  const schemas = computed(() => schemasCache.value[webhookIdRef.value] || [])

  const setLoading = (value) => {
    loadingState.value = { ...loadingState.value, [webhookIdRef.value]: value }
  }

  const setError = (value) => {
    errorState.value = { ...errorState.value, [webhookIdRef.value]: value }
  }

  const fetchSchemas = async () => {
    if (!webhookIdRef.value) return

    setLoading(true)
    setError(null)

    try {
      const result = await api.schemas.getByWebhook(webhookIdRef.value)
      schemasCache.value = {
        ...schemasCache.value,
        [webhookIdRef.value]: result || [],
      }
    } catch (e) {
      setError(e.message || 'Failed to fetch schemas')
    } finally {
      setLoading(false)
    }
  }

  const getSchema = async (triggerName) => {
    if (!webhookIdRef.value || !triggerName) return null

    try {
      return await api.schemas.get(webhookIdRef.value, triggerName)
    } catch (e) {
      console.error('Failed to get schema:', e)
      return null
    }
  }

  const updateSchema = async (triggerName, data) => {
    if (!webhookIdRef.value || !triggerName) return false

    try {
      const updated = await api.schemas.update(webhookIdRef.value, triggerName, data)

      // Update cache
      const currentSchemas = schemasCache.value[webhookIdRef.value] || []
      const index = currentSchemas.findIndex((s) => s.trigger_name === triggerName)

      if (index >= 0) {
        currentSchemas[index] = updated
      } else {
        currentSchemas.push(updated)
      }

      schemasCache.value = {
        ...schemasCache.value,
        [webhookIdRef.value]: [...currentSchemas],
      }

      return true
    } catch (e) {
      console.error('Failed to update schema:', e)
      throw e
    }
  }

  const deleteSchema = async (triggerName) => {
    if (!webhookIdRef.value || !triggerName) return false

    try {
      await api.schemas.delete(webhookIdRef.value, triggerName)

      // Remove from cache
      const currentSchemas = schemasCache.value[webhookIdRef.value] || []
      schemasCache.value = {
        ...schemasCache.value,
        [webhookIdRef.value]: currentSchemas.filter((s) => s.trigger_name !== triggerName),
      }

      return true
    } catch (e) {
      console.error('Failed to delete schema:', e)
      throw e
    }
  }

  const resetCapture = async (triggerName) => {
    if (!webhookIdRef.value || !triggerName) return false

    try {
      const result = await api.schemas.resetCapture(webhookIdRef.value, triggerName)

      // Update cache with the returned schema
      if (result.schema) {
        const currentSchemas = schemasCache.value[webhookIdRef.value] || []
        const index = currentSchemas.findIndex((s) => s.trigger_name === triggerName)

        if (index >= 0) {
          currentSchemas[index] = result.schema
          schemasCache.value = {
            ...schemasCache.value,
            [webhookIdRef.value]: [...currentSchemas],
          }
        }
      }

      return true
    } catch (e) {
      console.error('Failed to reset capture:', e)
      throw e
    }
  }

  const getSchemaForTrigger = (triggerName) => {
    const schemasList = schemas.value
    return schemasList.find((s) => s.trigger_name === triggerName) || null
  }

  const hasExamplePayload = (triggerName) => {
    const schema = getSchemaForTrigger(triggerName)
    return schema && schema.example_payload !== null
  }

  const hasMappingConfigured = (triggerName) => {
    const schema = getSchemaForTrigger(triggerName)
    return schema && schema.field_mapping !== null
  }

  const setWebhookId = (id) => {
    webhookIdRef.value = id
  }

  return {
    loading,
    error,
    schemas,
    fetchSchemas,
    getSchema,
    updateSchema,
    deleteSchema,
    resetCapture,
    getSchemaForTrigger,
    hasExamplePayload,
    hasMappingConfigured,
    setWebhookId,
  }
}

export function useUserTriggers() {
  const loading = ref(false)
  const error = ref(null)

  const userTriggers = computed(() => userTriggersCache.value || [])

  const fetchUserTriggers = async () => {
    if (userTriggersCache.value !== null) {
      return userTriggersCache.value
    }

    loading.value = true
    error.value = null

    try {
      const result = await api.schemas.getUserTriggers()
      userTriggersCache.value = result.triggers || []
      return userTriggersCache.value
    } catch (e) {
      error.value = e.message || 'Failed to fetch user triggers'
      return []
    } finally {
      loading.value = false
    }
  }

  const isUserTrigger = (triggerName) => {
    return userTriggers.value.includes(triggerName)
  }

  return {
    loading,
    error,
    userTriggers,
    fetchUserTriggers,
    isUserTrigger,
  }
}
