<script setup>
import { ref, onMounted } from 'vue';
import { Bug, RefreshCw, Trash2, FileText, MessageSquare, CornerDownLeft, Braces } from 'lucide-vue-next';
import { Switch } from '@/components/ui';
import { api } from '@/lib/api';

// Self-contained developer panel: inspects the raw input/output of every LLM
// call (system prompt, sent messages, raw response). Rendered only when the SPA
// runs from the Vite dev server (see AiBuilderView.vue). Toggling "Logging"
// flips the server-side fswa_ai_debug option, after which new calls are recorded.

const enabled = ref(false);
const entries = ref([]);
const open = ref(true);
const busy = ref(false);
const err = ref('');

async function refresh() {
  busy.value = true;
  err.value = '';
  try {
    const res = await api.agent.traces(50);
    enabled.value = res.enabled;
    entries.value = res.entries || [];
  } catch (e) {
    err.value = e.message;
  } finally {
    busy.value = false;
  }
}

async function toggle() {
  try {
    const res = await api.agent.setDebug(!enabled.value);
    enabled.value = res.enabled;
  } catch (e) {
    err.value = e.message;
  }
}

async function clearAll() {
  if (!confirm('Delete all stored AI traces?')) return;
  try {
    await api.agent.clearTraces();
    await refresh();
  } catch (e) {
    err.value = e.message;
  }
}

function time(ts) {
  try {
    return new Date(ts).toLocaleTimeString();
  } catch {
    return ts;
  }
}

function pretty(value) {
  try {
    return JSON.stringify(value, null, 2);
  } catch {
    return String(value);
  }
}

// Expose so the parent can refresh right after a message round-trips.
defineExpose({ refresh });

onMounted(refresh);
</script>

<template>
  <div class="rounded-lg border border-dashed border-amber-400/60 bg-amber-50/40 dark:bg-amber-950/20 mb-4">
    <!-- Header -->
    <div class="flex items-center justify-between gap-3 px-3 py-2">
      <button class="flex items-center gap-2 text-sm font-semibold text-amber-700 dark:text-amber-300" @click="open = !open">
        <Bug class="w-4 h-4" />
        AI Dev Trace
        <span class="text-xs font-normal text-amber-600/70 dark:text-amber-400/60">({{ entries.length }})</span>
      </button>
      <div class="flex items-center gap-1.5">
        <span class="flex items-center gap-1.5 text-xs text-amber-700 dark:text-amber-300 select-none">
          <Switch :model-value="enabled" @update:model-value="toggle" />
          Logging {{ enabled ? 'on' : 'off' }}
        </span>
        <button @click="refresh" :disabled="busy" title="Refresh"
          class="p-1 rounded text-amber-700 dark:text-amber-300 hover:bg-amber-200/40">
          <RefreshCw :class="['w-3.5 h-3.5', busy && 'animate-spin']" />
        </button>
        <button @click="clearAll" title="Clear all traces"
          class="p-1 rounded text-amber-700 dark:text-amber-300 hover:bg-amber-200/40">
          <Trash2 class="w-3.5 h-3.5" />
        </button>
      </div>
    </div>

    <div v-if="open" class="border-t border-amber-400/40 px-3 py-2 space-y-2">
      <p v-if="err" class="text-xs text-destructive">{{ err }}</p>

      <p v-if="!enabled" class="text-xs text-amber-700/80 dark:text-amber-300/70">
        Logging is off — enable it, then send a message to capture the prompt and raw response.
      </p>
      <p v-else-if="!entries.length" class="text-xs text-amber-700/80 dark:text-amber-300/70">
        No traces yet. Send a message in the chat below.
      </p>

      <!-- Trace list, newest first -->
      <details v-for="(e, i) in entries" :key="i"
        class="rounded border border-amber-400/40 bg-white/60 dark:bg-black/20 text-xs">
        <summary class="flex flex-wrap items-center gap-2 px-2 py-1.5 cursor-pointer">
          <span class="font-mono text-muted-foreground">{{ time(e.ts) }}</span>
          <span class="font-mono text-foreground">{{ e.provider }}</span>
          <span class="font-mono text-muted-foreground">{{ e.model }}</span>
          <span class="text-muted-foreground">{{ e.latency_ms }}ms</span>

          <span v-if="e.error" class="px-1.5 py-0.5 rounded bg-destructive/15 text-destructive font-medium">
            error: {{ e.error_code }}
          </span>
          <template v-else>
            <span :class="['px-1.5 py-0.5 rounded font-medium',
              e.parsed_ok ? 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400'
                          : 'bg-destructive/15 text-destructive']">
              {{ e.parsed_ok ? 'JSON ✓' : 'parse fail' }}
            </span>
            <span v-if="e.plan_steps" class="px-1.5 py-0.5 rounded bg-primary/15 text-primary font-medium">
              {{ e.plan_steps }} step{{ e.plan_steps === 1 ? '' : 's' }}
            </span>
            <span v-if="e.clarifying_count" class="px-1.5 py-0.5 rounded bg-amber-500/20 text-amber-700 dark:text-amber-300 font-medium">
              {{ e.clarifying_count }} question{{ e.clarifying_count === 1 ? '' : 's' }}
            </span>
          </template>
        </summary>

        <div class="border-t border-amber-400/30 p-2 space-y-3">
          <p v-if="e.error" class="text-destructive">{{ e.error }}</p>

          <!-- System prompt -->
          <div>
            <div class="flex items-center gap-1.5 font-semibold text-muted-foreground mb-1">
              <FileText class="w-3 h-3" /> System prompt
            </div>
            <pre class="whitespace-pre-wrap break-words rounded bg-muted/60 p-2 font-mono max-h-48 overflow-y-auto">{{ e.system }}</pre>
          </div>

          <!-- Sent messages -->
          <div v-if="e.messages && e.messages.length">
            <div class="flex items-center gap-1.5 font-semibold text-muted-foreground mb-1">
              <MessageSquare class="w-3 h-3" /> Messages sent ({{ e.messages.length }})
            </div>
            <div class="space-y-1">
              <div v-for="(m, mi) in e.messages" :key="mi" class="rounded bg-muted/60 p-2">
                <span class="font-mono font-semibold text-primary">{{ m.role }}</span>
                <pre class="whitespace-pre-wrap break-words font-mono mt-1">{{ m.content }}</pre>
              </div>
            </div>
          </div>

          <!-- Raw request: the exact JSON handed to the provider (keys redacted) -->
          <div v-if="e.request">
            <div class="flex items-center gap-1.5 font-semibold text-muted-foreground mb-1">
              <Braces class="w-3 h-3" /> Raw request
              <span v-if="e.request.endpoint" class="font-mono font-normal text-muted-foreground/70 truncate">{{ e.request.endpoint }}</span>
            </div>
            <pre class="whitespace-pre-wrap break-words rounded bg-muted/60 p-2 font-mono max-h-60 overflow-y-auto">{{ pretty(e.request) }}</pre>
          </div>

          <!-- Raw response -->
          <div v-if="e.response_raw != null">
            <div class="flex items-center gap-1.5 font-semibold text-muted-foreground mb-1">
              <CornerDownLeft class="w-3 h-3" /> Raw response
            </div>
            <pre class="whitespace-pre-wrap break-words rounded bg-muted/60 p-2 font-mono max-h-60 overflow-y-auto">{{ e.response_raw }}</pre>
          </div>
        </div>
      </details>
    </div>
  </div>
</template>
