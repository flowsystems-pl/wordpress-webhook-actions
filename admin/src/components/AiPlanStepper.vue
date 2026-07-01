<script setup>
import { ref } from 'vue';
import { CheckCircle2, XCircle, AlertCircle, Circle, Loader2, ChevronDown } from 'lucide-vue-next';
import { shortLabel } from '@/lib/aiLabels';
import { __ } from '@/i18n';

// Compact vertical progress list for the aside. Shows a short, laconic name per
// step; clicking a row reveals the full detail (the model's summary) in an
// accordion and focuses that step in the main panel.
const props = defineProps({
  steps: { type: Array, default: () => [] },
  abilities: { type: Object, default: () => ({}) },
  cursor: { type: Number, default: 0 },
  finished: { type: Boolean, default: false },
  running: { type: Boolean, default: false },
  selected: { type: Number, default: 0 },
});

const emit = defineEmits(['select']);

const openIndex = ref(null);

function isActive(i) {
  return i === props.cursor && !props.finished;
}

function toggle(i) {
  openIndex.value = openIndex.value === i ? null : i;
  emit('select', i);
}
</script>

<template>
  <div>
    <div class="text-xs font-semibold text-muted-foreground uppercase tracking-wide mb-2 px-1">
      {{ __('Build progress') }}
    </div>
    <ol class="space-y-0.5">
      <li v-for="(step, i) in steps" :key="step.id">
        <button
          @click="toggle(i)"
          :class="['w-full text-left flex items-center gap-2 rounded-md px-2 py-2 transition-colors',
            i === selected ? 'bg-primary/10' : 'hover:bg-muted']">
          <span class="shrink-0">
            <CheckCircle2 v-if="step.status === 'done'" class="w-4 h-4 text-emerald-500" />
            <Loader2 v-else-if="running && isActive(i) && step.status === 'pending'" class="w-4 h-4 animate-spin text-primary" />
            <XCircle v-else-if="step.status === 'failed'" class="w-4 h-4 text-destructive" />
            <AlertCircle v-else-if="step.status === 'blocked_input' || step.status === 'blocked_prereq' || step.status === 'blocked_probe' || step.status === 'needs_confirm'" class="w-4 h-4 text-amber-500" />
            <Circle v-else :class="['w-4 h-4', isActive(i) ? 'text-primary' : 'text-muted-foreground/40']" />
          </span>
          <span :class="['flex-1 min-w-0 text-sm truncate',
            isActive(i) ? 'font-medium text-foreground' : step.status === 'done' ? 'text-muted-foreground' : 'text-foreground']">
            {{ shortLabel(step.ability) }}
          </span>
          <ChevronDown class="w-3.5 h-3.5 shrink-0 text-muted-foreground transition-transform"
            :class="openIndex === i && 'rotate-180'" />
        </button>

        <!-- Accordion: full detail for this step -->
        <div v-if="openIndex === i" class="pl-8 pr-2 pb-2 pt-0.5 text-xs text-muted-foreground">
          {{ step.summary }}
        </div>
      </li>
    </ol>
  </div>
</template>
