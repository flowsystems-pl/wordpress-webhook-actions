<script setup>
import { ref, computed, watch, onMounted, nextTick } from 'vue';
import {
  BrainCircuit,
  WandSparkles,
  Trash2,
  CheckCircle2,
  AlertCircle,
  Loader2,
  Search,
  Settings2,
  RotateCcw,
} from 'lucide-vue-next';
import { Button, Input, Switch, Dialog } from '@/components/ui';
import ProviderLogo from '@/components/ProviderLogo.vue';
import AiProviderSettings from '@/components/AiProviderSettings.vue';
import AiDevPanel from '@/components/AiDevPanel.vue';
import ChatMarkdown from '@/components/ChatMarkdown.vue';
import AiPlanStepper from '@/components/AiPlanStepper.vue';
import AiStepPanel from '@/components/AiStepPanel.vue';
import AiBuildsBar from '@/components/AiBuildsBar.vue';
import BuiltWebhookActions from '@/components/BuiltWebhookActions.vue';
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
// Transcript index from which assistant replies are "new this turn" and get the
// reveal animation. Infinity = animate nothing (loaded history renders instantly).
const revealFrom = ref(Infinity);

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
// Delivery mode of the built webhook: true = synchronous (inline), false =
// asynchronous (queued). null = unknown / not fetched. Lets the user flip the
// mode the AI chose without leaving the builder.
const builtWebhookSync = ref(null);
const savingSync = ref(false);
const syncTooltip = __('Asynchronous: queued and delivered in the background on the next cron run, with automatic retries — it doesn’t slow the triggering request, but nothing is sent until a working cron runs. Synchronous: delivered instantly, inline with the triggering request — it works without any cron, at the cost of a little added latency. Toggle to switch.');

watch([execFinished, builtWebhookId], async ([finished, id]) => {
  builtWebhookEnabled.value = null;
  builtWebhookSync.value = null;
  justEnabled.value = false;
  if (!finished || !id) return;
  try {
    const wh = await api.webhooks.get(id);
    builtWebhookEnabled.value = Number(wh.is_enabled) === 1;
    builtWebhookSync.value = wh.is_synchronous === true || Number(wh.is_synchronous) === 1;
  } catch (e) {
    // Leave unknown — no offer rather than a wrong one.
  }
}, { immediate: true });

async function setBuiltWebhookSync(val) {
  if (savingSync.value || !builtWebhookId.value) return;
  savingSync.value = true;
  error.value = '';
  const previous = builtWebhookSync.value;
  builtWebhookSync.value = val; // optimistic
  try {
    await api.webhooks.update(builtWebhookId.value, { is_synchronous: val });
  } catch (e) {
    builtWebhookSync.value = previous; // roll back on failure
    error.value = e.message;
  } finally {
    savingSync.value = false;
  }
}

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

// Create a credential in the vault (from AiStepControls) and continue the step with
// it — so the user never leaves the build to set up auth. Two entry points:
//   • probe auth-fail (no inputKey): assign to the probed webhook and re-probe.
//   • blocked_input credential field (inputKey): patch the new id into that field.
async function onCreateCredential({ payload, inputKey } = {}) {
  if (creatingCred.value || !payload) return;
  creatingCred.value = true;
  error.value = '';
  try {
    const created = await api.credentials.create(payload);
    await loadCredentials();
    if (inputKey) {
      advance({ patch: { [inputKey]: Number(created.id) } });
    } else {
      advance({ probe_fix: { auth_credential_id: Number(created.id) } });
    }
  } catch (e) {
    error.value = e.message;
  } finally {
    creatingCred.value = false;
  }
}

// Mint a WP Application Password for the current admin server-side, store it as a
// basic vault credential, and continue the step with it — the secret never comes
// back to the browser. Same two entry points as onCreateCredential.
async function onProvisionAppPassword({ inputKey } = {}) {
  if (creatingCred.value) return;
  creatingCred.value = true;
  error.value = '';
  try {
    const created = await api.credentials.provisionAppPassword();
    await loadCredentials();
    if (inputKey) {
      advance({ patch: { [inputKey]: Number(created.id) } });
    } else {
      advance({ probe_fix: { auth_credential_id: Number(created.id) } });
    }
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
  revealFrom.value = Infinity; // loaded history renders instantly, no reveal
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
        revealFrom.value = Infinity;
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

// One chip per read the agent executed, e.g. "get_trigger_schema · wpcf7_mail_sent".
function readLabel(read) {
  const input = read.input || {};
  const hint = input.trigger || input.webhook_id || input.id || '';
  return hint ? `${read.ability} · ${hint}` : read.ability;
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
  revealFrom.value = transcript.value.length; // animate replies from this turn on
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
  revealFrom.value = transcript.value.length; // animate the resumed reply
  await dispatchMessage(text);
}

// While a turn runs server-side the orchestrator persists each completed read
// round, so polling the conversation surfaces live progress ("Checking the
// captured payload…" + read chips) instead of a silent spinner.
function pollTurnProgress(convId) {
  let fetching = false;
  return setInterval(async () => {
    if (fetching) return;
    fetching = true;
    try {
      const full = await api.agent.getConversation(convId);
      const t = full.transcript_json || [];
      // Only grow, and only for the conversation still on screen — an in-flight
      // poll must never clobber the final reply or a switched conversation.
      if (String(activeId.value) === String(convId) && t.length > transcript.value.length) {
        transcript.value = t;
        await scrollDown();
      }
    } catch { /* transient — keep polling */ } finally {
      fetching = false;
    }
  }, 1500);
}

// The stored transcript is canonical (interim read rounds, error notices, the
// final reply) — reload it rather than appending locally on top of what the
// progress poller may already have shown.
async function reloadTranscript(convId) {
  const full = await api.agent.getConversation(convId);
  if (String(activeId.value) === String(convId)) {
    transcript.value = full.transcript_json || [];
  }
}

async function dispatchMessage(text) {
  const convId = activeId.value;
  const poll = pollTurnProgress(convId);
  try {
    const res = await api.agent.message(convId, text);
    clearInterval(poll);
    try {
      await reloadTranscript(convId);
    } catch {
      // Fallback: append locally like before (reads as one activity line).
      if (res.activity?.length) {
        transcript.value.push({ role: 'tool', reads: res.activity });
      }
      transcript.value.push({ role: 'assistant', content: foldReply(res.assistant_message, res.clarifying_questions), notice: res.notice || undefined });
    }
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
    // The failed turn is persisted server-side (completed read rounds + an
    // error notice bubble) — show it; retrying the same message resumes there.
    try { await reloadTranscript(convId); } catch { /* keep local view */ }
    error.value = e.message;
    retryMessage.value = text;
    devPanel.value?.refresh();
  } finally {
    clearInterval(poll);
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

// Prop bundle for BuiltWebhookActions (used directly and via AiStepPanel).
const builtProps = computed(() => ({
  webhookId: builtWebhookId.value,
  enabled: builtWebhookEnabled.value,
  justEnabled: justEnabled.value,
  enabling: enablingWebhook.value,
  sync: builtWebhookSync.value,
  savingSync: savingSync.value,
  syncTooltip,
  hasRevertible: hasRevertible.value,
  running: running.value,
}));

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
      <AiBuildsBar :conversations="conversations" :active-id="activeId"
        @switch="onSwitchConversation" @delete="removeConversation({ id: activeId })" @new="newChat" />

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
            <template v-for="(m, i) in transcript" :key="i">
              <!-- Read activity: abilities the agent ran itself to gather data -->
              <div v-if="m.role === 'tool'" class="flex items-center flex-wrap gap-1.5 px-1 text-xs text-muted-foreground">
                <Search class="w-3.5 h-3.5 shrink-0" />
                <span v-for="(r, j) in (m.reads || [])" :key="j"
                  class="rounded bg-muted px-1.5 py-0.5 font-mono">{{ readLabel(r) }}</span>
              </div>
              <div v-else-if="m.content"
                :class="['flex', m.role === 'user' ? 'justify-end' : 'justify-start']">
                <div :class="['max-w-[80%] min-w-0 rounded-lg px-3 py-2 text-sm',
                  m.role === 'user' ? 'bg-primary text-primary-foreground whitespace-pre-wrap' : 'bg-muted text-foreground']">
                  <ChatMarkdown v-if="m.role === 'assistant'" :text="m.content" :animate="i >= revealFrom" />
                  <template v-else>{{ m.content }}</template>
                </div>
              </div>
              <!-- Provider fallback notice: the selected model failed, another answered -->
              <div v-if="m.notice" class="flex justify-start">
                <div class="max-w-[80%] flex items-start gap-1.5 rounded-md border border-amber-400/40 bg-amber-50/50 dark:bg-amber-950/20 px-2.5 py-1.5 text-xs text-amber-700 dark:text-amber-300">
                  <AlertCircle class="w-3.5 h-3.5 shrink-0 mt-0.5" />
                  <span>{{ m.notice }}</span>
                </div>
              </div>
            </template>
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
          <AiStepPanel v-if="execution && focusedStep"
            :step="focusedStep"
            :step-number="(focusedIndex ?? execCursor) + 1"
            :step-count="execSteps.length"
            :abilities="abilities"
            :credentials="credentials"
            :is-current="focusedIsCurrent"
            :running="running"
            :busy="running || creatingCred"
            :review-pre-run="reviewPreRun"
            :can-continue="canContinue"
            :finished="execFinished"
            :built="builtProps"
            @advance="(opts) => advance(opts)"
            @confirm="confirmStep"
            @retry="retryStep"
            @skip="skipStep"
            @probe-fix="(fix) => advance({ probe_fix: fix })"
            @create-credential="onCreateCredential"
            @provision-app-password="onProvisionAppPassword"
            @enable="enableBuiltWebhook"
            @toggle-sync="setBuiltWebhookSync"
            @revert="revertLast"
          />

          <!-- Finished, nothing focused -->
          <div v-else-if="execution && execFinished"
            class="rounded-lg border border-border bg-card p-5 flex flex-wrap items-center gap-3 text-sm">
            <span class="flex items-center gap-2 text-emerald-600 dark:text-emerald-400">
              <CheckCircle2 class="w-5 h-5" /> {{ __('Build complete.') }}
            </span>
            <BuiltWebhookActions v-bind="builtProps"
              @enable="enableBuiltWebhook" @toggle-sync="setBuiltWebhookSync" @revert="revertLast" />
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
