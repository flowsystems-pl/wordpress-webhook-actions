import { ref, computed, onMounted } from 'vue'
import { useChains } from './useChains'
import api from '../lib/api'

/**
 * Humanize `fswa_chain_link:N` trigger names into something readable:
 * `Chain: <chain name> ← <source webhook name>`.
 *
 * Returns:
 *   parseChainLink(triggerName) → linkId | null
 *   triggerLabel(triggerName) → null | { kind:'chain', chainName, sourceName, linkId, missing }
 *
 * On mount, fetches chains (shared via useChains) and webhooks (local cache).
 * Falls back to raw trigger name if either fetch fails.
 */
export function useChainTriggerLabel() {
  const { chains, fetchChains } = useChains()
  const webhookNamesById = ref(new Map())

  onMounted(async () => {
    fetchChains().catch(() => {})
    try {
      const list = await api.webhooks.list()
      const map = new Map()
      for (const w of list) map.set(Number(w.id), w.name)
      webhookNamesById.value = map
    } catch (e) {
      // Non-fatal — falls back to the raw trigger name.
    }
  })

  const chainLinkMeta = computed(() => {
    const m = new Map()
    for (const c of chains.value) {
      for (const l of c.links || []) {
        m.set(Number(l.id), {
          chainName: c.name,
          sourceWebhookId: Number(l.source_webhook_id),
        })
      }
    }
    return m
  })

  const parseChainLink = (triggerName) => {
    if (!triggerName) return null
    const match = String(triggerName).match(/^fswa_chain_link:(\d+)$/)
    return match ? Number(match[1]) : null
  }

  const triggerLabel = (triggerName) => {
    const linkId = parseChainLink(triggerName)
    if (linkId === null) return null
    const meta = chainLinkMeta.value.get(linkId)
    if (!meta) {
      return { kind: 'chain', chainName: null, sourceName: null, linkId, missing: true }
    }
    return {
      kind: 'chain',
      chainName: meta.chainName,
      sourceName: webhookNamesById.value.get(meta.sourceWebhookId) || `#${meta.sourceWebhookId}`,
      linkId,
      missing: false,
    }
  }

  return { chains, parseChainLink, triggerLabel }
}
