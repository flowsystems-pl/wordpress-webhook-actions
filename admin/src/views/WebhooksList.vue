<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { Plus, Pencil, Trash2, ScrollText, Power, PowerOff } from 'lucide-vue-next'
import { Button, Card, Badge, Switch } from '@/components/ui'
import api from '@/lib/api'
import { useHealthStats } from '@/composables/useHealthStats'

const { fetchStats: refreshHealthStats } = useHealthStats()

const router = useRouter()
const webhooks = ref([])
const loading = ref(true)
const error = ref(null)
const togglingId = ref(null)

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

const deleteWebhook = async (webhook) => {
  if (!confirm(`Are you sure you want to delete "${webhook.name}"?`)) {
    return
  }

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
            </div>

            <p class="text-xs sm:text-sm text-muted-foreground font-mono truncate mb-2">
              {{ webhook.endpoint_url }}
            </p>

            <div class="flex flex-wrap gap-1">
              <Badge
                v-for="trigger in webhook.triggers"
                :key="trigger"
                variant="outline"
                class="text-xs break-all sm:break-normal"
              >
                {{ trigger }}
              </Badge>
            </div>
          </div>

          <div class="flex items-center gap-1 sm:gap-2 pt-2 sm:pt-0 border-t sm:border-t-0 border-border sm:ml-4">
            <Switch
              :model-value="webhook.is_enabled"
              :loading="togglingId === webhook.id"
              @update:model-value="toggleWebhook(webhook)"
            />
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
