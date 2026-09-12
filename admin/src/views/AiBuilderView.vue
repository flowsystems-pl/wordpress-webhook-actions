<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
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
  Sparkles,
  Clock3,
  Wrench,
  History,
  KeyRound,
  ExternalLink,
  BookOpen,
} from 'lucide-vue-next';
import { Button, Input, Switch, Dialog } from '@/components/ui';
import ProviderLogo from '@/components/ProviderLogo.vue';
import AiProviderSettings from '@/components/AiProviderSettings.vue';
import { claimTrial } from '@/composables/useTrial';
import AiDevPanel from '@/components/AiDevPanel.vue';
import ChatMarkdown from '@/components/ChatMarkdown.vue';
import AiPlanStepper from '@/components/AiPlanStepper.vue';
import AiStepPanel from '@/components/AiStepPanel.vue';
import AiBuildsBar from '@/components/AiBuildsBar.vue';
import BuiltWebhookActions from '@/components/BuiltWebhookActions.vue';
import ShareBuildDialog from '@/components/ShareBuildDialog.vue';
import { api } from '@/lib/api';
import { __, sprintf } from '@/i18n';


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
// Machine-readable code for the last failure, so the UI can answer specific ones
// (an exhausted trial needs routes forward, not a red box).
const errorCode = ref('');
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

// ---- Free trial -----------------------------------------------------------
// A trial we could still claim: never started, never spent. This is what lets a
// brand-new install skip setup entirely.
const trialClaimable = computed(
  () => status.value?.trial?.started === false && status.value?.trial?.exhausted !== true,
);

// The gate for the whole builder, and the point of the trial: an install with no
// provider, no key and no account can still type a prompt. We claim the trial
// lazily when they actually send one — asking them to press "start my trial"
// first would just be the API-key detour again, one step shorter.
const canPrompt = computed(() => configured.value || trialClaimable.value);

// Turnstile mounts here. `interaction-only`, so on the overwhelming majority of
// sites it renders nothing at all and is never even reached — the API only
// demands a solved challenge from Playground.
const trialChallenge = ref(null);

/**
 * Make sure this site can talk to a model, claiming the free trial if that is
 * the only way. Returns false when it could not, with `error` already set.
 */
async function ensureTransport() {
  if (configured.value) return true;
  if (!trialClaimable.value) return false;

  try {
    status.value = await claimTrial(trialChallenge.value);
    return configured.value;
  } catch (e) {
    error.value =
      e?.message === 'challenge_failed'
        ? __('The verification challenge could not be completed. Please try again.')
        : e?.message || __('Could not start the free trial.');
    return false;
  }
}

// Active transport (for the model bar + provider logo).
const activeProvider = computed(() => status.value?.active_provider || '');
const activeModel = computed(() => status.value?.active_model || '');

const PROVIDER_LABELS = {
  anthropic: 'Anthropic (Claude)',
  openai: 'OpenAI',
  google: 'Google Gemini',
  hosted: 'WP Webhooks AI',
};
const activeProviderLabel = computed(() => {
  const id = activeProvider.value;
  const wp = status.value?.wp_ai_client?.providers?.find((p) => p.id === id);
  const byo = status.value?.byok?.providers?.find((p) => p.id === id);
  return wp?.label || byo?.label || PROVIDER_LABELS[id] || id;
});

// Two states the raw status cannot express in this bar. A site that has not
// claimed its trial has no active transport at all — but a prompt will work, so
// showing an empty name and a "?" logo would be a lie in the unhelpful
// direction. And the trial transport's id ('hosted_trial') is not a provider
// name, so it would otherwise be printed raw.
const onTrial = computed(() => status.value?.active_transport === 'hosted_trial');
const showsTrialIdentity = computed(() => onTrial.value || (!configured.value && trialClaimable.value));

const barProvider = computed(() => (showsTrialIdentity.value ? 'hosted' : activeProvider.value));
const barTitle = computed(() =>
  showsTrialIdentity.value ? __('WP Webhooks AI') : activeProviderLabel.value,
);
const barSubtitle = computed(() => {
  if (onTrial.value) return __('Free trial');
  if (showsTrialIdentity.value) return __('Free trial — starts with your first prompt');
  return activeModel.value;
});

// Trial credits chip, mirroring the hosted one. Shown BEFORE the trial is
// claimed too — "free trial" without a number is a promise you cannot size, and
// the first question anyone sensible asks is "free how much?". Once claimed,
// each turn refreshes the status so it counts down as the agent works.
const trialCredits = computed(() => {
  if (onTrial.value) return Number(status.value?.trial?.credits || 0);
  if (showsTrialIdentity.value) return Number(status.value?.trial?.grant || 0) || null;
  return null;
});
const trialCreditsLow = computed(() => onTrial.value && Number(trialCredits.value) < 12);

// The server does this arithmetic: the credits-per-run divisor is a measured
// average that will move, and it should move in one place.
const trialRuns = computed(() => Number(status.value?.trial?.runs_left || 0));

// Anything that can answer a prompt other than the trial. Once one of these
// exists the trial being empty stops mattering, so the "you're out" panel must
// disappear — otherwise connecting a key leaves a dead-end message on screen.
const hasOwnModel = computed(() =>
  status.value?.wp_ai_client?.available === true
  || (status.value?.byok?.providers || []).some((p) => p.connected)
  || status.value?.hosted?.available === true,
);

// Running out of credits is not an error the user made — it is the moment the
// product asks them to choose. Driven by the persisted flag as well as the live
// error code, so the routes survive a page reload rather than vanishing with the
// toast that announced them.
const trialSpentNoWayForward = computed(() =>
  !hasOwnModel.value
  && (status.value?.trial?.exhausted === true || errorCode.value === 'fswa_trial_out_of_credits'),
);

const trialTooltip = computed(() => {
  const runs = trialRuns.value;
  const head = onTrial.value
    ? __('%s credits left on your free trial.')
    : __('Your free trial starts with %s credits — nothing to set up, no card, no account.');

  const detail = runs > 0
    ? __('That is roughly %s agent runs. A run is one message you send; most integrations take two or three to plan, build and test.')
        .replace('%s', String(runs))
    : __('Connect your own provider, or add Pro credits, to keep building.');

  return `${head.replace('%s', Number(trialCredits.value || 0).toLocaleString())} ${detail}`;
});

// ---- Empty-state composer -------------------------------------------------
// The blank builder's whole job is to get one sentence out of the user, so the
// prompt box is the page rather than a control at the bottom of it.
//
// The placeholder types itself out, one real prompt after another. A static
// placeholder reads as a label and gets skipped; watching a sentence being
// written answers the question that actually keeps a blank builder blank —
// "what am I supposed to say to it?" — and shows the range of what it accepts
// without a tour, a modal, or a docs link.
const PROMPT_EXAMPLES = [
  __('When a Contact Form 7 form is submitted, send it as JSON to my n8n webhook'),
  __('Every time a WooCommerce order is completed, post the line items to Airtable'),
  __('When a new user registers, add them to my CRM and tag them by role'),
  __('When a post is published, notify my Slack channel with the title and link'),
];

const TYPE_MS = 34;    // per character while writing
const DELETE_MS = 24;  // clearing, only slightly quicker than writing — a fast
                       // rewind snaps and pulls the eye; this stays a backspace
const HOLD_MS = 2200;  // long enough to finish reading the completed sentence
const GAP_MS = 420;    // beat before the next one starts

const typedPlaceholder = ref('');
let typeTimer = null;
let typeState = { example: 0, chars: 0, phase: 'typing' };

const prefersReducedMotion = () =>
  typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches;

// Has this build actually said anything? An open-but-empty build (what "New
// build" makes) is the same moment as no build at all, and must get the same
// centred prompt box — not a transcript window containing nothing.
const hasTranscript = computed(() => !!activeId.value && (transcript.value.length > 0 || sending.value));

const composerFocused = ref(false);

function typeTick() {
  const full = PROMPT_EXAMPLES[typeState.example];

  // Focusing the box settles the animation: finish the sentence being written,
  // then hold it. Motion directly beside a live caret is where it is least
  // welcome, and freezing mid-word would read as a glitch rather than a pause —
  // so "stop" has to mean "complete, then stop". Same path once anything has
  // been typed, where the placeholder is hidden and the timer would only be
  // fighting the input.
  if (composerFocused.value || messageInput.value) {
    if (typeState.chars < full.length) {
      typeState.chars += 1;
      typedPlaceholder.value = full.slice(0, typeState.chars) + '▌';
      typeTimer = setTimeout(typeTick, TYPE_MS);
      return;
    }

    typedPlaceholder.value = full;   // settled, so the caret goes away
    typeState.phase = 'holding';     // resumes into the delete leg on blur
    typeTimer = setTimeout(typeTick, 400);
    return;
  }

  let next = TYPE_MS;

  if (typeState.phase === 'typing') {
    typeState.chars += 1;
    if (typeState.chars >= full.length) {
      typeState.phase = 'holding';
      next = HOLD_MS;
    }
  } else if (typeState.phase === 'holding') {
    typeState.phase = 'deleting';
    next = DELETE_MS;
  } else {
    typeState.chars -= 1;
    next = DELETE_MS;
    if (typeState.chars <= 0) {
      typeState.chars = 0;
      typeState.phase = 'typing';
      typeState.example = (typeState.example + 1) % PROMPT_EXAMPLES.length;
      next = GAP_MS;
    }
  }

  // The block caret matches the terminal styling used across this admin, and it
  // is what makes the text read as being written rather than merely truncated.
  const text = full.slice(0, typeState.chars);
  typedPlaceholder.value = typeState.phase === 'holding' ? text : text + '▌';

  typeTimer = setTimeout(typeTick, next);
}

onMounted(() => {
  if (prefersReducedMotion()) {
    typedPlaceholder.value = PROMPT_EXAMPLES[0];
    return;
  }
  typeTick();
});
onBeforeUnmount(() => { if (typeTimer) clearTimeout(typeTimer); });

// Tab, on an empty composer, accepts the example being typed/held — like a
// shell or address-bar autocomplete — instead of just tabbing focus away.
// Once there's real input, Tab goes back to behaving normally.
function acceptTypedPlaceholder(event) {
  if (messageInput.value) return;
  event.preventDefault();
  messageInput.value = PROMPT_EXAMPLES[typeState.example];
}

// ---- Hosted credits (Pro) -------------------------------------------------
// Shown while the hosted transport is active; each turn response carries the
// fresh balance (res.hosted), so the chip counts down as the agent works.
const hostedCredits = computed(() => {
  const h = status.value?.hosted;
  return h?.available && status.value?.source === 'hosted' ? h : null;
});
const creditsLeft = computed(() =>
  Number(hostedCredits.value?.monthly_remaining || 0) + Number(hostedCredits.value?.topup_remaining || 0)
);
const creditsLow = computed(() => {
  const limit = Number(hostedCredits.value?.monthly_limit || 0);
  return creditsLeft.value < Math.max(50, limit * 0.05);
});
const creditsTooltip = computed(() => {
  const h = hostedCredits.value;
  if (!h?.resets_at) return '';
  const d = new Date(h.resets_at);
  return isNaN(d) ? '' : __('Monthly credits reset on %s.').replace('%s', d.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' }));
});

// Live "time until reset" counter. `now` ticks each minute so the chip counts
// down without a page refresh.
const now = ref(Date.now());
let resetTimer = null;
onMounted(() => { resetTimer = setInterval(() => { now.value = Date.now(); }, 60000); });
onBeforeUnmount(() => { if (resetTimer) clearInterval(resetTimer); });

const resetCountdown = computed(() => {
  const iso = hostedCredits.value?.resets_at;
  if (!iso) return '';
  const then = new Date(iso).getTime();
  if (isNaN(then)) return '';
  const ms = then - now.value;
  if (ms <= 0) return __('resets soon');
  const mins = Math.floor(ms / 60000);
  const days = Math.floor(mins / 1440);
  const hours = Math.floor((mins % 1440) / 60);
  if (days >= 1) return sprintf(__('resets in %1$dd %2$dh'), days, hours);
  return sprintf(__('resets in %1$dh %2$dm'), hours, mins % 60);
});

// Every turn response carries the balances the turn just changed, so the credits
// chip counts down as the agent works instead of showing whatever it read at
// page load. The trial needs this as much as the Pro balance does: a chip frozen
// at the full grant makes a draining trial look free right up until it isn't.
function applyCredits(res) {
  if (!status.value) return;

  if (res?.hosted) {
    status.value = { ...status.value, hosted: res.hosted };
  }
  if (res?.trial) {
    status.value = { ...status.value, trial: res.trial };
  }
}

// The provider panel lives ABOVE the chat, so opening it from a button further
// down the page silently expands something off-screen — the click appears to do
// nothing. Scroll it into view after Vue has actually mounted it.
const providerPanel = ref(null);

async function openProviderSettings() {
  showSettings.value = true;
  await nextTick();
  providerPanel.value?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

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
    errorCode.value = '';
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
    errorCode.value = '';
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

// Which fix event continues the run once a credential exists, for the two
// blocked states that offer a "create inline" credential control (a
// blocked_input credential field patches its own key instead — see below).
function advanceWithCredential(credentialId, kind) {
  return advance(
    kind === 'dispatch'
      ? { dispatch_fix: { auth_credential_id: credentialId } }
      : { probe_fix: { auth_credential_id: credentialId } }
  );
}

// Create a credential in the vault (from AiStepControls) and continue the step with
// it — so the user never leaves the build to set up auth. Three entry points:
//   • probe/dispatch auth-fail (no inputKey, `kind` says which): assign to the
//     webhook and retry that step.
//   • blocked_input credential field (inputKey): patch the new id into that field.
async function onCreateCredential({ payload, inputKey, kind } = {}) {
  if (creatingCred.value || !payload) return;
  creatingCred.value = true;
  error.value = '';
    errorCode.value = '';
  try {
    const created = await api.credentials.create(payload);
    await loadCredentials();
    if (inputKey) {
      advance({ patch: { [inputKey]: Number(created.id) } });
    } else {
      advanceWithCredential(Number(created.id), kind);
    }
  } catch (e) {
    error.value = e.message;
  } finally {
    creatingCred.value = false;
  }
}

// Mint a WP Application Password for the current admin server-side, store it as a
// basic vault credential, and continue the step with it — the secret never comes
// back to the browser. Same entry points as onCreateCredential.
async function onProvisionAppPassword({ inputKey, kind } = {}) {
  if (creatingCred.value) return;
  creatingCred.value = true;
  error.value = '';
    errorCode.value = '';
  try {
    const created = await api.credentials.provisionAppPassword();
    await loadCredentials();
    if (inputKey) {
      advance({ patch: { [inputKey]: Number(created.id) } });
    } else {
      advanceWithCredential(Number(created.id), kind);
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

// Which panel action composed a user turn, for the transcript's own label. An
// unrecognised origin from a newer build still reads as automatic.
function originLabel(origin) {
  if (origin === 'fix_it') return __('Sent for you by “Fix it”');
  if (origin === 'continuation') return __('Sent for you — the run resumed');
  return __('Sent for you by the panel');
}

// Fold any clarifying questions into the assistant bubble so they're actually
// visible (mirrors how the server stores them in the transcript).
function foldReply(message, questions) {
  const qs = questions || [];
  if (!qs.length) return message || '';
  return [message || '', ...qs.map((q) => `• ${q}`)].filter(Boolean).join('\n');
}

// ---- Chat ----------------------------------------------------------------
// The message whose send failed, so the user can retry without re-typing it —
// with the origin it was sent under, so a retried "Fix it" is still recorded as
// the panel's words rather than the author's.
const retryMessage = ref(null);
const retryOrigin = ref('');

async function send() {
  const text = messageInput.value.trim();
  if (!text || sending.value) return;

  // Claim the trial here, on the first prompt, rather than behind a button on an
  // onboarding screen. `sending` is set first so the composer shows a spinner
  // while the (usually invisible) challenge resolves.
  if (!configured.value) {
    sending.value = true;
    error.value = '';
    errorCode.value = '';
    const ok = await ensureTransport();
    sending.value = false;
    if (!ok) return;
  }

  if (!activeId.value) {
    await newChat();
  }

  sending.value = true;
  error.value = '';
    errorCode.value = '';
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
  const origin = retryOrigin.value;
  sending.value = true;
  error.value = '';
    errorCode.value = '';
  retryMessage.value = null;
  revealFrom.value = transcript.value.length; // animate the resumed reply
  await dispatchMessage(text, origin);
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

// `origin` is empty for a message the user typed, and names the panel action
// that composed it otherwise ('fix_it', 'continuation') — see PANEL_ORIGINS in
// AgentOrchestrator. It only affects how the turn reads back in the transcript;
// the model receives it as the user's turn either way.
async function dispatchMessage(text, origin = '') {
  const convId = activeId.value;
  const poll = pollTurnProgress(convId);
  try {
    const res = await api.agent.message(convId, text, origin);
    clearInterval(poll);
    applyCredits(res);
    try {
      await reloadTranscript(convId);
    } catch {
      // Fallback: append locally like before (reads as one activity line).
      if (res.activity?.length) {
        transcript.value.push({ role: 'tool', reads: res.activity });
      }
      transcript.value.push({ role: 'assistant', content: foldReply(res.assistant_message, res.clarifying_questions), notice: res.notice || undefined, api_docs: res.api_docs?.length ? res.api_docs : undefined });
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
    errorCode.value = e.code || '';
    retryMessage.value = text;
    retryOrigin.value = origin;
    // A FAILED turn changes the balance too — running out of credits IS the
    // failure. Successful turns carry the fresh balance in the response, but an
    // error carries nothing, so the chip would keep showing the credits the user
    // no longer has, next to a message saying they have none. Only on failure,
    // so the normal path still costs no extra round-trip.
    await refreshStatus();
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
    errorCode.value = '';
  let continuation = null; // outlives the try: dispatched after `running` clears
  try {
    let first = true;
    let keepGoing = true;
    while (keepGoing) {
      const startedAt = Date.now();
      const res = await api.agent.step(activeId.value, first ? opts : {});
      continuation = res.continuation || null;
      first = false;
      applyCredits(res);
      // Hold the "running" presentation so even instant steps are noticeable,
      // THEN apply the result (the step flips to done / blocked in one beat).
      const hold = MIN_STEP_MS - (Date.now() - startedAt);
      if (hold > 0) await sleep(hold);
      execution.value = res.execution;
      // The run appends its step outcomes to the transcript when it stops, so
      // the next message the user sends carries them to the model.
      if (res.transcript) transcript.value = res.transcript;
      focusedIndex.value = null; // follow the cursor as it advances
      devPanel.value?.refresh();
      keepGoing = res.continue;
      if (keepGoing) await sleep(DONE_FLASH_MS);
    }
    await loadConversations();
  } catch (e) {
    error.value = e.message;
    continuation = null; // a failed run is not a finished one
  } finally {
    running.value = false;
  }

  // The run finished, but it finished on a step whose only job was to wait for
  // something the agent needed before it could plan the rest (a captured
  // payload). Hand that straight back to it rather than leaving a half-built
  // webhook and a silent screen — the user already did their part. Dispatched
  // outside the try/finally above because dispatchMessage() starts the NEXT
  // run itself in auto mode, and it must own `running` from here on.
  if (continuation) {
    sending.value = true;
    revealFrom.value = transcript.value.length;
    transcript.value.push({ role: 'user', content: continuation, origin: 'continuation' });
    await scrollDown();
    await dispatchMessage(continuation, 'continuation');
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

// "Fix it" on a blocked step: hand the failure back to the agent as a turn so it
// proposes a corrected plan. Retry re-runs the same broken build, which is only
// useful once something has changed — this is the button for when nothing has.
// The endpoint's own response goes in the message because that is the part that
// says WHY, and the user should never have to copy it across themselves.
async function fixStep() {
  const step = currentStep.value;
  if (!step || sending.value || running.value) return;

  const detail = step.dispatch || step.probe || null;
  const parts = [
    sprintf(
      __('Step %1$s (%2$s) did not succeed: %3$s'),
      step.id,
      step.ability,
      detail?.message || step.error || __('the step failed.')
    ),
  ];
  if (detail?.response) {
    parts.push(__('The endpoint responded:') + '\n' + detail.response);
  }
  parts.push(__('Fix the build so this passes — adjust the field mapping or the pre-dispatch Code Glue snippet as needed — then test again before enabling.'));
  const text = parts.join('\n\n');

  sending.value = true;
  revealFrom.value = transcript.value.length;
  transcript.value.push({ role: 'user', content: text, origin: 'fix_it' });
  await scrollDown();
  await dispatchMessage(text, 'fix_it');
}
function skipStep() {
  advance({ skip: true });
}

// Revert the most recent applied change. Repeated clicks walk further back.
const REVERTIBLE_ABILITIES = ['create_webhook', 'update_webhook', 'set_mapping', 'set_conditions', 'assign_credential', 'enable_webhook'];
const hasRevertible = computed(() =>
  execSteps.value.some((s) => s.status === 'done' && REVERTIBLE_ABILITIES.includes(s.ability))
);

// Share = export what this conversation built (the server resolves the object
// set from the run's applied steps), so it needs at least one applied step.
// Publishing is the same document going to wpwebhooks.org instead of to disk,
// and needs an active Pro license.
const shareOpen = ref(false);
const shareMode = ref('export');
const canShareBuild = computed(() =>
  !!activeId.value && execSteps.value.some((s) => s.status === 'done')
);
// Publishing is refused server-side from a Playground sandbox or an address
// nothing on the internet can reach, so ask rather than sniffing the hostname.
const publishEligibility = ref({ can_publish: true, reason: '' });
api.builds.publishEligibility()
  .then((d) => { publishEligibility.value = d; })
  .catch(() => {});

const canPublishBuild = computed(() => canShareBuild.value && publishEligibility.value.can_publish);
const publishBlockedReason = computed(() => publishEligibility.value.reason || '');

function openShare(mode) {
  shareMode.value = mode;
  shareOpen.value = true;
}
const activeTitle = computed(() =>
  conversations.value.find((c) => String(c.id) === String(activeId.value))?.title || ''
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
  canShare: canShareBuild.value,
  canPublish: canPublishBuild.value,
}));

async function revertLast() {
  if (running.value || !activeId.value) return;
  running.value = true;
  error.value = '';
    errorCode.value = '';
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
      {{ __('Describe the integration or automation you want. The agent proposes a plan you can review and ask for changes, then builds and tests it for you.') }}
    </p>

    <div v-if="loading" class="flex items-center gap-2 text-muted-foreground">
      <Loader2 class="w-4 h-4 animate-spin" /> {{ __('Loading…') }}
    </div>

    <template v-else>
    <!-- Setup card -------------------------------------------------------- -->
    <!-- Last resort only: no provider AND no trial left to claim. A fresh install
         never sees this — it goes straight to the composer and the trial is
         claimed on the first prompt. What lands here is a site whose trial is
         spent and which has connected nothing, i.e. someone who now genuinely
         does have a decision to make. -->
    <div v-if="!canPrompt && !conversations.length" class="rounded-lg border border-border bg-card p-6 max-w-2xl">
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
    <!-- A provider, or a trial we can still claim: the full builder. Neither, but
         past builds exist: those builds, read-only. Nothing at all when there is
         neither — the setup card stands alone. -->
    <div v-if="canPrompt || conversations.length" class="space-y-4">
      <!-- Nothing to prompt with, but there ARE builds: a one-line bar rather than
           the full card, so the builds themselves stay above the fold. Same
           settings, one click away — it expands into the very same
           AiProviderSettings. -->
      <div v-if="!canPrompt" class="rounded-lg border border-border bg-card">
        <div class="flex items-center justify-between gap-3 px-4 py-3">
          <div class="flex items-start gap-2 min-w-0 text-sm text-muted-foreground">
            <History class="w-4 h-4 shrink-0 mt-0.5" />
            <span>{{ __('Your previous builds, read-only. Connect a provider to continue building.') }}</span>
          </div>
          <Button variant="outline" size="sm" class="shrink-0" @click="showSettings = !showSettings">
            <Settings2 class="w-4 h-4 mr-1.5" />
            {{ __('Connect') }}
          </Button>
        </div>
        <div v-if="showSettings" ref="providerPanel" class="border-t border-border p-4">
          <AiProviderSettings :status="status" @update="onSettingsUpdate" />
        </div>
      </div>

      <!-- Active model bar + expandable provider settings -->
      <div v-else class="rounded-lg border border-border bg-card">
        <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
          <div class="flex flex-wrap items-center gap-2 min-w-0">
            <ProviderLogo :provider="barProvider" :size="36" />
            <div class="min-w-0">
              <div class="text-sm font-medium text-foreground truncate">{{ barTitle }}</div>
              <div :class="['text-xs text-muted-foreground truncate', !showsTrialIdentity && 'font-mono']">
                {{ barSubtitle }}
              </div>
            </div>
            <span v-if="trialCredits !== null" :title="trialTooltip"
              :class="['inline-flex cursor-help items-center gap-1 rounded-full border px-2 py-0.5 text-xs tabular-nums shrink-0',
                trialCreditsLow ? 'border-amber-400/50 bg-amber-50/60 dark:bg-amber-950/20 text-amber-700 dark:text-amber-300'
                                : 'border-primary/30 bg-primary/10 text-primary']">
              <Sparkles class="w-3.5 h-3.5" />
              <template v-if="onTrial">
                {{ __('%s credits left').replace('%s', trialCredits.toLocaleString()) }}
              </template>
              <template v-else>
                {{ __('%s free credits').replace('%s', trialCredits.toLocaleString()) }}
              </template>
            </span>
            <span v-if="hostedCredits" :title="creditsTooltip"
              :class="['inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs tabular-nums shrink-0',
                creditsLow ? 'border-amber-400/50 bg-amber-50/60 dark:bg-amber-950/20 text-amber-700 dark:text-amber-300'
                           : 'border-primary/30 bg-primary/10 text-primary']">
              <Sparkles class="w-3.5 h-3.5" />
              {{ __('%s credits left').replace('%s', creditsLeft.toLocaleString()) }}
            </span>
            <span v-if="hostedCredits && resetCountdown" :title="creditsTooltip"
              class="inline-flex items-center gap-1 rounded-full border border-border bg-muted/40 px-2 py-0.5 text-xs text-muted-foreground shrink-0">
              <Clock3 class="w-3.5 h-3.5" />
              {{ resetCountdown }}
            </span>
          </div>
          <div class="flex flex-wrap items-center gap-3">
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

        <div v-if="showSettings" ref="providerPanel" class="border-t border-border p-4">
          <AiProviderSettings :status="status" @update="onSettingsUpdate" />
        </div>
      </div>

      <!-- Builds bar: switcher + delete + new, above the conversation window -->
      <AiBuildsBar :conversations="conversations" :active-id="activeId" :can-create="canPrompt"
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
        <!-- ── Empty state ───────────────────────────────────────────────────
             Nothing said yet — either no build open, or one opened by "New
             build" that has no messages in it. Both are the same moment: the one
             thing that has to happen is that a sentence gets typed, so the
             prompt box IS the page. Centred, given room, nothing competing.
             send() creates the build (if there isn't one) and claims the free
             trial, so this is genuinely the first and only step. -->
        <div v-if="canPrompt && !hasTranscript" class="flex flex-col items-center py-10 sm:py-16">
          <BrainCircuit class="w-8 h-8 text-primary mb-3" />
          <h3 class="text-xl sm:text-2xl font-semibold text-foreground text-center">
            {{ __('Let’s build something.') }}
          </h3>
          <p class="mt-1.5 mb-6 max-w-md text-center text-sm text-muted-foreground">
            {{ __('Describe the integration in plain words. The agent plans it, then builds and tests it for you.') }}
          </p>

          <form @submit.prevent="send" class="w-full max-w-2xl">
            <div class="rounded-2xl border border-border bg-card p-3 transition-colors focus-within:border-primary/60">
              <!-- Enter sends, Shift+Enter breaks the line: this is a prompt, not
                   a form field, and the examples it invites are long enough to
                   want wrapping. -->
              <!-- The `!` overrides are load-bearing: WordPress admin CSS styles
                   every textarea with its own border, background and box-shadow,
                   which would draw a second input box inside this card. -->
              <textarea v-model="messageInput" rows="2"
                :placeholder="typedPlaceholder"
                class="w-full resize-none px-2 pt-1 text-sm leading-relaxed text-foreground placeholder:text-muted-foreground
                       !border-0 !bg-transparent !shadow-none focus:!outline-none focus:!ring-0 focus:!shadow-none"
                @focus="composerFocused = true" @blur="composerFocused = false"
                @keydown.enter.exact.prevent="send" @keydown.tab="acceptTypedPlaceholder"></textarea>

              <div class="flex items-center justify-between gap-3 pt-2">
                <span class="pl-2 text-xs text-muted-foreground" :title="!configured ? trialTooltip : ''">
                  <template v-if="!configured && trialRuns > 0">
                    {{ __('No API key needed — %1$s free credits, about %2$s builds.')
                        .replace('%1$s', Number(trialCredits || 0).toLocaleString())
                        .replace('%2$s', String(trialRuns)) }}
                  </template>
                  <template v-else-if="!configured">{{ __('No API key needed — your first builds are free.') }}</template>
                  <template v-else>{{ __('Enter to build, Shift+Enter for a new line.') }}</template>
                </span>
                <button type="submit" :disabled="sending || !messageInput.trim()"
                  class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground disabled:opacity-50">
                  <Loader2 v-if="sending" class="w-4 h-4 animate-spin" />
                  <WandSparkles v-else class="w-4 h-4" />
                  {{ __('Build') }}
                </button>
              </div>
            </div>
          </form>
        </div>

        <!-- Nothing to prompt with and no build open: say so, plainly. -->
        <div v-else-if="!activeId"
          class="rounded-lg border border-dashed border-border p-8 text-center text-sm text-muted-foreground">
          {{ __('Start a new build, then describe what you want to integrate.') }}
        </div>

        <!-- Turnstile mounts here on the first prompt of an unconfigured site.
             `interaction-only`, and only reached where the API asks for a
             challenge at all — so on an ordinary install it stays empty and
             Cloudflare is never contacted. -->
        <div ref="trialChallenge" class="flex justify-center empty:hidden"></div>

        <template v-if="hasTranscript">
          <!-- Transcript -->
          <div ref="transcriptEl" class="rounded-lg border border-border bg-card p-4 max-h-[300px] overflow-y-auto space-y-3">
            <template v-for="(m, i) in transcript" :key="i">
              <!-- Read activity: abilities the agent ran itself to gather data.
                   Step-result entries are also role "tool" but carry no reads —
                   they exist to feed the model what the plan did, and the run
                   panel already shows the user each step's outcome. -->
              <div v-if="m.role === 'tool'" v-show="(m.reads || []).length"
                class="flex items-center flex-wrap gap-1.5 px-1 text-xs text-muted-foreground">
                <Search class="w-3.5 h-3.5 shrink-0" />
                <span v-for="(r, j) in (m.reads || [])" :key="j"
                  class="rounded bg-muted px-1.5 py-0.5 font-mono">{{ readLabel(r) }}</span>
              </div>
              <!-- A user turn the PANEL composed, not the user: "Fix it" handing
                   a failed step's own error back to the agent, or the run
                   resuming itself once a payload was captured. It still goes to
                   the model as their turn, so it keeps their side of the thread —
                   but dashed and monospaced, with the button that sent it named,
                   so it never reads as something they typed. -->
              <div v-else-if="m.content && m.origin" class="flex justify-end">
                <div class="max-w-[80%] min-w-0 rounded-lg border border-dashed border-border px-3 py-2">
                  <div class="flex items-center gap-1.5 text-[11px] font-medium uppercase tracking-wide text-muted-foreground mb-1">
                    <Wrench v-if="m.origin === 'fix_it'" class="w-3 h-3 shrink-0" />
                    <Clock3 v-else class="w-3 h-3 shrink-0" />
                    <span>{{ originLabel(m.origin) }}</span>
                  </div>
                  <div class="whitespace-pre-wrap font-mono text-xs text-muted-foreground">{{ m.content }}</div>
                </div>
              </div>
              <!-- The API Docs Library is reading a service's reference for the
                   first time. A transient entry the server writes while the
                   turn waits, and removes once the reply lands. -->
              <div v-else-if="m.researching" class="flex justify-start">
                <div class="max-w-[80%] flex items-center gap-2 rounded-lg border border-dashed border-border px-3 py-2 text-sm text-muted-foreground">
                  <Loader2 class="w-4 h-4 shrink-0 animate-spin" />
                  <span>{{ m.content }}</span>
                </div>
              </div>
              <div v-else-if="m.content"
                :class="['flex', m.role === 'user' ? 'justify-end' : 'justify-start']">
                <div :class="['max-w-[80%] min-w-0 rounded-lg px-3 py-2 text-sm',
                  m.role === 'user' ? 'bg-primary text-primary-foreground whitespace-pre-wrap' : 'bg-muted text-foreground']">
                  <ChatMarkdown v-if="m.role === 'assistant'" :text="m.content" :animate="i >= revealFrom" />
                  <template v-else>{{ m.content }}</template>
                </div>
              </div>
              <!-- The reply was built against a reference card from the WP Webhooks
                   API Docs Library (injected server-side), not from the model's
                   memory of the service's API. Same badge family as the Payload
                   Library one on the plan steps. -->
              <div v-if="m.api_docs?.length" class="flex justify-start">
                <div class="max-w-[80%] flex flex-wrap items-center gap-x-2 gap-y-1 px-1 text-[11px] text-muted-foreground">
                  <span class="inline-flex items-center gap-1 rounded bg-yellow-500/15 px-1.5 py-0.5 font-medium text-yellow-700 dark:text-yellow-400"
                    :title="__('The AI read this reference on our server — the request shape below comes from the vendor\'s documentation, not from memory.')">
                    <BookOpen class="w-3 h-3" /> {{ __('WP Webhooks API Docs Library') }}
                  </span>
                  <a v-for="(d, j) in m.api_docs" :key="j" :href="d.docs_url" target="_blank" rel="noopener noreferrer"
                    class="underline decoration-dotted underline-offset-2 hover:text-foreground">
                    {{ d.service }} — {{ d.operation }}<template v-if="d.verified_at"> · {{ sprintf(__('verified %s'), d.verified_at) }}</template><template v-if="d.source === 'researched'"> · {{ __('auto-researched') }}</template>
                  </a>
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

          <!-- Composer, compact. Once a conversation is underway the transcript is
               the subject and this is a control beneath it — the opposite of the
               empty state, where it is the whole point of the screen. -->
          <form v-if="canPrompt" @submit.prevent="send" class="flex gap-2">
            <Input v-model="messageInput" type="text" :placeholder="__('Describe what to build…')"
              class="flex-1" />
            <button type="submit" :disabled="sending || !messageInput.trim()"
              class="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground disabled:opacity-50">
              <Loader2 v-if="sending" class="w-4 h-4 animate-spin" />
              <WandSparkles v-else class="w-4 h-4" />
              {{ __('Build') }}
            </button>
          </form>

          <!-- Focused step detail (one step at a time). Its actions all call the
               agent, so it is hidden without a provider rather than shown inert. -->
          <AiStepPanel v-if="configured && execution && focusedStep"
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
            @dispatch-fix="(fix) => advance({ dispatch_fix: fix })"
            @fix-it="fixStep"
            @create-credential="onCreateCredential"
            @provision-app-password="onProvisionAppPassword"
            @enable="enableBuiltWebhook"
            @toggle-sync="setBuiltWebhookSync"
            @revert="revertLast"
            @share="openShare('export')"
            @publish="openShare('publish')"
          />

          <!-- Finished, nothing focused -->
          <div v-else-if="configured && execution && execFinished"
            class="rounded-lg border border-border bg-card p-5 flex flex-wrap items-center gap-3 text-sm">
            <span class="flex items-center gap-2 text-emerald-600 dark:text-emerald-400">
              <CheckCircle2 class="w-5 h-5" /> {{ __('Build complete.') }}
            </span>
            <BuiltWebhookActions v-bind="builtProps"
              @enable="enableBuiltWebhook" @toggle-sync="setBuiltWebhookSync" @revert="revertLast"
              @share="openShare('export')" @publish="openShare('publish')" />
          </div>
        </template>
      </section>
      </div>
    </div>
    </template>

    <!-- Trial spent. Three ways on, two of them free — shown INSTEAD of the red
         toast, because "you have run out" with no route forward is the moment a
         user closes the tab. Everything already built stays on the site. -->
    <div v-if="trialSpentNoWayForward"
      class="mt-4 rounded-lg border border-primary/30 bg-primary/5 p-4 space-y-3">
      <div class="flex items-start gap-2">
        <Sparkles class="w-4 h-4 text-primary shrink-0 mt-0.5" />
        <div class="min-w-0">
          <p class="text-sm font-medium text-foreground">
            {{ __('Your free trial is used up.') }}
          </p>
          <p class="mt-0.5 text-xs text-muted-foreground">
            {{ __('Everything you have built stays on this site. Connect a model to keep going — the first two cost nothing.') }}
          </p>
        </div>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <Button size="sm" @click="openProviderSettings">
          <KeyRound class="w-4 h-4 mr-1.5" /> {{ __('Connect your own key') }}
        </Button>
        <a href="https://wpwebhooks.org/docs/get-google-ai-studio-api-key/"
          target="_blank" rel="noopener noreferrer">
          <Button size="sm" variant="outline">
            <ExternalLink class="w-4 h-4 mr-1.5" /> {{ __('Get a free Gemini key') }}
          </Button>
        </a>
        <a href="https://wpwebhooks.org/pricing/#pricing"
          target="_blank" rel="noopener noreferrer">
          <Button size="sm" variant="outline">{{ __('Get Pro credits') }}</Button>
        </a>
      </div>
    </div>

    <!-- Error toast -->
    <div v-if="error && !trialSpentNoWayForward" class="mt-4 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive flex items-center justify-between gap-3">
      <span>{{ error }}</span>
      <Button v-if="retryMessage" size="sm" variant="outline" :disabled="sending" @click="retrySend">
        <RotateCcw class="w-4 h-4 mr-1.5" /> {{ __('Retry') }}
      </Button>
    </div>

    <!-- Share/export this build -->
    <ShareBuildDialog
      :open="shareOpen"
      :mode="shareMode"
      :conversation-id="activeId"
      :title="activeTitle"
      @close="shareOpen = false"
    />

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
