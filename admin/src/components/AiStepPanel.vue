<script setup>
import { CheckCircle2, XCircle, AlertCircle, Circle, Loader2, ShieldAlert, Undo2, Play } from 'lucide-vue-next';
import { Button } from '@/components/ui';
import AiStepControls from '@/components/AiStepControls.vue';
import ChatMarkdown from '@/components/ChatMarkdown.vue';
import BuiltWebhookActions from '@/components/BuiltWebhookActions.vue';
import { abilityTitle } from '@/lib/aiLabels';
import { __ } from '@/i18n';

// The single focused plan step, extracted from AiBuilderView (the view stays
// the coordinator): animated status header, the step's PHP when it carries a
// Code Glue snippet, the active controls while it is the step being run, and
// the run / continue / finished bar.
const props = defineProps({
  step: { type: Object, required: true },
  stepNumber: { type: Number, default: 1 },
  stepCount: { type: Number, default: 1 },
  abilities: { type: Object, default: () => ({}) },
  credentials: { type: Array, default: () => [] },
  isCurrent: { type: Boolean, default: false },
  running: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  reviewPreRun: { type: Boolean, default: false },
  canContinue: { type: Boolean, default: false },
  finished: { type: Boolean, default: false },
  built: { type: Object, default: () => ({}) }, // BuiltWebhookActions prop bundle
});

const emit = defineEmits([
  'advance', 'confirm', 'retry', 'skip', 'probe-fix',
  'create-credential', 'provision-app-password',
  'enable', 'toggle-sync', 'revert',
]);
</script>

<template>
  <div class="rounded-lg border border-border bg-card p-5 space-y-4">
    <!-- Header -->
    <div class="flex items-start gap-3">
      <div class="mt-0.5 shrink-0 relative">
        <span v-if="running && isCurrent && step.status === 'pending'"
          class="absolute inset-0 rounded-full bg-primary/40 animate-ping" aria-hidden="true"></span>
        <Transition name="fswa-pop" mode="out-in">
          <span :key="step.status + ':' + (running && isCurrent)" class="relative block">
            <CheckCircle2 v-if="step.status === 'done'" class="w-5 h-5 text-emerald-500" />
            <Loader2 v-else-if="running && isCurrent && step.status === 'pending'" class="w-5 h-5 animate-spin text-primary" />
            <ShieldAlert v-else-if="step.status === 'needs_confirm'" class="w-5 h-5 text-amber-500" />
            <XCircle v-else-if="step.status === 'failed'" class="w-5 h-5 text-destructive" />
            <AlertCircle v-else-if="step.status === 'blocked_input' || step.status === 'blocked_prereq' || step.status === 'blocked_probe'" class="w-5 h-5 text-amber-500" />
            <Undo2 v-else-if="step.status === 'reverted'" class="w-5 h-5 text-muted-foreground" />
            <Circle v-else class="w-5 h-5 text-primary" />
          </span>
        </Transition>
      </div>
      <div class="min-w-0">
        <h3 class="text-base font-semibold text-foreground leading-snug">
          {{ step.summary || abilityTitle(abilities, step.ability) }}
        </h3>
        <p class="text-xs text-muted-foreground mt-0.5">
          {{ __('Step') }} {{ stepNumber }} {{ __('of') }} {{ stepCount }} · {{ abilityTitle(abilities, step.ability) }}
        </p>
      </div>
    </div>

    <!-- Snippet code: create_snippet / update_snippet / raw-code previews
         carry PHP in their input — show it so the user reviews the exact
         code the build will run, not just a one-line summary. -->
    <ChatMarkdown v-if="typeof step.input?.code === 'string' && step.input.code.trim()"
      :text="'```php\n' + step.input.code + '\n```'" />

    <!-- Active controls (only when this is the step being run) -->
    <AiStepControls
      v-if="isCurrent"
      :key="step.id + ':' + step.status"
      :step="step"
      :abilities="abilities"
      :credentials="credentials"
      :busy="busy"
      @continue="(patch) => emit('advance', { patch })"
      @confirm="emit('confirm')"
      @retry="emit('retry')"
      @skip="emit('skip')"
      @probe-fix="(fix) => emit('probe-fix', fix)"
      @create-credential="(payload) => emit('create-credential', payload)"
      @provision-app-password="emit('provision-app-password')"
    />

    <!-- Non-current step states -->
    <div v-else-if="step.status === 'done'" class="flex items-center gap-1.5 text-sm text-emerald-600 dark:text-emerald-400">
      <CheckCircle2 class="w-4 h-4" /> {{ __('Done') }}
    </div>
    <div v-else-if="step.status === 'skipped'" class="text-sm text-muted-foreground">{{ __('Skipped') }}</div>
    <div v-else-if="step.status === 'reverted'" class="flex items-center gap-1.5 text-sm text-muted-foreground"><Undo2 class="w-4 h-4" /> {{ __('Reverted') }}</div>
    <div v-else class="text-sm text-muted-foreground">{{ __('Waiting for earlier steps…') }}</div>

    <!-- Run / continue / finished -->
    <div v-if="reviewPreRun || canContinue || finished || running"
      class="flex items-center gap-2 pt-3 border-t border-border">
      <Button v-if="reviewPreRun" :disabled="running" @click="emit('advance')">
        <Play class="w-4 h-4 mr-1.5" /> {{ __('Run plan') }}
      </Button>
      <Button v-else-if="canContinue" :disabled="running" @click="emit('advance')">
        <Play class="w-4 h-4 mr-1.5" /> {{ __('Continue build') }}
      </Button>
      <template v-if="finished">
        <span class="flex items-center gap-2 text-sm text-emerald-600 dark:text-emerald-400">
          <CheckCircle2 class="w-4 h-4" /> {{ __('Build complete.') }}
        </span>
        <BuiltWebhookActions v-bind="built"
          @enable="emit('enable')" @toggle-sync="(v) => emit('toggle-sync', v)" @revert="emit('revert')" />
      </template>
      <span v-else-if="running" class="flex items-center gap-2 text-sm text-muted-foreground">
        <Loader2 class="w-4 h-4 animate-spin" /> {{ __('Working…') }}
      </span>
    </div>
  </div>
</template>

<style scoped>
/* Springy pop-in when the step's status icon changes (e.g. spinner → green check). */
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
</style>
