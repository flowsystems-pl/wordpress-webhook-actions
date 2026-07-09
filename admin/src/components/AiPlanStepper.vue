<script setup>
import { ref, watch } from 'vue';
import { CheckCircle2, XCircle, AlertCircle, Circle, Loader2, ChevronDown, Undo2 } from 'lucide-vue-next';
import { shortLabel } from '@/lib/aiLabels';
import { __ } from '@/i18n';

// Compact vertical progress list for the aside. Shows a short, laconic name per
// step; clicking a row reveals the full detail (the model's summary) in an
// accordion and focuses that step in the main panel. Steps animate as they run
// (ping ring), complete (icon pop + green row flash) and enter (staggered slide).
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

function isRunning(i) {
  return props.running && isActive(i) && props.steps[i]?.status === 'pending';
}

function toggle(i) {
  openIndex.value = openIndex.value === i ? null : i;
  emit('select', i);
}

// One-shot green flash on the row whose status just flipped to done. Tracked as
// a transition (previous status → done) so restored builds don't flash on load.
const flashIndex = ref(null);
let prevStatuses = [];
watch(
  () => props.steps.map((s) => s.status),
  (now) => {
    const i = now.findIndex((s, idx) => s === 'done' && prevStatuses[idx] && prevStatuses[idx] !== 'done');
    if (i !== -1) {
      flashIndex.value = null; // restart the CSS animation if it re-fires quickly
      requestAnimationFrame(() => (flashIndex.value = i));
      setTimeout(() => {
        if (flashIndex.value === i) flashIndex.value = null;
      }, 1300);
    }
    prevStatuses = now;
  },
  { immediate: true }
);
</script>

<template>
  <div>
    <div class="text-xs font-semibold text-muted-foreground uppercase tracking-wide mb-2 px-1">
      {{ __('Build progress') }}
    </div>
    <ol class="space-y-0.5">
      <li v-for="(step, i) in steps" :key="step.id"
        class="fswa-step-enter" :style="{ animationDelay: `${i * 60}ms` }">
        <button
          @click="toggle(i)"
          :class="['w-full text-left flex items-center gap-2 rounded-md px-2 py-2 transition-colors',
            flashIndex === i && 'fswa-flash',
            i === selected ? 'bg-primary/10' : 'hover:bg-muted']">
          <span class="shrink-0 relative">
            <span v-if="isRunning(i)"
              class="absolute inset-0 rounded-full bg-primary/40 animate-ping" aria-hidden="true"></span>
            <Transition name="fswa-pop" mode="out-in">
              <span :key="step.status + ':' + isRunning(i)" class="relative block">
                <CheckCircle2 v-if="step.status === 'done'" class="w-4 h-4 text-emerald-500" />
                <Loader2 v-else-if="isRunning(i)" class="w-4 h-4 animate-spin text-primary" />
                <XCircle v-else-if="step.status === 'failed'" class="w-4 h-4 text-destructive" />
                <AlertCircle v-else-if="step.status === 'blocked_input' || step.status === 'blocked_prereq' || step.status === 'blocked_probe' || step.status === 'needs_confirm'" class="w-4 h-4 text-amber-500" />
                <Undo2 v-else-if="step.status === 'reverted'" class="w-4 h-4 text-muted-foreground" />
                <Circle v-else :class="['w-4 h-4', isActive(i) ? 'text-primary' : 'text-muted-foreground/40']" />
              </span>
            </Transition>
          </span>
          <span :class="['flex-1 min-w-0 text-sm truncate',
            isActive(i) ? 'font-medium text-foreground' : step.status === 'done' ? 'text-muted-foreground' : 'text-foreground']">
            {{ shortLabel(step.ability) }}
          </span>
          <span v-if="step.reused" class="shrink-0 rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
            {{ __('reused') }}
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

<style scoped>
/* Staggered entrance when a fresh plan appears (rows are keyed by step id). */
.fswa-step-enter {
  animation: fswa-slide-in 0.35s ease-out backwards;
}
@keyframes fswa-slide-in {
  0% { opacity: 0; transform: translateX(-8px); }
  100% { opacity: 1; transform: translateX(0); }
}

/* Springy pop-in when a step's status icon changes (spinner → green check). */
.fswa-pop-enter-active {
  animation: fswa-pop 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.fswa-pop-leave-active {
  transition: opacity 0.12s ease, transform 0.12s ease;
}
.fswa-pop-leave-to {
  opacity: 0;
  transform: scale(0.6);
}
@keyframes fswa-pop {
  0% { transform: scale(0.3); opacity: 0; }
  100% { transform: scale(1); opacity: 1; }
}

/* One-shot celebratory flash on the row that just completed. */
.fswa-flash {
  animation: fswa-flash 1.25s ease-out;
}
@keyframes fswa-flash {
  0% { background-color: rgb(16 185 129 / 0.3); }
  100% { background-color: transparent; }
}
</style>
