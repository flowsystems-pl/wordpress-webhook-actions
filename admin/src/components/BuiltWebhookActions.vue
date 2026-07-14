<script setup>
// Post-build action row for the AI Builder: enable the (disabled) webhook, flip
// its delivery mode (async/sync), open it, or undo the last change. Rendered in
// both the focused and the summary "build complete" panels, so it lives here as
// the single source of truth. Purely presentational — the parent owns the state
// and handlers and reacts to the emitted events.
import { RouterLink } from 'vue-router';
import { Power, ExternalLink, Undo2, Info } from 'lucide-vue-next';
import { Button, Switch, Tooltip } from '@/components/ui';
import { __ } from '@/i18n';

defineProps({
  webhookId: { type: [Number, String], default: null },
  enabled: { type: Boolean, default: null },   // null = unknown / not fetched
  justEnabled: { type: Boolean, default: false },
  enabling: { type: Boolean, default: false },
  sync: { type: Boolean, default: null },       // null = unknown; true = synchronous
  savingSync: { type: Boolean, default: false },
  syncTooltip: { type: String, default: '' },
  hasRevertible: { type: Boolean, default: false },
  running: { type: Boolean, default: false },
});

defineEmits(['enable', 'toggle-sync', 'revert']);
</script>

<template>
  <Button v-if="enabled === false" size="sm" :disabled="enabling" @click="$emit('enable')">
    <Power class="w-4 h-4 mr-1.5" /> {{ __('Enable webhook') }}
  </Button>
  <span v-else-if="justEnabled" class="flex items-center gap-1.5 text-sm text-emerald-600 dark:text-emerald-400">
    <Power class="w-4 h-4" /> {{ __('Webhook is live.') }}
  </span>

  <div v-if="sync !== null" class="flex items-center gap-2 text-sm">
    <Switch :model-value="sync" :loading="savingSync" @update:model-value="$emit('toggle-sync', $event)" />
    <span class="text-muted-foreground">{{ sync ? __('Synchronous delivery') : __('Asynchronous delivery') }}</span>
    <Tooltip :content="syncTooltip">
      <Info class="w-3.5 h-3.5 text-muted-foreground cursor-help" />
    </Tooltip>
  </div>

  <RouterLink v-if="webhookId" :to="{ name: 'WebhookEdit', params: { id: webhookId } }">
    <Button size="sm" variant="outline">
      <ExternalLink class="w-4 h-4 mr-1.5" /> {{ __('Open webhook') }}
    </Button>
  </RouterLink>

  <Button v-if="hasRevertible" size="sm" variant="outline" :disabled="running" @click="$emit('revert')">
    <Undo2 class="w-4 h-4 mr-1.5" /> {{ __('Undo last change') }}
  </Button>
</template>
