<script setup>
import { ref, computed, watch, onMounted, nextTick } from 'vue';
import {
  BrainCircuit,
  WandSparkles,
  Sparkles,
  Plus,
  Trash2,
  ShieldAlert,
  Play,
  CheckCircle2,
  XCircle,
  AlertCircle,
  Circle,
  Loader2,
  Settings2,
  ExternalLink,
  Undo2,
  RotateCcw,
  Power,
} from 'lucide-vue-next';
import { Button, Input, Switch, Select, SelectTrigger, SelectValue, SelectContent, SelectItem, Dialog } from '@/components/ui';
import ProviderLogo from '@/components/ProviderLogo.vue';
import AiProviderSettings from '@/components/AiProviderSettings.vue';
import AiDevPanel from '@/components/AiDevPanel.vue';
import ChatMarkdown from '@/components/ChatMarkdown.vue';
import AiPlanStepper from '@/components/AiPlanStepper.vue';
import AiStepControls from '@/components/AiStepControls.vue';
import { abilityTitle } from '@/lib/aiLabels';
import { api } from '@/lib/api';
import { __ } from '@/i18n';

// The dev trace panel always renders under the Vite dev server, and in
// production when the site opts in via Settings → AI Builder (trace_enabled).
const isDev = import.meta.env.DEV;
const devPanel = ref(null);
const showDevPanel = computed(() => isDev || status.value?.trace_enabled === true);

// ---- State ---------------------------------------------------------------
const loading = ref(true);
const status = ref(null);
const conversations = ref([]);
const abilities = ref({});
const activeId = ref(null);
const transcript = ref([]);
const plan = ref([]);
const messageInput = ref('');
const sending = ref(false);
const error = ref('');
const transcriptEl = ref(null);

// ---- Execution state machine ---------------------------------------------
const execution = ref(null);      // { mode, cursor, refs, steps[] }
const running = ref(false);       // the step loop is in flight

// Presentation pacing: most abilities execute in milliseconds, which reads as
// "nothing happened" to a human. Each auto-run step therefore stays visibly
// "running" for a minimum time, and the fresh green check gets a beat to
// register before the next step starts.
const MIN_STEP_MS = 3200;         // minimum visible "running" time per step
const DONE_FLASH_MS = 1200;       // pause on the completed check before chaining

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
function onSettingsUpdate(newStatus, action = '') {
  status.value = newStatus;
  // Once a model is actually picked/activated (WP preference, a BYO model, or
  // "Use" a provider), close the "Change model" panel so it's clear we're ready.
  if (action === 'wp' || action.startsWith('model:') || action.startsWith('use:')) {
    showSettings.value = false;
  }
}

// ---- Execution-derived state ---------------------------------------------
const execMode = computed(() => execution.value?.mode || status.value?.exec_mode || 'auto');
const isReview = computed(() => execMode.value === 'review');
const execSteps = computed(() => execution.value?.steps || []);
const execCursor = computed(() => execution.value?.cursor ?? 0);

// The webhook this build created OR edited, so the user can jump in and tinker
// once done. Prefers a created webhook; otherwise the last one the build touched.
const builtWebhookId = computed(() => {
  let id = 0;
  for (const s of execSteps.value) {
    if (s.status !== 'done') continue;
    if (s.ability === 'create_webhook') {
      const n = Number(s.result?.webhook?.id);
      if (n > 0) id = n;
    } else if (s.ability === 'delete_webhook') {
      id = 0; // deleted — nothing to open
    } else if (s.ability === 'update_webhook' || s.ability === 'enable_webhook') {
      const n = Number(s.input?.id);
      if (n > 0) id = n;
    } else if (['set_mapping', 'set_conditions', 'assign_credential', 'test_dispatch', 'probe_endpoint', 'get_trigger_schema'].includes(s.ability)) {
      const n = Number(s.input?.webhook_id);
      if (n > 0) id = n;
    }
  }
  return id;
});
const execFinished = computed(() => !!execution.value && execCursor.value >= execSteps.value.length);
// Whether the aside progress stepper has anything to show (drives the layout).
const hasStepper = computed(() => !!execution.value && execSteps.value.length > 0);

// A build can finish with its webhook still disabled (new webhooks are created
// disabled by design, and edit-plans don't always include an enable step). Check
// at build end and offer to switch it live — the user should never be left with
// a silently-dead webhook.
const builtWebhookEnabled = ref(null); // null = unknown / not fetched
const enablingWebhook = ref(false);
const justEnabled = ref(false);

watch([execFinished, builtWebhookId], async ([finished, id]) => {
  builtWebhookEnabled.value = null;
  justEnabled.value = false;
  if (!finished || !id) return;
  try {
    const wh = await api.webhooks.get(id);
    builtWebhookEnabled.value = Number(wh.is_enabled) === 1;
  } catch (e) {
    // Leave unknown — no offer rather than a wrong one.
  }
}, { immediate: true });

async function enableBuiltWebhook() {
  if (enablingWebhook.value || !builtWebhookId.value) return;
  enablingWebhook.value = true;
  error.value = '';
  try {
    await api.webhooks.toggle(builtWebhookId.value);
    builtWebhookEnabled.value = true;
    justEnabled.value = true;
  } catch (e) {
    error.value = e.message;
  } finally {
    enablingWebhook.value = false;
  }
}
// The step currently being processed / awaiting the user (at the cursor).
const currentStep = computed(() => execSteps.value[execCursor.value] || null);
// Which step the user is viewing in the main panel. Null = follow the cursor.
const focusedIndex = ref(null);
const focusedStep = computed(() => execSteps.value[focusedIndex.value ?? execCursor.value] || null);
const focusedIsCurrent = computed(
  () => (focusedIndex.value ?? execCursor.value) === execCursor.value && !execFinished.value
);
// Review mode, nothing run yet: show the plan for inspection before the first run.
const reviewPreRun = computed(() =>
  !!execution.value && isReview.value && execCursor.value === 0 &&
  execSteps.value.every((s) => s.status === 'pending') && !running.value
);
// A paused-but-runnable run the user can resume (e.g. after leaving the panel).
const canContinue = computed(() =>
  !!execution.value && !execFinished.value && !running.value &&
  currentStep.value?.status === 'pending' && !reviewPreRun.value
);

// ---- Lifecycle -----------------------------------------------------------
onMounted(async () => {
  try {
    await refreshStatus();
    await Promise.all([loadConversations(), loadAbilities(), loadCredentials()]);
    // Restore the most recent build (newest first) so its progress resumes.
    if (!activeId.value && conversations.value.length) {
      await selectConversation(conversations.value[0]);
    }
  } finally {
    loading.value = false;
  }
});

async function loadAbilities() {
  try {
    const res = await api.agent.abilities();
    abilities.value = res.abilities || {};
  } catch (e) {
    // Non-fatal: the plan still renders, just without typed field editors.
  }
}

// Vault credentials, for the "attach a credential" fix on a 401/403 probe.
const credentials = ref([]);
async function loadCredentials() {
  try {
    const res = await api.credentials.list();
    credentials.value = Array.isArray(res) ? res : (res.credentials || res.items || []);
  } catch (e) {
    // Non-fatal: the auth fix falls back to a "add one, then retry" hint.
  }
}

// Busy flag while a credential is being created inline (from a 401/403 probe fix).
const creatingCred = ref(false);

// Create a credential in the vault (payload from AiStepControls), assign it to the
// webhook and re-probe — so the user never leaves the build to set up auth.
async function onCreateCredential(payload) {
  if (creatingCred.value) return;
  creatingCred.value = true;
  error.value = '';
  try {
    const created = await api.credentials.create(payload);
    await loadCredentials();
    advance({ probe_fix: { auth_credential_id: Number(created.id) } });
  } catch (e) {
    error.value = e.message;
  } finally {
    creatingCred.value = false;
  }
}

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

function onSwitchConversation(idStr) {
  // Conversation ids come back from the REST API as strings, so compare loosely
  // (String vs String) rather than against a parsed number.
  const conv = conversations.value.find((c) => String(c.id) === String(idStr));
  if (conv) selectConversation(conv);
}

async function selectConversation(conv) {
  activeId.value = conv.id;
  focusedIndex.value = null;
  try {
    const full = await api.agent.getConversation(conv.id);
    transcript.value = full.transcript_json || [];
    plan.value = decoratePlan(full.plan_json || []);
    // Restore any in-progress run so the user resumes where they left off.
    execution.value = full.execution_json || null;
    await scrollDown();
    maybeResumePrereq();
  } catch (e) {
    error.value = e.message;
  }
}

// Delete confirmation via our Dialog (not a browser alert).
const deleteDialogOpen = ref(false);
const pendingDeleteId = ref(null);
const deleting = ref(false);

function removeConversation(conv) {
  pendingDeleteId.value = conv?.id ?? null;
  deleteDialogOpen.value = true;
}

async function confirmDeleteConversation() {
  const id = pendingDeleteId.value;
  if (id == null) return;
  deleting.value = true;
  try {
    await api.agent.deleteConversation(id);
    conversations.value = conversations.value.filter((c) => String(c.id) !== String(id));
    deleteDialogOpen.value = false;
    pendingDeleteId.value = null;
    if (String(activeId.value) === String(id)) {
      // Load the next remaining build so the panel isn't left blank (no refresh needed).
      if (conversations.value.length) {
        await selectConversation(conversations.value[0]);
      } else {
        activeId.value = null;
        transcript.value = [];
        plan.value = [];
        execution.value = null;
      }
    }
  } catch (e) {
    error.value = e.message;
  } finally {
    deleting.value = false;
  }
}

// Attach a local _confirmed flag for the UI without mutating server data, and
// guarantee an input object so field editors can bind to it.
function decoratePlan(steps) {
  return (steps || []).map((s) => ({ ...s, input: s.input || {}, _confirmed: false }));
}

// Fold any clarifying questions into the assistant bubble so they're actually
// visible (mirrors how the server stores them in the transcript).
function foldReply(message, questions) {
  const qs = questions || [];
  if (!qs.length) return message || '';
  return [message || '', ...qs.map((q) => `• ${q}`)].filter(Boolean).join('\n');
}

// ---- Chat ----------------------------------------------------------------
// The message whose send failed, so the user can retry without re-typing it.
const retryMessage = ref(null);

async function send() {
  const text = messageInput.value.trim();
  if (!text || sending.value) return;

  if (!activeId.value) {
    await newChat();
  }

  sending.value = true;
  error.value = '';
  retryMessage.value = null;
  focusedIndex.value = null;
  transcript.value.push({ role: 'user', content: text });
  messageInput.value = '';
  await scrollDown();
  await dispatchMessage(text);
}

// Re-send the last failed prompt (the user bubble is already in the transcript).
async function retrySend() {
  if (!retryMessage.value || sending.value) return;
  const text = retryMessage.value;
  sending.value = true;
  error.value = '';
  retryMessage.value = null;
  await dispatchMessage(text);
}

async function dispatchMessage(text) {
  try {
    const res = await api.agent.message(activeId.value, text);
    transcript.value.push({ role: 'assistant', content: foldReply(res.assistant_message, res.clarifying_questions) });
    // Only swap the plan when the reply carries a new one — a clarifying-only
    // reply must not blank out the progress aside (mirrors server persistence,
    // which also keeps the stored execution in that case).
    if (res.execution) {
      plan.value = decoratePlan(res.plan || []);
      execution.value = res.execution;
    }
    await scrollDown();
    await loadConversations();
    devPanel.value?.refresh();
    // Auto mode: start running a NEW plan immediately. Review mode waits for "Run plan".
    if (res.execution && execMode.value === 'auto') {
      advance();
    }
  } catch (e) {
    error.value = e.message;
    retryMessage.value = text;
    devPanel.value?.refresh();
  } finally {
    sending.value = false;
  }
}

// ---- Execution loop ------------------------------------------------------
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// Advance the plan one step, then auto-chain while the server says to continue.
// `opts` (patch / confirm / skip) apply only to the FIRST call of this run.
async function advance(opts = {}) {
  if (running.value || !activeId.value) return;
  running.value = true;
  error.value = '';
  try {
    let first = true;
    let keepGoing = true;
    while (keepGoing) {
      const startedAt = Date.now();
      const res = await api.agent.step(activeId.value, first ? opts : {});
      first = false;
      // Hold the "running" presentation so even instant steps are noticeable,
      // THEN apply the result (the step flips to done / blocked in one beat).
      const hold = MIN_STEP_MS - (Date.now() - startedAt);
      if (hold > 0) await sleep(hold);
      execution.value = res.execution;
      focusedIndex.value = null; // follow the cursor as it advances
      devPanel.value?.refresh();
      keepGoing = res.continue;
      if (keepGoing) await sleep(DONE_FLASH_MS);
    }
    await loadConversations();
  } catch (e) {
    error.value = e.message;
  } finally {
    running.value = false;
  }
}

// A "waiting for a captured payload" pause is a wait-on-external-state condition:
// it can resolve itself once the payload exists (submitted in the meantime, or
// already captured on another webhook). Re-check automatically when the user
// returns to the panel, so they don't have to hit Retry. Other pauses
// (blocked_input / needs_confirm / failed) genuinely need the user, so we leave them.
function maybeResumePrereq() {
  if (currentStep.value?.status === 'blocked_prereq' && !running.value) {
    advance();
  }
}

function confirmStep() {
  advance({ confirm: true });
}
function retryStep() {
  advance({});
}
function skipStep() {
  advance({ skip: true });
}

// Revert the most recent applied change. Repeated clicks walk further back.
const REVERTIBLE_ABILITIES = ['create_webhook', 'update_webhook', 'set_mapping', 'set_conditions', 'assign_credential', 'enable_webhook'];
const hasRevertible = computed(() =>
  execSteps.value.some((s) => s.status === 'done' && REVERTIBLE_ABILITIES.includes(s.ability))
);

async function revertLast() {
  if (running.value || !activeId.value) return;
  running.value = true;
  error.value = '';
  try {
    const res = await api.agent.revert(activeId.value);
    execution.value = res.execution;
    if (res.transcript) transcript.value = res.transcript;
    focusedIndex.value = null;
    devPanel.value?.refresh();
    await scrollDown();
    await loadConversations();
  } catch (e) {
    error.value = e.message;
  } finally {
    running.value = false;
  }
}

async function setExecMode(mode) {
  try {
    const res = await api.agent.setExecMode(mode);
    if (status.value) status.value = { ...status.value, exec_mode: res.exec_mode };
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
    <!-- Developer trace panel (Vite dev server only) -->
    <AiDevPanel v-if="showDevPanel" ref="devPanel" />

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
          <div class="flex items-center gap-3 shrink-0">
            <label class="flex items-center gap-2 text-xs text-muted-foreground cursor-pointer select-none">
              <Switch :model-value="isReview" @update:model-value="(v) => setExecMode(v ? 'review' : 'auto')" />
              {{ __('Review plan before running') }}
            </label>
            <Button variant="outline" size="sm" @click="showSettings = !showSettings">
              <Settings2 class="w-4 h-4 mr-1.5" />
              {{ __('Change model') }}
            </Button>
          </div>
        </div>

        <div v-if="showSettings" class="border-t border-border p-4">
          <AiProviderSettings :status="status" @update="onSettingsUpdate" />
        </div>
      </div>

      <!-- Builds bar: switcher + delete + new, above the conversation window -->
      <div class="flex flex-wrap items-center gap-2">
        <template v-if="conversations.length > 1">
          <label class="text-xs font-medium text-muted-foreground">{{ __('Your builds') }}</label>
          <div class="flex-1 min-w-56 max-w-2xl">
            <Select :model-value="String(activeId ?? '')" @update:model-value="onSwitchConversation">
              <SelectTrigger><SelectValue :placeholder="__('Your builds')" /></SelectTrigger>
              <SelectContent>
                <SelectItem v-for="c in conversations" :key="c.id" :value="String(c.id)">
                  {{ c.title || __('Untitled build') }}
                </SelectItem>
              </SelectContent>
            </Select>
          </div>
        </template>
        <button v-if="activeId" @click="removeConversation({ id: activeId })" :title="__('Delete this build')"
          class="p-2 rounded-md text-muted-foreground hover:text-destructive hover:bg-muted shrink-0">
          <Trash2 class="w-4 h-4" />
        </button>
        <div class="flex-1"></div>
        <button @click="newChat"
          class="inline-flex items-center gap-2 rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted shrink-0">
          <Plus class="w-4 h-4" /> {{ __('New build') }}
        </button>
      </div>

      <div :class="['grid grid-cols-1 gap-6', hasStepper && 'lg:grid-cols-[240px_1fr]']">
      <!-- Aside: build progress stepper (only once a plan is executing) -->
      <aside v-if="hasStepper">
        <AiPlanStepper :steps="execSteps" :abilities="abilities"
          :cursor="execCursor" :finished="execFinished" :running="running"
          :selected="focusedIndex ?? execCursor" @select="(i) => (focusedIndex = i)" />
      </aside>

      <!-- Main: chat + the single focused step. min-w-0 lets the column shrink
           below its content's intrinsic width (wide code blocks scroll inside
           their bubble instead of blowing the grid out sideways). -->
      <section class="space-y-4 min-w-0">
        <div v-if="!activeId" class="rounded-lg border border-dashed border-border p-8 text-center text-muted-foreground">
          {{ __('Start a new build, then describe what you want to integrate.') }}
        </div>

        <template v-else>
          <!-- Transcript -->
          <div ref="transcriptEl" class="rounded-lg border border-border bg-card p-4 max-h-[300px] overflow-y-auto space-y-3">
            <div v-if="!transcript.length" class="text-sm text-muted-foreground">
              {{ __('e.g. “When a Contact Form 7 form is submitted, send it as JSON to my n8n webhook.”') }}
            </div>
            <div v-for="(m, i) in transcript" :key="i"
              :class="['flex', m.role === 'user' ? 'justify-end' : 'justify-start']">
              <div :class="['max-w-[80%] min-w-0 rounded-lg px-3 py-2 text-sm',
                m.role === 'user' ? 'bg-primary text-primary-foreground whitespace-pre-wrap' : 'bg-muted text-foreground']">
                <ChatMarkdown v-if="m.role === 'assistant'" :text="m.content" />
                <template v-else>{{ m.content }}</template>
              </div>
            </div>
            <div v-if="sending" class="flex items-center gap-2 text-muted-foreground text-sm">
              <Loader2 class="w-4 h-4 animate-spin" /> {{ __('Thinking…') }}
            </div>
          </div>

          <!-- Input -->
          <form @submit.prevent="send" class="flex gap-2">
            <Input v-model="messageInput" type="text" :placeholder="__('Describe what to build…')"
              class="flex-1" />
            <button type="submit" :disabled="sending || !messageInput.trim()"
              class="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground disabled:opacity-50">
              <WandSparkles class="w-4 h-4" /> {{ __('Build') }}
            </button>
          </form>

          <!-- Focused step detail (one step at a time) -->
          <div v-if="execution && focusedStep" class="rounded-lg border border-border bg-card p-5 space-y-4">
            <!-- Header -->
            <div class="flex items-start gap-3">
              <div class="mt-0.5 shrink-0 relative">
                <span v-if="running && focusedIsCurrent && focusedStep.status === 'pending'"
                  class="absolute inset-0 rounded-full bg-primary/40 animate-ping" aria-hidden="true"></span>
                <Transition name="fswa-pop" mode="out-in">
                  <span :key="focusedStep.status + ':' + (running && focusedIsCurrent)" class="relative block">
                    <CheckCircle2 v-if="focusedStep.status === 'done'" class="w-5 h-5 text-emerald-500" />
                    <Loader2 v-else-if="running && focusedIsCurrent && focusedStep.status === 'pending'" class="w-5 h-5 animate-spin text-primary" />
                    <ShieldAlert v-else-if="focusedStep.status === 'needs_confirm'" class="w-5 h-5 text-amber-500" />
                    <XCircle v-else-if="focusedStep.status === 'failed'" class="w-5 h-5 text-destructive" />
                    <AlertCircle v-else-if="focusedStep.status === 'blocked_input' || focusedStep.status === 'blocked_prereq' || focusedStep.status === 'blocked_probe'" class="w-5 h-5 text-amber-500" />
                    <Undo2 v-else-if="focusedStep.status === 'reverted'" class="w-5 h-5 text-muted-foreground" />
                    <Circle v-else class="w-5 h-5 text-primary" />
                  </span>
                </Transition>
              </div>
              <div class="min-w-0">
                <h3 class="text-base font-semibold text-foreground leading-snug">
                  {{ focusedStep.summary || abilityTitle(abilities, focusedStep.ability) }}
                </h3>
                <p class="text-xs text-muted-foreground mt-0.5">
                  {{ __('Step') }} {{ (focusedIndex ?? execCursor) + 1 }} {{ __('of') }} {{ execSteps.length }} · {{ abilityTitle(abilities, focusedStep.ability) }}
                </p>
              </div>
            </div>

            <!-- Active controls (only when this is the step being run) -->
            <AiStepControls
              v-if="focusedIsCurrent"
              :key="focusedStep.id + ':' + focusedStep.status"
              :step="focusedStep"
              :abilities="abilities"
              :credentials="credentials"
              :busy="running || creatingCred"
              @continue="(patch) => advance({ patch })"
              @confirm="confirmStep"
              @retry="retryStep"
              @skip="skipStep"
              @probe-fix="(fix) => advance({ probe_fix: fix })"
              @create-credential="onCreateCredential"
            />

            <!-- Non-current step states -->
            <div v-else-if="focusedStep.status === 'done'" class="flex items-center gap-1.5 text-sm text-emerald-600 dark:text-emerald-400">
              <CheckCircle2 class="w-4 h-4" /> {{ __('Done') }}
            </div>
            <div v-else-if="focusedStep.status === 'skipped'" class="text-sm text-muted-foreground">{{ __('Skipped') }}</div>
            <div v-else-if="focusedStep.status === 'reverted'" class="flex items-center gap-1.5 text-sm text-muted-foreground"><Undo2 class="w-4 h-4" /> {{ __('Reverted') }}</div>
            <div v-else-if="!focusedIsCurrent" class="text-sm text-muted-foreground">{{ __('Waiting for earlier steps…') }}</div>

            <!-- Run / continue / finished -->
            <div v-if="reviewPreRun || canContinue || execFinished || running"
              class="flex items-center gap-2 pt-3 border-t border-border">
              <Button v-if="reviewPreRun" :disabled="running" @click="advance()">
                <Play class="w-4 h-4 mr-1.5" /> {{ __('Run plan') }}
              </Button>
              <Button v-else-if="canContinue" :disabled="running" @click="advance()">
                <Play class="w-4 h-4 mr-1.5" /> {{ __('Continue build') }}
              </Button>
              <template v-if="execFinished">
                <span class="flex items-center gap-2 text-sm text-emerald-600 dark:text-emerald-400">
                  <CheckCircle2 class="w-4 h-4" /> {{ __('Build complete.') }}
                </span>
                <Button v-if="builtWebhookEnabled === false" size="sm" :disabled="enablingWebhook" @click="enableBuiltWebhook">
                  <Power class="w-4 h-4 mr-1.5" /> {{ __('Enable webhook') }}
                </Button>
                <span v-else-if="justEnabled" class="flex items-center gap-1.5 text-sm text-emerald-600 dark:text-emerald-400">
                  <Power class="w-4 h-4" /> {{ __('Webhook is live.') }}
                </span>
                <RouterLink v-if="builtWebhookId" :to="{ name: 'WebhookEdit', params: { id: builtWebhookId } }">
                  <Button size="sm" variant="outline">
                    <ExternalLink class="w-4 h-4 mr-1.5" /> {{ __('Open webhook') }}
                  </Button>
                </RouterLink>
                <Button v-if="hasRevertible" size="sm" variant="outline" :disabled="running" @click="revertLast">
                  <Undo2 class="w-4 h-4 mr-1.5" /> {{ __('Undo last change') }}
                </Button>
              </template>
              <span v-else-if="running" class="flex items-center gap-2 text-sm text-muted-foreground">
                <Loader2 class="w-4 h-4 animate-spin" /> {{ __('Working…') }}
              </span>
            </div>
          </div>

          <!-- Finished, nothing focused -->
          <div v-else-if="execution && execFinished"
            class="rounded-lg border border-border bg-card p-5 flex flex-wrap items-center gap-3 text-sm">
            <span class="flex items-center gap-2 text-emerald-600 dark:text-emerald-400">
              <CheckCircle2 class="w-5 h-5" /> {{ __('Build complete.') }}
            </span>
            <Button v-if="builtWebhookEnabled === false" size="sm" :disabled="enablingWebhook" @click="enableBuiltWebhook">
              <Power class="w-4 h-4 mr-1.5" /> {{ __('Enable webhook') }}
            </Button>
            <span v-else-if="justEnabled" class="flex items-center gap-1.5 text-emerald-600 dark:text-emerald-400">
              <Power class="w-4 h-4" /> {{ __('Webhook is live.') }}
            </span>
            <RouterLink v-if="builtWebhookId" :to="{ name: 'WebhookEdit', params: { id: builtWebhookId } }">
              <Button size="sm" variant="outline">
                <ExternalLink class="w-4 h-4 mr-1.5" /> {{ __('Open webhook') }}
              </Button>
            </RouterLink>
            <Button v-if="hasRevertible" size="sm" variant="outline" :disabled="running" @click="revertLast">
              <Undo2 class="w-4 h-4 mr-1.5" /> {{ __('Undo last change') }}
            </Button>
          </div>
        </template>
      </section>
      </div>
    </div>

    <!-- Error toast -->
    <div v-if="error" class="mt-4 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive flex items-center justify-between gap-3">
      <span>{{ error }}</span>
      <Button v-if="retryMessage" size="sm" variant="outline" :disabled="sending" @click="retrySend">
        <RotateCcw class="w-4 h-4 mr-1.5" /> {{ __('Retry') }}
      </Button>
    </div>

    <!-- Delete-build confirmation -->
    <Dialog
      :open="deleteDialogOpen"
      :title="__('Delete this build?')"
      :description="__('This removes the conversation and its build history. It does not delete any webhooks it created.')"
      @close="deleteDialogOpen = false"
    >
      <template #footer>
        <Button variant="outline" :disabled="deleting" @click="deleteDialogOpen = false">{{ __('Cancel') }}</Button>
        <Button variant="destructive" :disabled="deleting" @click="confirmDeleteConversation">
          <Trash2 class="w-4 h-4 mr-1.5" /> {{ __('Delete build') }}
        </Button>
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
/* Springy pop-in when a step's status icon changes (e.g. spinner → green check). */
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
