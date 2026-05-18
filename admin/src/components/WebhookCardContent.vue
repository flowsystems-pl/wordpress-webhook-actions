<script setup>
import { Pencil, Trash2, ScrollText, FlaskConical, Copy, Check, Zap, Unlink } from 'lucide-vue-next';
import { Button, Badge, Switch } from '@/components/ui';

defineProps({
  webhook: { type: Object, required: true },
  isOrphan: Boolean,
  wpTriggers: { type: Array, default: () => [] },
  togglingId: { type: [Number, String, null], default: null },
  togglingSync: { type: [Number, String, null], default: null },
  copiedKey: { type: [String, null], default: null },
});

const emit = defineEmits(['copy', 'toggle', 'toggle-sync', 'logs', 'test', 'edit', 'delete']);
</script>

<template>
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
          @click="emit('copy', webhook.endpoint_url, `wh-url-${webhook.id}`)"
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
          @click="emit('copy', webhook.webhook_uuid, `wh-id-${webhook.id}`)"
          class="shrink-0 rounded p-1 hover:bg-muted transition-colors"
          title="Copy X-Webhook-Id"
        >
          <Check v-if="copiedKey === `wh-id-${webhook.id}`" class="h-3.5 w-3.5 text-green-500" />
          <Copy v-else class="h-3.5 w-3.5 text-muted-foreground" />
        </button>
      </div>

      <!-- Triggers / orphan badge -->
      <div v-if="isOrphan" class="inline-flex items-center gap-1.5 rounded-md border border-destructive/40 bg-destructive/5 px-2 py-1">
        <Unlink class="h-3.5 w-3.5 text-destructive" />
        <span class="text-xs font-medium text-destructive">No trigger assigned</span>
      </div>
      <div v-else class="flex flex-wrap gap-1">
        <div
          v-for="trigger in wpTriggers"
          :key="trigger"
          class="inline-flex items-center gap-0.5"
        >
          <Badge variant="outline" class="text-xs break-all sm:break-normal">
            {{ trigger }}
          </Badge>
          <button
            @click="emit('copy', trigger, `wh-trigger-${webhook.id}-${trigger}`)"
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
        @update:model-value="emit('toggle', webhook)"
      />
      <div class="flex items-center gap-1 border-l pl-2 ml-1" title="Synchronous execution">
        <Zap class="h-3.5 w-3.5 text-muted-foreground shrink-0" />
        <Switch
          :model-value="webhook.is_synchronous"
          :loading="togglingSync === webhook.id"
          @update:model-value="emit('toggle-sync', webhook)"
        />
      </div>
      <Button size="icon" variant="ghost" title="View logs" class="h-8 w-8 sm:h-9 sm:w-9" @click="emit('logs', webhook)">
        <ScrollText class="h-4 w-4" />
      </Button>
      <Button size="icon" variant="ghost" title="Test" class="h-8 w-8 sm:h-9 sm:w-9" @click="emit('test', webhook)">
        <FlaskConical class="h-4 w-4" />
      </Button>
      <Button size="icon" variant="ghost" title="Edit" class="h-8 w-8 sm:h-9 sm:w-9" @click="emit('edit', webhook)">
        <Pencil class="h-4 w-4" />
      </Button>
      <Button size="icon" variant="ghost" title="Delete" class="h-8 w-8 sm:h-9 sm:w-9" @click="emit('delete', webhook)">
        <Trash2 class="h-4 w-4" />
      </Button>
    </div>
  </div>
</template>
