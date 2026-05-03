<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { Plus, Pencil, Trash2, ScrollText, FlaskConical, Copy, Check, Zap } from 'lucide-vue-next'
import { Button, Card, Badge, Switch, Dialog, Checkbox } from '@/components/ui'
import TestWebhookDrawer from '@/components/TestWebhookDrawer.vue'
import api from '@/lib/api'
import { useHealthStats } from '@/composables/useHealthStats'
import { useCopyToClipboard } from '@/composables/useCopyToClipboard'
import { useSyncWarning } from '@/composables/useSyncWarning'

const { fetchStats: refreshHealthStats } = useHealthStats()
const { copiedKey, copy } = useCopyToClipboard()
const { dontShowAgain, isWarningDismissed, applyDismiss, resetDontShowAgain } = useSyncWarning()

const router = useRouter()
const webhooks = ref([])
const loading = ref(true)
const error = ref(null)
const togglingId = ref(null)
const togglingSync = ref(null)
const pendingDeleteWebhook = ref(null)
const pendingSyncWebhook = ref(null)
const testWebhook = ref(null)

const loadWebhooks = async () => {
  loading.value = true
  error.value = null
  try {
    webhooks.value = await api.webhooks.list()
  } catch (e) {
    error.value = e.message
    console.error('Failed to load webhooks:', e)
  } finally {
    loading.value = false
  }
}

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

const confirmDeleteWebhook = async () => {
  const webhook = pendingDeleteWebhook.value
  if (!webhook) return
  pendingDeleteWebhook.value = null
  try {
    await api.webhooks.delete(webhook.id)
    webhooks.value = webhooks.value.filter((w) => w.id !== webhook.id)
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
      <template #footer>
        <div class="flex gap-2">
          <Button variant="destructive" @click="confirmDeleteWebhook">Delete</Button>
          <Button variant="outline" @click="pendingDeleteWebhook = null">Cancel</Button>
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
    <div v-else class="space-y-3 sm:space-y-4">
      <Card
        v-for="webhook in webhooks"
        :key="webhook.id"
        class="p-3 sm:p-4"
      >
        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 sm:gap-3 mb-2 flex-wrap">
              <h3 class="font-medium text-sm sm:text-base">{{ webhook.name }}</h3>
              <Badge :variant="webhook.is_enabled ? 'success' : 'secondary'" class="text-xs">
                {{ webhook.is_enabled ? 'Active' : 'Disabled' }}
              </Badge>
              <Badge variant="outline" class="text-xs font-mono">
                {{ webhook.http_method || 'POST' }}
              </Badge>
              <Badge
                v-if="webhook.is_synchronous"
                variant="warning"
                class="text-xs cursor-help"
                title="Executes synchronously (blocking)"
              >
                Sync
              </Badge>
            </div>

            <div class="flex items-center gap-1 mb-2">
              <p class="text-xs sm:text-sm text-muted-foreground font-mono truncate">
                {{ webhook.endpoint_url }}
              </p>
              <button
                @click="copy(webhook.endpoint_url, `wh-url-${webhook.id}`)"
                class="shrink-0 rounded p-1 hover:bg-muted transition-colors"
                title="Copy endpoint URL"
              >
                <Check v-if="copiedKey === `wh-url-${webhook.id}`" class="h-3.5 w-3.5 text-green-500" />
                <Copy v-else class="h-3.5 w-3.5 text-muted-foreground" />
              </button>
            </div>

            <div class="flex items-center gap-1 mb-2">
              <span class="text-xs text-muted-foreground font-mono">X-Webhook-Id: {{ webhook.webhook_uuid }}</span>
              <button
                @click="copy(webhook.webhook_uuid, `wh-id-${webhook.id}`)"
                class="shrink-0 rounded p-1 hover:bg-muted transition-colors"
                title="Copy X-Webhook-Id"
              >
                <Check v-if="copiedKey === `wh-id-${webhook.id}`" class="h-3.5 w-3.5 text-green-500" />
                <Copy v-else class="h-3.5 w-3.5 text-muted-foreground" />
              </button>
            </div>

            <div class="flex flex-wrap gap-1">
              <div
                v-for="trigger in webhook.triggers"
                :key="trigger"
                class="inline-flex items-center gap-0.5"
              >
                <Badge
                  variant="outline"
                  class="text-xs break-all sm:break-normal"
                >
                  {{ trigger }}
                </Badge>
                <button
                  @click="copy(trigger, `wh-trigger-${webhook.id}-${trigger}`)"
                  class="shrink-0 rounded p-0.5 hover:bg-muted transition-colors"
                  title="Copy trigger name"
                >
                  <Check v-if="copiedKey === `wh-trigger-${webhook.id}-${trigger}`" class="h-3 w-3 text-green-500" />
                  <Copy v-else class="h-3 w-3 text-muted-foreground" />
                </button>
              </div>
            </div>
          </div>

          <div class="flex items-center gap-1 sm:gap-2 pt-2 sm:pt-0 border-t sm:border-t-0 border-border sm:ml-4">
            <Switch
              :model-value="webhook.is_enabled"
              :loading="togglingId === webhook.id"
              @update:model-value="toggleWebhook(webhook)"
            />
            <div
              class="flex items-center gap-1 border-l pl-2 ml-1"
              title="Synchronous execution"
            >
              <Zap class="h-3.5 w-3.5 text-muted-foreground shrink-0" />
              <Switch
                :model-value="webhook.is_synchronous"
                :loading="togglingSync === webhook.id"
                @update:model-value="toggleSynchronous(webhook)"
              />
            </div>
            <Button
              size="icon"
              variant="ghost"
              @click="router.push(`/webhooks/${webhook.id}/logs`)"
              title="View logs"
              class="h-8 w-8 sm:h-9 sm:w-9"
            >
              <ScrollText class="h-4 w-4" />
            </Button>
            <Button
              size="icon"
              variant="ghost"
              title="Test"
              class="h-8 w-8 sm:h-9 sm:w-9"
              @click="testWebhook = webhook"
            >
              <FlaskConical class="h-4 w-4" />
            </Button>
            <Button
              size="icon"
              variant="ghost"
              @click="router.push(`/webhooks/${webhook.id}`)"
              title="Edit"
              class="h-8 w-8 sm:h-9 sm:w-9"
            >
              <Pencil class="h-4 w-4" />
            </Button>
            <Button
              size="icon"
              variant="ghost"
              @click="deleteWebhook(webhook)"
              title="Delete"
              class="h-8 w-8 sm:h-9 sm:w-9"
            >
              <Trash2 class="h-4 w-4" />
            </Button>
          </div>
        </div>
      </Card>
    </div>
  </div>
</template>
