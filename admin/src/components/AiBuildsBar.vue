<script setup>
import { Plus, Trash2 } from 'lucide-vue-next';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/components/ui';
import { __ } from '@/i18n';

// Builds bar above the conversation window: switcher (once there is more than
// one build), delete-current, and "New build". Extracted from AiBuilderView.
const props = defineProps({
  conversations: { type: Array, default: () => [] },
  activeId: { type: [Number, String], default: null },
});

const emit = defineEmits(['switch', 'delete', 'new']);
</script>

<template>
  <div class="flex flex-wrap items-center gap-2">
    <template v-if="conversations.length > 1">
      <label class="text-xs font-medium text-muted-foreground">{{ __('Your builds') }}</label>
      <div class="flex-1 min-w-56 max-w-2xl">
        <Select :model-value="String(activeId ?? '')" @update:model-value="(v) => emit('switch', v)">
          <SelectTrigger><SelectValue :placeholder="__('Your builds')" /></SelectTrigger>
          <SelectContent>
            <SelectItem v-for="c in conversations" :key="c.id" :value="String(c.id)">
              {{ c.title || __('Untitled build') }}
            </SelectItem>
          </SelectContent>
        </Select>
      </div>
    </template>
    <button v-if="activeId" @click="emit('delete')" :title="__('Delete this build')"
      class="p-2 rounded-md text-muted-foreground hover:text-destructive hover:bg-muted shrink-0">
      <Trash2 class="w-4 h-4" />
    </button>
    <div class="flex-1"></div>
    <button @click="emit('new')"
      class="inline-flex items-center gap-2 rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted shrink-0">
      <Plus class="w-4 h-4" /> {{ __('New build') }}
    </button>
  </div>
</template>
