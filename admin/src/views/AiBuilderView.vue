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
  CheckCircle2,
  XCircle,
  AlertCircle,
  Circle,
  Loader2,
  Settings2,
  ExternalLink,
  Undo2,
} from 'lucide-vue-next';
import { Button, Input, Switch, Select, SelectTrigger, SelectValue, SelectContent, SelectItem, Dialog } from '@/components/ui';
import ProviderLogo from '@/components/ProviderLogo.vue';
import AiProviderSettings from '@/components/AiProviderSettings.vue';
import AiDevPanel from '@/components/AiDevPanel.vue';
import AiPlanStepper from '@/components/AiPlanStepper.vue';
import { abilityTitle, fieldMeta } from '@/lib/aiLabels';
import { api } from '@/lib/api';
import { __ } from '@/i18n';

// The dev trace panel renders only under the Vite dev server, never in the
// shipped production build (admin/dist).
const isDev = import.meta.env.DEV;
const devPanel = ref(null);

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
const inputDraft = ref({});       // user-typed values for a blocked_input step
const ANIM_DELAY = 450;           // ms between auto-advanced steps (animation breathing room)

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

// Inline probe-fix drafts (blocked_probe: correct the webhook then re-probe).
const probeUrlDraft = ref('');
const probeCredDraft = ref('');

// Filterable credential picker for large vaults.
const credSearch = ref('');
const filteredCredentials = computed(() => {
  const q = credSearch.value.trim().toLowerCase();
  if (!q) return credentials.value;
  return credentials.value.filter((c) => String(c.name || '').toLowerCase().includes(q));
});

function fixProbeEndpoint() {
  const url = probeUrlDraft.value.trim();
  if (!url) return;
  probeUrlDraft.value = '';
  advance({ probe_fix: { endpoint_url: url } });
}
function fixProbeAuth() {
  const id = Number(probeCredDraft.value);
  if (!id) return;
  probeCredDraft.value = '';
  advance({ probe_fix: { auth_credential_id: id } });
}

// Inline "create a credential, add it to the vault, and assign it" flow for a
// 401/403 probe — so the user never has to leave the build to set up auth.
const showCreateCred = ref(false);
const creatingCred = ref(false);
const newCred = ref({ name: '', type: 'bearer', secret: '', username: '', password: '', header_name: '' });

function resetNewCred() {
  newCred.value = { name: '', type: 'bearer', secret: '', username: '', password: '', header_name: '' };
  showCreateCred.value = false;
}

const newCredValid = computed(() => {
  const c = newCred.value;
  if (!c.name.trim()) return false;
  if (c.type === 'basic') return !!c.username && !!c.password;
  if (c.type === 'api_key' || c.type === 'custom') return !!c.secret && !!c.header_name.trim();
  return !!c.secret; // bearer
});

async function createAndAssignCred() {
  if (!newCredValid.value || creatingCred.value) return;
  creatingCred.value = true;
  error.value = '';
  try {
    const c = newCred.value;
    const payload = { name: c.name.trim(), type: c.type };
    if (c.type === 'basic') {
      payload.username = c.username;
      payload.password = c.password;
    } else {
      payload.secret = c.secret;
    }
    if (c.type === 'api_key' || c.type === 'custom') {
      payload.header_name = c.header_name.trim();
    }
    const created = await api.credentials.create(payload);
    await loadCredentials();
    resetNewCred();
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
  inputDraft.value = {};
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

// ---- Plan-step input schema ----------------------------------------------
function abilityFor(name) {
  return abilities.value[name] || null;
}

// Fields to ask for on a blocked_input step (the missing keys + their schema meta).
function missingFields(step) {
  const a = abilityFor(step?.ability);
  const props = a?.input_schema?.properties || {};
  return (step?.missing || []).map((key) => {
    const spec = props[key] || { type: 'string' };
    return { key, type: spec.type || 'string', enum: spec.enum || null };
  });
}

// Fold any clarifying questions into the assistant bubble so they're actually
// visible (mirrors how the server stores them in the transcript).
function foldReply(message, questions) {
  const qs = questions || [];
  if (!qs.length) return message || '';
  return [message || '', ...qs.map((q) => `• ${q}`)].filter(Boolean).join('\n');
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
  execution.value = null;
  inputDraft.value = {};
  focusedIndex.value = null;
  transcript.value.push({ role: 'user', content: text });
  messageInput.value = '';
  await scrollDown();

  try {
    const res = await api.agent.message(activeId.value, text);
    transcript.value.push({ role: 'assistant', content: foldReply(res.assistant_message, res.clarifying_questions) });
    plan.value = decoratePlan(res.plan || []);
    execution.value = res.execution || null;
    await scrollDown();
    await loadConversations();
    devPanel.value?.refresh();
    // Auto mode: start running the plan immediately. Review mode waits for "Run plan".
    if (execution.value && execMode.value === 'auto') {
      advance();
    }
  } catch (e) {
    error.value = e.message;
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
      const res = await api.agent.step(activeId.value, first ? opts : {});
      first = false;
      execution.value = res.execution;
      inputDraft.value = {};
      focusedIndex.value = null; // follow the cursor as it advances
      devPanel.value?.refresh();
      keepGoing = res.continue;
      if (keepGoing) await sleep(ANIM_DELAY);
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

function continueInput() {
  advance({ patch: { ...inputDraft.value } });
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
    <AiDevPanel v-if="isDev" ref="devPanel" />

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

      <div class="grid grid-cols-1 lg:grid-cols-[240px_1fr] gap-6">
      <!-- Aside: build switcher + progress stepper -->
      <aside class="space-y-3">
        <button @click="newChat"
          class="w-full inline-flex items-center justify-center gap-2 rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted">
          <Plus class="w-4 h-4" /> {{ __('New build') }}
        </button>

        <!-- Switch between builds (only meaningful with more than one) -->
        <div v-if="conversations.length > 1" class="space-y-1">
          <label class="block text-xs font-medium text-muted-foreground px-1">{{ __('Your builds') }}</label>
          <div class="flex items-center gap-1.5">
            <Select :model-value="String(activeId ?? '')" @update:model-value="onSwitchConversation" class="flex-1">
              <SelectTrigger><SelectValue :placeholder="__('Your builds')" /></SelectTrigger>
              <SelectContent>
                <SelectItem v-for="c in conversations" :key="c.id" :value="String(c.id)">
                  {{ c.title || __('Untitled build') }}
                </SelectItem>
              </SelectContent>
            </Select>
            <button v-if="activeId" @click="removeConversation({ id: activeId })" :title="__('Delete this build')"
              class="p-2 rounded-md text-muted-foreground hover:text-destructive hover:bg-muted shrink-0">
              <Trash2 class="w-4 h-4" />
            </button>
          </div>
        </div>

        <!-- Single build: just a delete affordance -->
        <div v-else-if="activeId" class="flex justify-end">
          <button @click="removeConversation({ id: activeId })"
            class="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-destructive">
            <Trash2 class="w-3.5 h-3.5" /> {{ __('Delete build') }}
          </button>
        </div>

        <AiPlanStepper v-if="execution && execSteps.length" :steps="execSteps" :abilities="abilities"
          :cursor="execCursor" :finished="execFinished" :running="running"
          :selected="focusedIndex ?? execCursor" @select="(i) => (focusedIndex = i)" />
      </aside>

      <!-- Main: chat + the single focused step -->
      <section class="space-y-4">
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
            <Input v-model="messageInput" type="text" :placeholder="__('Describe what to build…')"
              class="flex-1" />
            <button type="submit" :disabled="sending || !messageInput.trim()"
              class="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground disabled:opacity-50">
              <Send class="w-4 h-4" /> {{ __('Send') }}
            </button>
          </form>

          <!-- Focused step detail (one step at a time) -->
          <div v-if="execution && focusedStep" class="rounded-lg border border-border bg-card p-5 space-y-4">
            <!-- Header -->
            <div class="flex items-start gap-3">
              <div class="mt-0.5 shrink-0">
                <CheckCircle2 v-if="focusedStep.status === 'done'" class="w-5 h-5 text-emerald-500" />
                <Loader2 v-else-if="running && focusedIsCurrent && focusedStep.status === 'pending'" class="w-5 h-5 animate-spin text-primary" />
                <ShieldAlert v-else-if="focusedStep.status === 'needs_confirm'" class="w-5 h-5 text-amber-500" />
                <XCircle v-else-if="focusedStep.status === 'failed'" class="w-5 h-5 text-destructive" />
                <AlertCircle v-else-if="focusedStep.status === 'blocked_input' || focusedStep.status === 'blocked_prereq' || focusedStep.status === 'blocked_probe'" class="w-5 h-5 text-amber-500" />
                <Undo2 v-else-if="focusedStep.status === 'reverted'" class="w-5 h-5 text-muted-foreground" />
                <Circle v-else class="w-5 h-5 text-primary" />
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
            <template v-if="focusedIsCurrent">
              <!-- blocked_input: ask for the missing values, human-labelled -->
              <div v-if="focusedStep.status === 'blocked_input'" class="space-y-4">
                <div v-for="f in missingFields(focusedStep)" :key="f.key" class="space-y-1.5">
                  <label class="block text-sm font-medium text-foreground">{{ fieldMeta(f.key).label }}</label>
                  <p v-if="fieldMeta(f.key).help" class="text-xs text-muted-foreground">{{ fieldMeta(f.key).help }}</p>
                  <Select v-if="f.enum" :model-value="String(inputDraft[f.key] ?? '')"
                    @update:model-value="(v) => (inputDraft[f.key] = v)">
                    <SelectTrigger><SelectValue :placeholder="fieldMeta(f.key).label" /></SelectTrigger>
                    <SelectContent>
                      <SelectItem v-for="opt in f.enum" :key="opt" :value="opt">{{ opt }}</SelectItem>
                    </SelectContent>
                  </Select>
                  <Input v-else v-model="inputDraft[f.key]"
                    :type="f.type === 'integer' ? 'number' : 'text'"
                    :placeholder="fieldMeta(f.key).placeholder || fieldMeta(f.key).label" />
                </div>
                <Button :disabled="running" @click="continueInput">
                  <Play class="w-4 h-4 mr-1.5" /> {{ __('Continue') }}
                </Button>
              </div>

              <!-- blocked_prereq: need a captured payload -->
              <div v-else-if="focusedStep.status === 'blocked_prereq'"
                class="rounded-md border border-amber-400/40 bg-amber-50/40 dark:bg-amber-950/20 p-4 text-sm">
                <p class="text-amber-700 dark:text-amber-300 mb-3">
                  {{ __('No example payload captured yet. Open a page with your form and submit a test entry, then retry so the agent can map the real fields.') }}
                </p>
                <div class="flex gap-2">
                  <Button size="sm" :disabled="running" @click="retryStep">{{ __('I sent a test — retry') }}</Button>
                  <Button size="sm" variant="outline" :disabled="running" @click="skipStep">{{ __('Skip') }}</Button>
                </div>
              </div>

              <!-- blocked_probe: the probe reached the endpoint but got an actionable status -->
              <div v-else-if="focusedStep.status === 'blocked_probe'"
                class="rounded-md border border-amber-400/40 bg-amber-50/40 dark:bg-amber-950/20 p-4 text-sm space-y-3">
                <p class="text-amber-700 dark:text-amber-300">{{ focusedStep.probe?.message }}</p>

                <!-- 401/403: attach a vault credential to the webhook, then re-probe -->
                <template v-if="focusedStep.probe?.kind === 'auth'">
                  <!-- Pick an existing vault credential -->
                  <div v-if="credentials.length && !showCreateCred" class="space-y-2">
                    <Input v-if="credentials.length > 8" v-model="credSearch" type="text" :placeholder="__('Search credentials…')" />
                    <Select :model-value="String(probeCredDraft)" @update:model-value="(v) => (probeCredDraft = v)">
                      <SelectTrigger><SelectValue :placeholder="__('Choose a credential')" /></SelectTrigger>
                      <SelectContent>
                        <SelectItem v-for="c in filteredCredentials" :key="c.id" :value="String(c.id)">{{ c.name }}</SelectItem>
                        <div v-if="!filteredCredentials.length" class="px-2 py-1.5 text-xs text-muted-foreground">{{ __('No matches') }}</div>
                      </SelectContent>
                    </Select>
                    <div class="flex flex-wrap gap-2">
                      <Button size="sm" :disabled="running || !probeCredDraft" @click="fixProbeAuth">{{ __('Add credential & retry') }}</Button>
                      <Button size="sm" variant="outline" :disabled="running" @click="showCreateCred = true">{{ __('+ New credential') }}</Button>
                      <Button size="sm" variant="outline" :disabled="running" @click="skipStep">{{ __('Skip') }}</Button>
                    </div>
                  </div>

                  <!-- Create a new vault credential and assign it inline -->
                  <div v-else class="space-y-2">
                    <p v-if="!credentials.length" class="text-xs text-muted-foreground">{{ __('No credentials in the vault yet — create one and it will be assigned to this webhook.') }}</p>
                    <Input v-model="newCred.name" type="text" :placeholder="__('Credential name (e.g. n8n auth)')" />
                    <Select :model-value="newCred.type" @update:model-value="(v) => (newCred.type = v)">
                      <SelectTrigger><SelectValue /></SelectTrigger>
                      <SelectContent>
                        <SelectItem value="bearer">{{ __('Bearer token') }}</SelectItem>
                        <SelectItem value="basic">{{ __('Basic auth') }}</SelectItem>
                        <SelectItem value="api_key">{{ __('API key (header)') }}</SelectItem>
                        <SelectItem value="custom">{{ __('Custom header') }}</SelectItem>
                      </SelectContent>
                    </Select>
                    <template v-if="newCred.type === 'basic'">
                      <Input v-model="newCred.username" type="text" :placeholder="__('Username')" />
                      <Input v-model="newCred.password" type="password" :placeholder="__('Password')" />
                    </template>
                    <Input v-else v-model="newCred.secret" type="password" :placeholder="newCred.type === 'bearer' ? __('Token') : __('Secret value')" />
                    <Input v-if="newCred.type === 'api_key' || newCred.type === 'custom'" v-model="newCred.header_name" type="text" :placeholder="__('Header name (e.g. X-API-Key)')" />
                    <div class="flex flex-wrap gap-2">
                      <Button size="sm" :disabled="running || creatingCred || !newCredValid" @click="createAndAssignCred">{{ __('Create & assign') }}</Button>
                      <Button v-if="credentials.length" size="sm" variant="outline" :disabled="running || creatingCred" @click="showCreateCred = false">{{ __('Use existing') }}</Button>
                      <Button size="sm" variant="outline" :disabled="running || creatingCred" @click="skipStep">{{ __('Skip') }}</Button>
                    </div>
                  </div>
                </template>

                <!-- 404 / unreachable: provide a different endpoint URL, then re-probe -->
                <template v-else>
                  <div class="space-y-2">
                    <Input v-model="probeUrlDraft" type="text" :placeholder="__('https://your-endpoint.example/webhook')" />
                    <div class="flex gap-2">
                      <Button size="sm" :disabled="running || !probeUrlDraft" @click="fixProbeEndpoint">{{ __('Update URL & retry') }}</Button>
                      <Button size="sm" variant="outline" :disabled="running" @click="retryStep">{{ __('Retry') }}</Button>
                      <Button size="sm" variant="outline" :disabled="running" @click="skipStep">{{ __('Skip') }}</Button>
                    </div>
                  </div>
                </template>
              </div>

              <!-- needs_confirm -->
              <div v-else-if="focusedStep.status === 'needs_confirm'"
                class="rounded-md border border-amber-400/40 bg-amber-50/40 dark:bg-amber-950/20 p-4">
                <p class="text-sm text-amber-700 dark:text-amber-300 mb-3 flex items-center gap-1.5">
                  <ShieldAlert class="w-4 h-4" /> {{ __('This step goes live or changes data. Confirm to run it.') }}
                </p>
                <div class="flex gap-2">
                  <Button size="sm" :disabled="running" @click="confirmStep">{{ __('Confirm & run') }}</Button>
                  <Button size="sm" variant="outline" :disabled="running" @click="skipStep">{{ __('Skip') }}</Button>
                </div>
              </div>

              <!-- failed -->
              <div v-else-if="focusedStep.status === 'failed'"
                class="rounded-md border border-destructive/40 bg-destructive/10 p-4">
                <p class="text-sm text-destructive mb-3">{{ focusedStep.error }}</p>
                <div class="flex gap-2">
                  <Button size="sm" :disabled="running" @click="retryStep">{{ __('Retry') }}</Button>
                  <Button size="sm" variant="outline" :disabled="running" @click="skipStep">{{ __('Skip') }}</Button>
                </div>
              </div>
            </template>

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
    <div v-if="error" class="mt-4 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
      {{ error }}
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
