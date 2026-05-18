import { ref, computed } from 'vue'
import { api } from '../lib/api'

const chains = ref([])
const loading = ref(false)
const error = ref(null)
let fetchPromise = null

async function fetchChains(force = false) {
  if (!force && fetchPromise) return fetchPromise
  loading.value = true
  error.value = null
  fetchPromise = (async () => {
    try {
      chains.value = await api.chains.list()
    } catch (e) {
      error.value = e.message || 'Failed to fetch chains'
      chains.value = []
    } finally {
      loading.value = false
    }
  })()
  return fetchPromise
}

function refresh() {
  fetchPromise = null
  return fetchChains(true)
}

async function createChain(data) {
  const created = await api.chains.create(data)
  await refresh()
  return created
}

async function updateChain(id, data) {
  const updated = await api.chains.update(id, data)
  await refresh()
  return updated
}

async function deleteChain(id) {
  await api.chains.delete(id)
  await refresh()
}

async function createLink(chainId, sourceWebhookId, targetWebhookId) {
  const link = await api.chains.createLink(chainId, {
    source_webhook_id: sourceWebhookId,
    target_webhook_id: targetWebhookId,
  })
  await refresh()
  return link
}

async function deleteLink(chainId, linkId) {
  await api.chains.deleteLink(chainId, linkId)
  await refresh()
}

/**
 * Synchronize chain links for a target webhook within a single chain.
 * Adds links for source IDs not yet linked; removes links to sources that
 * were deselected. Useful when saving the WebhookForm chain config.
 *
 * @returns {Promise<{ added: number[], removed: number[] }>} link IDs touched
 */
async function syncTargetSources(chainId, targetWebhookId, desiredSourceIds) {
  await fetchChains()
  const chain = chains.value.find((c) => Number(c.id) === Number(chainId))
  const existing = (chain?.links || []).filter(
    (l) => Number(l.target_webhook_id) === Number(targetWebhookId)
  )
  const existingBySource = new Map(
    existing.map((l) => [Number(l.source_webhook_id), Number(l.id)])
  )
  const desired = new Set(desiredSourceIds.map(Number))

  const added = []
  const removed = []

  for (const sid of desired) {
    if (!existingBySource.has(sid)) {
      const link = await api.chains.createLink(chainId, {
        source_webhook_id: sid,
        target_webhook_id: targetWebhookId,
      })
      added.push(Number(link.id))
    }
  }
  for (const [sid, linkId] of existingBySource) {
    if (!desired.has(sid)) {
      await api.chains.deleteLink(chainId, linkId)
      removed.push(linkId)
    }
  }
  if (added.length || removed.length) {
    await refresh()
  }
  return { added, removed }
}

/**
 * Remove ALL chain links where the given webhook is the target.
 * Used when switching a webhook out of chain mode.
 */
async function clearTargetLinks(targetWebhookId) {
  await fetchChains()
  const toRemove = []
  for (const chain of chains.value) {
    for (const l of chain.links || []) {
      if (Number(l.target_webhook_id) === Number(targetWebhookId)) {
        toRemove.push({ chainId: Number(chain.id), linkId: Number(l.id) })
      }
    }
  }
  for (const { chainId, linkId } of toRemove) {
    await api.chains.deleteLink(chainId, linkId)
  }
  if (toRemove.length) await refresh()
  return toRemove.length
}

export function useChains() {
  return {
    chains,
    loading,
    error,
    fetchChains,
    refresh,
    createChain,
    updateChain,
    deleteChain,
    createLink,
    deleteLink,
    syncTargetSources,
    clearTargetLinks,
  }
}

/**
 * Compute, for a given webhook ID, the chains it belongs to (as source or target).
 */
export function useWebhookChainInvolvement(webhookId) {
  return computed(() => {
    const id = Number(webhookId?.value ?? webhookId)
    if (!id) return []
    return chains.value
      .filter((c) =>
        (c.links || []).some(
          (l) =>
            Number(l.source_webhook_id) === id ||
            Number(l.target_webhook_id) === id
        )
      )
      .map((c) => ({
        id: Number(c.id),
        name: c.name,
        description: c.description,
        is_target: (c.links || []).some(
          (l) => Number(l.target_webhook_id) === id
        ),
        is_source: (c.links || []).some(
          (l) => Number(l.source_webhook_id) === id
        ),
      }))
  })
}
