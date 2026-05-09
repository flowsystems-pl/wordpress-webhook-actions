import { ref, isRef } from 'vue'
import { api } from '../lib/api'

const toVal = (v) => (isRef(v) ? v.value : v)

export function useSnippets() {
  const snippets = ref([])
  const loading = ref(false)
  const error = ref(null)

  const fetchSnippets = async (search = '', tags = []) => {
    loading.value = true
    error.value = null
    try {
      const params = {}
      if (search) params.search = search
      if (tags.length) params['tags[]'] = tags
      snippets.value = await api.snippets.list(params)
    } catch (e) {
      if (e.data?.status !== 404) {
        error.value = e.message || 'Failed to fetch snippets'
      }
      snippets.value = []
    } finally {
      loading.value = false
    }
  }

  const createSnippet = async (data) => {
    return await api.snippets.create(data)
  }

  const updateSnippet = async (id, data) => {
    return await api.snippets.update(id, data)
  }

  const deleteSnippet = async (id) => {
    return await api.snippets.delete(id)
  }

  const previewSnippet = async (code, payload, mode = 'pre', postContext = null) => {
    const body = { code, payload, mode }
    if (postContext) body.postContext = postContext
    return await api.snippets.preview(body)
  }

  return {
    snippets,
    loading,
    error,
    fetchSnippets,
    createSnippet,
    updateSnippet,
    deleteSnippet,
    previewSnippet,
  }
}

export function useTriggerSnippet(webhookId, trigger) {
  const assignment = ref(null)
  const loading = ref(false)
  const saving = ref(false)
  const error = ref(null)

  const fetch = async () => {
    const wid = toVal(webhookId)
    const trg = toVal(trigger)
    if (!wid || !trg) return
    loading.value = true
    error.value = null
    try {
      assignment.value = await api.snippets.getTriggerSnippet(wid, trg)
    } catch (e) {
      if (e.data?.status !== 404) {
        error.value = e.message || 'Failed to fetch trigger snippet'
      }
      assignment.value = null
    } finally {
      loading.value = false
    }
  }

  const save = async (data) => {
    const wid = toVal(webhookId)
    const trg = toVal(trigger)
    saving.value = true
    error.value = null
    try {
      assignment.value = await api.snippets.saveTriggerSnippet(wid, trg, data)
      return true
    } catch (e) {
      error.value = e.message || 'Failed to save trigger snippet'
      return false
    } finally {
      saving.value = false
    }
  }

  return {
    assignment,
    loading,
    saving,
    error,
    fetch,
    save,
  }
}
