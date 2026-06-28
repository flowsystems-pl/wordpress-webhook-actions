<script setup>
import { ref, computed, onMounted, nextTick } from 'vue';
import {
  BrainCircuit,
  Send,
  Sparkles,
  Plus,
  Trash2,
  ShieldAlert,
  Play,
  Undo2,
  CheckCircle2,
  XCircle,
  Loader2,
  Settings2,
} from 'lucide-vue-next';
import { Button } from '@/components/ui';
import ProviderLogo from '@/components/ProviderLogo.vue';
import AiProviderSettings from '@/components/AiProviderSettings.vue';
import { api } from '@/lib/api';
import { __ } from '@/i18n';

// ---- State ---------------------------------------------------------------
const loading = ref(true);
const status = ref(null);
const conversations = ref([]);
const activeId = ref(null);
const transcript = ref([]);
const plan = ref([]);
const messageInput = ref('');
const sending = ref(false);
const executing = ref(false);
const execResult = ref(null);
const pendingConfirm = ref(null);
const error = ref('');
const transcriptEl = ref(null);

// ---- Provider settings ---------------------------------------------------
const showSettings = ref(false);
const configured = computed(() => status.value?.configured === true);

// Active transport (for the model bar + provider logo).
const activeProvider = computed(() => status.value?.active_provider || '');
const activeModel = computed(() => status.value?.active_model || '');

const PROVIDER_LABELS = {
  anthropic: 'Anthropic (Claude)',
  openai: 'OpenAI',
  google: 'Google Gemini',
};
const activeProviderLabel = computed(() => {
  const id = activeProvider.value;
  const wp = status.value?.wp_ai_client?.providers?.find((p) => p.id === id);
  const byo = status.value?.byok?.providers?.find((p) => p.id === id);
  return wp?.label || byo?.label || PROVIDER_LABELS[id] || id;
});

// The settings component returns a fresh status payload after every change.
function onSettingsUpdate(newStatus) {
  status.value = newStatus;
}

// Steps the user has confirmed for the next execute call.
const confirmedSteps = computed(() =>
  plan.value.filter((s) => s._confirmed).map((s) => s.id)
);
const hasUnconfirmed = computed(() =>
  plan.value.some((s) => s.requires_confirm && !s._confirmed)
);

// ---- Lifecycle -----------------------------------------------------------
onMounted(async () => {
  try {
    await refreshStatus();
    await loadConversations();
  } finally {
    loading.value = false;
  }
});

async function refreshStatus() {
  try {
    status.value = await api.agent.status();
  } catch (e) {
    error.value = e.message;
  }
}

async function loadConversations() {
  try {
    const res = await api.agent.listConversations();
    conversations.value = res.conversations || [];
  } catch (e) {
    error.value = e.message;
  }
}

// ---- Conversations -------------------------------------------------------
async function newChat() {
  try {
    const conv = await api.agent.createConversation();
    conversations.value.unshift(conv);
    selectConversation(conv);
  } catch (e) {
    error.value = e.message;
  }
}

async function selectConversation(conv) {
  activeId.value = conv.id;
  execResult.value = null;
  pendingConfirm.value = null;
  try {
    const full = await api.agent.getConversation(conv.id);
    transcript.value = full.transcript_json || [];
    plan.value = decoratePlan(full.plan_json || []);
    await scrollDown();
  } catch (e) {
    error.value = e.message;
  }
}

async function removeConversation(conv) {
  if (!confirm(__('Delete this conversation?'))) return;
  await api.agent.deleteConversation(conv.id);
  conversations.value = conversations.value.filter((c) => c.id !== conv.id);
  if (activeId.value === conv.id) {
    activeId.value = null;
    transcript.value = [];
    plan.value = [];
  }
}

// Attach a local _confirmed flag for the UI without mutating server data.
function decoratePlan(steps) {
  return (steps || []).map((s) => ({ ...s, _confirmed: false }));
}

// ---- Chat ----------------------------------------------------------------
async function send() {
  const text = messageInput.value.trim();
  if (!text || sending.value) return;

  if (!activeId.value) {
    await newChat();
  }

  sending.value = true;
  error.value = '';
  execResult.value = null;
  pendingConfirm.value = null;
  transcript.value.push({ role: 'user', content: text });
  messageInput.value = '';
  await scrollDown();

  try {
    const res = await api.agent.message(activeId.value, text);
    transcript.value.push({ role: 'assistant', content: res.assistant_message });
    plan.value = decoratePlan(res.plan || []);
    await scrollDown();
    await loadConversations();
  } catch (e) {
    error.value = e.message;
  } finally {
    sending.value = false;
  }
}

function removeStep(id) {
  plan.value = plan.value.filter((s) => s.id !== id);
}

// ---- Execute -------------------------------------------------------------
async function buildIt() {
  if (!plan.value.length || executing.value) return;
  executing.value = true;
  error.value = '';
  pendingConfirm.value = null;
  try {
    const payload = {
      plan: plan.value.map(({ _confirmed, ...rest }) => rest),
      confirmed: confirmedSteps.value,
    };
    const res = await api.agent.execute(activeId.value, payload);
    execResult.value = res;
    if (res.status === 'needs_confirm') {
      pendingConfirm.value = res.pending_step;
    } else if (res.status === 'completed') {
      // Refresh the plan (now consumed) and transcript.
      await selectConversation({ id: activeId.value });
    }
  } catch (e) {
    error.value = e.message;
  } finally {
    executing.value = false;
  }
}

async function undo() {
  if (!confirm(__('Undo the last build? This deletes webhooks/chains the agent created.'))) return;
  try {
    const res = await api.agent.undo(activeId.value);
    execResult.value = { status: 'undone', reverted: res.reverted };
  } catch (e) {
    error.value = e.message;
  }
}

async function scrollDown() {
  await nextTick();
  if (transcriptEl.value) {
    transcriptEl.value.scrollTop = transcriptEl.value.scrollHeight;
  }
}
</script>

<template>
  <div>
    <!-- Heading -->
    <div class="flex items-center gap-2 mb-2">
      <BrainCircuit class="w-6 h-6 text-primary" />
      <h2 class="text-xl font-semibold text-foreground">{{ __('Build with AI') }}</h2>
      <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-primary/15 text-primary border border-primary/30">
        <Sparkles class="w-3 h-3" /> {{ __('Beta') }}
      </span>
    </div>
    <p class="text-sm text-muted-foreground mb-6">
      {{ __('Describe the integration or automation you want. The agent proposes a plan you can edit, then builds and tests it for you.') }}
    </p>

    <div v-if="loading" class="flex items-center gap-2 text-muted-foreground">
      <Loader2 class="w-4 h-4 animate-spin" /> {{ __('Loading…') }}
    </div>

    <!-- Setup card -------------------------------------------------------- -->
    <div v-else-if="!configured" class="rounded-lg border border-border bg-card p-6 max-w-2xl">
      <div class="flex items-center gap-2 mb-1">
        <Settings2 class="w-5 h-5 text-primary" />
        <h3 class="text-lg font-semibold text-foreground">{{ __('Connect an AI provider') }}</h3>
      </div>
      <p class="text-sm text-muted-foreground mb-4">
        {{ __('Use a provider configured in WordPress, or add your own API keys. Keys are encrypted in your Credentials Vault and never returned over the API.') }}
      </p>
      <AiProviderSettings :status="status" @update="onSettingsUpdate" />
    </div>

    <!-- Builder ----------------------------------------------------------- -->
    <div v-else class="space-y-4">
      <!-- Active model bar + expandable provider settings -->
      <div class="rounded-lg border border-border bg-card">
        <div class="flex items-center justify-between gap-3 px-4 py-3">
          <div class="flex items-center gap-2 min-w-0">
            <ProviderLogo :provider="activeProvider" :size="20" />
            <div class="min-w-0">
              <div class="text-sm font-medium text-foreground truncate">{{ activeProviderLabel }}</div>
              <div class="text-xs text-muted-foreground truncate font-mono">{{ activeModel }}</div>
            </div>
          </div>
          <Button variant="outline" size="sm" @click="showSettings = !showSettings">
            <Settings2 class="w-4 h-4 mr-1.5" />
            {{ __('Change model') }}
          </Button>
        </div>

        <div v-if="showSettings" class="border-t border-border p-4">
          <AiProviderSettings :status="status" @update="onSettingsUpdate" />
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-[220px_1fr] gap-6">
      <!-- Conversations sidebar -->
      <aside class="space-y-2">
        <button @click="newChat"
          class="w-full inline-flex items-center justify-center gap-2 rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted">
          <Plus class="w-4 h-4" /> {{ __('New build') }}
        </button>
        <ul class="space-y-1">
          <li v-for="c in conversations" :key="c.id">
            <div :class="['group flex items-center justify-between rounded-md px-3 py-2 text-sm cursor-pointer',
              activeId === c.id ? 'bg-primary/10 text-primary' : 'text-muted-foreground hover:bg-muted']"
              @click="selectConversation(c)">
              <span class="truncate">{{ c.title || __('Untitled build') }}</span>
              <button @click.stop="removeConversation(c)" class="opacity-0 group-hover:opacity-100 text-muted-foreground hover:text-destructive">
                <Trash2 class="w-3.5 h-3.5" />
              </button>
            </div>
          </li>
        </ul>
      </aside>

      <!-- Chat + plan -->
      <section class="space-y-4">
        <div v-if="!activeId" class="rounded-lg border border-dashed border-border p-8 text-center text-muted-foreground">
          {{ __('Start a new build, then describe what you want to integrate.') }}
        </div>

        <template v-else>
          <!-- Transcript -->
          <div ref="transcriptEl" class="rounded-lg border border-border bg-card p-4 max-h-[420px] overflow-y-auto space-y-3">
            <div v-if="!transcript.length" class="text-sm text-muted-foreground">
              {{ __('e.g. “When a Contact Form 7 form is submitted, send it as JSON to my n8n webhook.”') }}
            </div>
            <div v-for="(m, i) in transcript" :key="i"
              :class="['flex', m.role === 'user' ? 'justify-end' : 'justify-start']">
              <div :class="['max-w-[80%] rounded-lg px-3 py-2 text-sm whitespace-pre-wrap',
                m.role === 'user' ? 'bg-primary text-primary-foreground' : 'bg-muted text-foreground']">
                {{ m.content }}
              </div>
            </div>
            <div v-if="sending" class="flex items-center gap-2 text-muted-foreground text-sm">
              <Loader2 class="w-4 h-4 animate-spin" /> {{ __('Thinking…') }}
            </div>
          </div>

          <!-- Input -->
          <form @submit.prevent="send" class="flex gap-2">
            <input v-model="messageInput" type="text" :placeholder="__('Describe what to build…')"
              class="flex-1 rounded-md border border-border bg-background px-3 py-2 text-sm text-foreground" />
            <button type="submit" :disabled="sending || !messageInput.trim()"
              class="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground disabled:opacity-50">
              <Send class="w-4 h-4" /> {{ __('Send') }}
            </button>
          </form>

          <!-- Editable plan -->
          <div v-if="plan.length" class="rounded-lg border border-border bg-card p-4">
            <div class="flex items-center justify-between mb-3">
              <h3 class="text-sm font-semibold text-foreground">{{ __('Proposed plan') }}</h3>
              <span class="text-xs text-muted-foreground">{{ __('Review and edit before building') }}</span>
            </div>
            <ol class="space-y-2">
              <li v-for="(step, idx) in plan" :key="step.id"
                class="rounded-md border border-border p-3">
                <div class="flex items-start justify-between gap-3">
                  <div class="min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                      <span class="text-xs font-mono text-muted-foreground">{{ idx + 1 }}.</span>
                      <code class="text-xs px-1.5 py-0.5 rounded bg-muted text-foreground">{{ step.ability }}</code>
                      <span v-if="step.requires_confirm"
                        class="inline-flex items-center gap-1 text-xs text-amber-600 dark:text-amber-400">
                        <ShieldAlert class="w-3.5 h-3.5" /> {{ __('Needs confirmation') }}
                      </span>
                    </div>
                    <p class="text-sm text-foreground">{{ step.summary }}</p>
                  </div>
                  <button @click="removeStep(step.id)" class="text-muted-foreground hover:text-destructive shrink-0">
                    <Trash2 class="w-4 h-4" />
                  </button>
                </div>
                <label v-if="step.requires_confirm" class="mt-2 flex items-center gap-2 text-xs text-muted-foreground">
                  <input type="checkbox" v-model="step._confirmed" /> {{ __('I confirm this step') }}
                </label>
              </li>
            </ol>

            <div class="mt-4 flex items-center gap-2">
              <button @click="buildIt" :disabled="executing || hasUnconfirmed"
                class="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground disabled:opacity-50">
                <Loader2 v-if="executing" class="w-4 h-4 animate-spin" />
                <Play v-else class="w-4 h-4" />
                {{ __('Build it') }}
              </button>
              <span v-if="hasUnconfirmed" class="text-xs text-amber-600 dark:text-amber-400">
                {{ __('Confirm the highlighted steps to continue.') }}
              </span>
            </div>
          </div>

          <!-- Execution result -->
          <div v-if="execResult" class="rounded-lg border border-border bg-card p-4">
            <div v-if="execResult.status === 'completed'" class="flex items-center gap-2 text-sm text-emerald-600 dark:text-emerald-400 mb-2">
              <CheckCircle2 class="w-4 h-4" /> {{ __('Build applied.') }}
            </div>
            <div v-else-if="execResult.status === 'needs_confirm'" class="flex items-center gap-2 text-sm text-amber-600 dark:text-amber-400 mb-2">
              <ShieldAlert class="w-4 h-4" />
              {{ __('Paused for confirmation:') }} {{ pendingConfirm?.summary }}
            </div>
            <div v-else-if="execResult.status === 'error'" class="flex items-center gap-2 text-sm text-destructive mb-2">
              <XCircle class="w-4 h-4" /> {{ execResult.error }}
            </div>
            <div v-else-if="execResult.status === 'undone'" class="flex items-center gap-2 text-sm text-muted-foreground mb-2">
              <Undo2 class="w-4 h-4" /> {{ __('Last build undone.') }}
            </div>

            <button v-if="execResult.status === 'completed'" @click="undo"
              class="inline-flex items-center gap-1.5 rounded-md border border-border px-3 py-1.5 text-xs font-medium text-muted-foreground hover:bg-muted">
              <Undo2 class="w-3.5 h-3.5" /> {{ __('Undo this build') }}
            </button>
          </div>
        </template>
      </section>
      </div>
    </div>

    <!-- Error toast -->
    <div v-if="error" class="mt-4 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
      {{ error }}
    </div>
  </div>
</template>
