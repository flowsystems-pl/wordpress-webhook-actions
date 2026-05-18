<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { Plus, Pencil, Trash2, ScrollText, FlaskConical, Copy, Check, Zap, Network, Unlink } from 'lucide-vue-next'
import { Button, Card, Badge, Switch, Dialog, Checkbox, Input, Label } from '@/components/ui'
import TestWebhookDrawer from '@/components/TestWebhookDrawer.vue'
import WebhookCardContent from '@/components/WebhookCardContent.vue'
import api from '@/lib/api'
import { useHealthStats } from '@/composables/useHealthStats'
import { useCopyToClipboard } from '@/composables/useCopyToClipboard'
import { useSyncWarning } from '@/composables/useSyncWarning'
import { useChains } from '@/composables/useChains'

const { fetchStats: refreshHealthStats } = useHealthStats()
const { copiedKey, copy } = useCopyToClipboard()
const { dontShowAgain, isWarningDismissed, applyDismiss, resetDontShowAgain } = useSyncWarning()
const { chains, fetchChains, refresh: refreshChains, updateChain, deleteChain } = useChains()

const router = useRouter()
const webhooks = ref([])
const loading = ref(true)
const error = ref(null)
const togglingId = ref(null)
const togglingSync = ref(null)
const pendingDeleteWebhook = ref(null)
const pendingSyncWebhook = ref(null)
const pendingDeleteChain = ref(null)
const pendingRenameChain = ref(null)
const renameInput = ref('')
const renameError = ref('')
const renaming = ref(false)
const testWebhook = ref(null)

const loadWebhooks = async () => {
  loading.value = true
  error.value = null
  try {
    const [list] = await Promise.all([api.webhooks.list(), fetchChains()])
    webhooks.value = list
  } catch (e) {
    error.value = e.message
    console.error('Failed to load webhooks:', e)
  } finally {
    loading.value = false
  }
}

// ── computed: chain grouping + orphan detection ──────────────────────────────

const wpTriggers = (webhook) =>
  (webhook?.triggers || []).filter((t) => !String(t).startsWith('fswa_chain_link:'))

const chainLinkCount = (webhook) =>
  (webhook?.triggers || []).filter((t) => String(t).startsWith('fswa_chain_link:')).length

const isOrphan = (webhook) =>
  wpTriggers(webhook).length === 0 && chainLinkCount(webhook) === 0

const webhookById = computed(() => {
  const map = new Map()
  for (const w of webhooks.value) map.set(Number(w.id), w)
  return map
})

const chainGroups = computed(() => {
  const groups = []
  const involved = new Set()
  for (const chain of chains.value) {
    const memberIds = new Set()
    for (const l of (chain.links || [])) {
      memberIds.add(Number(l.source_webhook_id))
      memberIds.add(Number(l.target_webhook_id))
    }
    const members = []
    for (const id of memberIds) {
      const w = webhookById.value.get(id)
      if (w) {
        members.push(w)
        involved.add(id)
      }
    }
    if (members.length) {
      groups.push({ chain, webhooks: members })
    }
  }
  return groups
})

const involvedIds = computed(() => {
  const ids = new Set()
  for (const g of chainGroups.value) {
    for (const w of g.webhooks) ids.add(Number(w.id))
  }
  return ids
})

const unchainedWebhooks = computed(() =>
  webhooks.value.filter((w) => !involvedIds.value.has(Number(w.id)))
)

// Chains the about-to-delete webhook participates in (computed in the modal)
const pendingDeleteChainImpact = computed(() => {
  const id = pendingDeleteWebhook.value?.id
  if (!id) return []
  const target = Number(id)
  return chains.value
    .filter((c) =>
      (c.links || []).some(
        (l) =>
          Number(l.source_webhook_id) === target ||
          Number(l.target_webhook_id) === target
      )
    )
    .map((c) => {
      const links = (c.links || []).filter(
        (l) =>
          Number(l.source_webhook_id) === target ||
          Number(l.target_webhook_id) === target
      )
      return { id: c.id, name: c.name, linkCount: links.length }
    })
})

const toggleWebhook = async (webhook) => {
  togglingId.value = webhook.id
  try {
    const updated = await api.webhooks.toggle(webhook.id)
    const index = webhooks.value.findIndex((w) => w.id === webhook.id)
    if (index !== -1) {
      webhooks.value[index] = updated
    }
  } catch (e) {
    console.error('Failed to toggle webhook:', e)
  } finally {
    togglingId.value = null
  }
}

const toggleSynchronous = async (webhook) => {
  if (webhook.is_synchronous) {
    togglingSync.value = webhook.id
    try {
      const updated = await api.webhooks.update(webhook.id, { is_synchronous: false })
      const index = webhooks.value.findIndex((w) => w.id === webhook.id)
      if (index !== -1) webhooks.value[index] = updated
    } catch (e) {
      console.error('Failed to update webhook:', e)
    } finally {
      togglingSync.value = null
    }
  } else if (isWarningDismissed()) {
    togglingSync.value = webhook.id
    try {
      const updated = await api.webhooks.update(webhook.id, { is_synchronous: true })
      const index = webhooks.value.findIndex((w) => w.id === webhook.id)
      if (index !== -1) webhooks.value[index] = updated
    } catch (e) {
      console.error('Failed to update webhook:', e)
    } finally {
      togglingSync.value = null
    }
  } else {
    pendingSyncWebhook.value = webhook
  }
}

const confirmToggleSync = async () => {
  const webhook = pendingSyncWebhook.value
  if (!webhook) return
  applyDismiss()
  pendingSyncWebhook.value = null
  togglingSync.value = webhook.id
  try {
    const updated = await api.webhooks.update(webhook.id, { is_synchronous: true })
    const index = webhooks.value.findIndex((w) => w.id === webhook.id)
    if (index !== -1) webhooks.value[index] = updated
  } catch (e) {
    console.error('Failed to update webhook:', e)
  } finally {
    togglingSync.value = null
  }
}

const deleteWebhook = (webhook) => {
  pendingDeleteWebhook.value = webhook
}

// ── chain actions ────────────────────────────────────────────────────────────

const openRenameChain = (chain) => {
  pendingRenameChain.value = chain
  renameInput.value = chain.name || ''
  renameError.value = ''
}

const confirmRenameChain = async () => {
  const chain = pendingRenameChain.value
  if (!chain || renaming.value) return
  const newName = (renameInput.value || '').trim()
  if (!newName) {
    renameError.value = 'Name is required.'
    return
  }
  if (newName === chain.name) {
    pendingRenameChain.value = null
    return
  }
  renaming.value = true
  renameError.value = ''
  try {
    await updateChain(chain.id, { name: newName })
    pendingRenameChain.value = null
  } catch (e) {
    renameError.value = e?.message || 'Failed to rename chain.'
  } finally {
    renaming.value = false
  }
}

const openDeleteChain = (chain) => {
  pendingDeleteChain.value = chain
}

// Webhooks that would become orphans if the pendingDeleteChain were removed.
const chainDeleteOrphans = computed(() => {
  const chain = pendingDeleteChain.value
  if (!chain) return []
  const linksToRemoveByTarget = {}
  for (const l of chain.links || []) {
    const tid = Number(l.target_webhook_id)
    linksToRemoveByTarget[tid] = (linksToRemoveByTarget[tid] || 0) + 1
  }
  const orphans = []
  for (const [tidStr, removeCount] of Object.entries(linksToRemoveByTarget)) {
    const tid = Number(tidStr)
    const w = webhooks.value.find((x) => Number(x.id) === tid)
    if (!w) continue
    const remainingTriggers = wpTriggers(w).length + (chainLinkCount(w) - removeCount)
    if (remainingTriggers <= 0) orphans.push(w)
  }
  return orphans
})

const confirmDeleteChain = async () => {
  const chain = pendingDeleteChain.value
  if (!chain) return
  pendingDeleteChain.value = null
  try {
    await deleteChain(chain.id)
    // Reload webhooks so newly-orphaned targets re-render with no triggers
    await loadWebhooks()
    refreshHealthStats()
  } catch (e) {
    console.error('Failed to delete chain:', e)
  }
}

const confirmDeleteWebhook = async () => {
  const webhook = pendingDeleteWebhook.value
  if (!webhook) return
  pendingDeleteWebhook.value = null
  try {
    await api.webhooks.delete(webhook.id)
    webhooks.value = webhooks.value.filter((w) => w.id !== webhook.id)
    await refreshChains()
    // Re-fetch webhooks so any newly-orphaned downstream webhooks render
    // their trigger lists (now without the synthetic chain trigger).
    await loadWebhooks()
    refreshHealthStats()
  } catch (e) {
    console.error('Failed to delete webhook:', e)
  }
}

onMounted(loadWebhooks)
</script>

<template>
  <div>
    <!-- Test Drawer -->
    <TestWebhookDrawer
      :open="!!testWebhook"
      :webhook="testWebhook"
      @close="testWebhook = null"
    />

    <!-- Delete Confirm Dialog -->
    <Dialog
      :open="!!pendingDeleteWebhook"
      :title="`Delete &quot;${pendingDeleteWebhook?.name}&quot;?`"
      description="This will permanently delete the webhook and all associated data. This action cannot be undone."
      @close="pendingDeleteWebhook = null"
    >
      <div v-if="pendingDeleteChainImpact.length" class="rounded-md border-l-4 border-accent bg-muted/40 px-3 py-2 space-y-1 text-sm">
        <div class="flex items-center gap-1.5 font-medium">
          <Network class="h-4 w-4 text-accent" />
          <span>This webhook participates in {{ pendingDeleteChainImpact.length === 1 ? 'a chain' : 'chains' }}:</span>
        </div>
        <ul class="list-disc list-inside text-muted-foreground">
          <li v-for="c in pendingDeleteChainImpact" :key="c.id">
            <strong class="text-foreground">{{ c.name }}</strong> — {{ c.linkCount }} {{ c.linkCount === 1 ? 'link' : 'links' }} will be removed
          </li>
        </ul>
        <p class="text-xs text-muted-foreground pt-1">
          Downstream webhooks that lose their only trigger will be marked as orphans (no trigger assigned).
        </p>
      </div>
      <template #footer>
        <div class="flex gap-2">
          <Button variant="destructive" @click="confirmDeleteWebhook">Delete</Button>
          <Button variant="outline" @click="pendingDeleteWebhook = null">Cancel</Button>
        </div>
      </template>
    </Dialog>

    <!-- Rename Chain Dialog -->
    <Dialog
      :open="!!pendingRenameChain"
      title="Rename chain"
      @close="pendingRenameChain = null"
    >
      <div class="space-y-2">
        <Label for="fswa-chain-rename-input">Chain name</Label>
        <Input
          id="fswa-chain-rename-input"
          v-model="renameInput"
          placeholder="My chain"
          :disabled="renaming"
          :class="{ 'border-destructive': renameError }"
          @keyup.enter="confirmRenameChain"
        />
        <p v-if="renameError" class="text-sm text-destructive">{{ renameError }}</p>
      </div>
      <template #footer>
        <div class="flex gap-2">
          <Button variant="default" :disabled="renaming" @click="confirmRenameChain">
            {{ renaming ? 'Saving…' : 'Save' }}
          </Button>
          <Button variant="outline" :disabled="renaming" @click="pendingRenameChain = null">Cancel</Button>
        </div>
      </template>
    </Dialog>

    <!-- Delete Chain Confirm Dialog -->
    <Dialog
      :open="!!pendingDeleteChain"
      :title="`Delete chain &quot;${pendingDeleteChain?.name}&quot;?`"
      description="The chain and all its links will be removed. Member webhooks stay, but lose their chain-link triggers."
      @close="pendingDeleteChain = null"
    >
      <div v-if="chainDeleteOrphans.length" class="rounded-md border-l-4 border-accent bg-muted/40 px-3 py-2 space-y-1 text-sm">
        <div class="flex items-center gap-1.5 font-medium">
          <Unlink class="h-4 w-4 text-destructive" />
          <span>
            {{ chainDeleteOrphans.length === 1 ? 'This webhook will become an orphan' : 'These webhooks will become orphans' }}:
          </span>
        </div>
        <ul class="list-disc list-inside text-muted-foreground">
          <li v-for="w in chainDeleteOrphans" :key="w.id">
            <strong class="text-foreground">{{ w.name }}</strong> — no remaining triggers
          </li>
        </ul>
        <p class="text-xs text-muted-foreground pt-1">
          They will move to the Unchained section with “No trigger assigned”.
        </p>
      </div>
      <template #footer>
        <div class="flex gap-2">
          <Button variant="destructive" @click="confirmDeleteChain">Delete chain</Button>
          <Button variant="outline" @click="pendingDeleteChain = null">Cancel</Button>
        </div>
      </template>
    </Dialog>

    <!-- Sync Warning Dialog -->
    <Dialog
      :open="!!pendingSyncWebhook"
      title="Enable Synchronous Execution?"
      @close="() => { pendingSyncWebhook = null; resetDontShowAgain() }"
    >
      <div class="space-y-2 text-sm text-muted-foreground">
        <p>
          This webhook will fire inline during the WordPress request that triggers it, bypassing the queue.
          Slow or unreachable endpoints can <strong class="text-foreground">delay page loads, form submissions, and other frontend interactions.</strong>
        </p>
        <p>
          The <strong class="text-foreground">recommended approach is asynchronous delivery</strong> via the built-in system cron or an external cron job.
        </p>
      </div>
      <label class="flex items-center gap-2 cursor-pointer select-none">
        <Checkbox v-model="dontShowAgain" />
        <span class="text-sm text-muted-foreground">Don't show this again</span>
      </label>
      <template #footer>
        <div class="flex gap-2">
          <Button variant="destructive" @click="confirmToggleSync">Enable Anyway</Button>
          <Button variant="outline" @click="() => { pendingSyncWebhook = null; resetDontShowAgain() }">Cancel</Button>
        </div>
      </template>
    </Dialog>

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
      <div>
        <h2 class="text-xl font-semibold">Webhooks</h2>
        <p class="text-muted-foreground text-sm">Manage your webhook endpoints</p>
      </div>
      <Button @click="router.push('/webhooks/new')" class="self-start sm:self-auto">
        <Plus class="mr-2 h-4 w-4" />
        Add Webhook
      </Button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="text-center py-8 text-muted-foreground">
      Loading webhooks...
    </div>

    <!-- Error -->
    <div v-else-if="error" class="text-center py-8 text-destructive">
      {{ error }}
    </div>

    <!-- Empty -->
    <Card v-else-if="webhooks.length === 0" class="p-8 text-center">
      <p class="text-muted-foreground mb-4">No webhooks configured yet</p>
      <Button @click="router.push('/webhooks/new')">
        <Plus class="mr-2 h-4 w-4" />
        Create your first webhook
      </Button>
    </Card>

    <!-- List -->
    <div v-else class="space-y-5">
      <!-- Chain groups -->
      <div
        v-for="group in chainGroups"
        :key="`chain-${group.chain.id}`"
        class="rounded-lg border-l-4 border-accent bg-muted/20 pl-4 pr-2 py-3 space-y-2"
      >
        <div class="flex items-center gap-2">
          <Network class="h-4 w-4 text-accent shrink-0" />
          <h3 class="text-sm font-semibold">{{ group.chain.name }}</h3>
          <Badge variant="secondary" class="text-xs">
            {{ group.webhooks.length }} {{ group.webhooks.length === 1 ? 'webhook' : 'webhooks' }}
          </Badge>
          <div class="ml-auto flex items-center gap-1">
            <Button
              variant="ghost"
              size="icon"
              class="h-7 w-7"
              title="Rename chain"
              aria-label="Rename chain"
              @click="openRenameChain(group.chain)"
            >
              <Pencil class="h-3.5 w-3.5" />
            </Button>
            <Button
              variant="ghost"
              size="icon"
              class="h-7 w-7 text-destructive hover:text-destructive"
              title="Delete chain"
              aria-label="Delete chain"
              @click="openDeleteChain(group.chain)"
            >
              <Trash2 class="h-3.5 w-3.5" />
            </Button>
          </div>
        </div>
        <p v-if="group.chain.description" class="text-xs text-muted-foreground">{{ group.chain.description }}</p>
        <div class="space-y-3 sm:space-y-4">
          <Card
            v-for="webhook in group.webhooks"
            :key="`g${group.chain.id}-w${webhook.id}`"
            class="p-3 sm:p-4"
          >
            <WebhookCardContent
              :webhook="webhook"
              :is-orphan="isOrphan(webhook)"
              :wp-triggers="wpTriggers(webhook)"
              :toggling-id="togglingId"
              :toggling-sync="togglingSync"
              :copied-key="copiedKey"
              @copy="copy"
              @toggle="toggleWebhook"
              @toggle-sync="toggleSynchronous"
              @logs="router.push(`/webhooks/${webhook.id}/logs`)"
              @test="testWebhook = $event"
              @edit="router.push(`/webhooks/${webhook.id}`)"
              @delete="deleteWebhook"
            />
          </Card>
        </div>
      </div>

      <!-- Unchained section -->
      <div v-if="unchainedWebhooks.length" class="space-y-3 sm:space-y-4">
        <div v-if="chainGroups.length" class="flex items-center gap-2">
          <h3 class="text-sm font-semibold text-muted-foreground">Unchained</h3>
        </div>
        <Card
          v-for="webhook in unchainedWebhooks"
          :key="`u${webhook.id}`"
          class="p-3 sm:p-4"
        >
          <WebhookCardContent
            :webhook="webhook"
            :is-orphan="isOrphan(webhook)"
            :wp-triggers="wpTriggers(webhook)"
            :toggling-id="togglingId"
            :toggling-sync="togglingSync"
            :copied-key="copiedKey"
            @copy="copy"
            @toggle="toggleWebhook"
            @toggle-sync="toggleSynchronous"
            @logs="router.push(`/webhooks/${webhook.id}/logs`)"
            @test="testWebhook = $event"
            @edit="router.push(`/webhooks/${webhook.id}`)"
            @delete="deleteWebhook"
          />
        </Card>
      </div>
    </div>
  </div>
</template>
