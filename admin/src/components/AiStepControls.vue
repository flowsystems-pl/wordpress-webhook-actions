<script setup>
import { ref, reactive, computed } from 'vue';
import { Play } from 'lucide-vue-next';
import { Button, Input, Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/components/ui';
import { fieldMeta } from '@/lib/aiLabels';
import { __ } from '@/i18n';

// The active controls for the step currently being run: the blocked-state cards
// (missing input, missing payload, probe result) plus confirm/retry/skip. Owns
// all the transient input drafts and emits semantic actions to the parent, which
// drives the execution loop. Re-mounted per step (keyed on id+status), so drafts
// reset automatically.
const props = defineProps({
  step: { type: Object, required: true },
  abilities: { type: Object, default: () => ({}) },
  credentials: { type: Array, default: () => [] },
  busy: { type: Boolean, default: false },
});

const emit = defineEmits(['continue', 'retry', 'skip', 'confirm', 'probe-fix', 'dispatch-fix', 'fix-it', 'create-credential', 'provision-app-password']);

// ---- blocked_input -------------------------------------------------------
const inputDraft = reactive({});

function abilityFor(name) {
  return props.abilities[name] || null;
}

// Fields to ask for on a blocked_input step (the missing keys + their schema meta).
function missingFields(step) {
  const a = abilityFor(step?.ability);
  const specs = a?.input_schema?.properties || {};
  return (step?.missing || []).map((key) => {
    const spec = specs[key] || { type: 'string' };
    return { key, type: spec.type || 'string', enum: spec.enum || null };
  });
}

function continueInput() {
  emit('continue', { ...inputDraft });
}

// A blocked_input step may be missing a vault-credential reference (e.g.
// assign_credential.credential_id, or an auth_credential_id). For those we render
// the same pick-or-create-inline control the probe auth path uses, instead of a
// raw number field, so the user never has to leave the build to wire up auth.
const CRED_KEYS = ['credential_id', 'auth_credential_id'];
const credInputKey = computed(() =>
  (props.step?.missing || []).find((k) => CRED_KEYS.includes(k)) || null
);
const inputCredDraft = ref('');

function continueWithCred() {
  const id = Number(inputCredDraft.value);
  if (!id || !credInputKey.value) return;
  emit('continue', { ...inputDraft, [credInputKey.value]: id });
}

// ---- blocked_probe: endpoint / credential fixes --------------------------
const probeUrlDraft = ref('');
const probeCredDraft = ref('');
const credSearch = ref('');

const filteredCredentials = computed(() => {
  const q = credSearch.value.trim().toLowerCase();
  if (!q) return props.credentials;
  return props.credentials.filter((c) => String(c.name || '').toLowerCase().includes(q));
});

function fixProbeEndpoint() {
  const url = probeUrlDraft.value.trim();
  if (!url) return;
  emit('probe-fix', { endpoint_url: url });
}
function fixProbeAuth() {
  const id = Number(probeCredDraft.value);
  if (!id) return;
  emit('probe-fix', { auth_credential_id: id });
}
// Same picker, but for a test_dispatch that came back 401/403 (blocked_dispatch)
// rather than a probe (blocked_probe) — see the credential-picker markup below.
function fixDispatchAuth() {
  const id = Number(probeCredDraft.value);
  if (!id) return;
  emit('dispatch-fix', { auth_credential_id: id });
}

// ---- inline credential creation ------------------------------------------
const showCreateCred = ref(false);
const newCred = reactive({ name: '', type: 'bearer', secret: '', username: '', password: '', header_name: '' });

const newCredValid = computed(() => {
  if (!newCred.name.trim()) return false;
  if (newCred.type === 'basic') return !!newCred.username && !!newCred.password;
  if (newCred.type === 'api_key' || newCred.type === 'custom') return !!newCred.secret && !!newCred.header_name.trim();
  return !!newCred.secret; // bearer
});

function buildNewCredPayload() {
  const payload = { name: newCred.name.trim(), type: newCred.type };
  if (newCred.type === 'basic') {
    payload.username = newCred.username;
    payload.password = newCred.password;
  } else {
    payload.secret = newCred.secret;
  }
  if (newCred.type === 'api_key' || newCred.type === 'custom') {
    payload.header_name = newCred.header_name.trim();
  }
  return payload;
}

// From a 401/403 probe OR test_dispatch: create the credential and assign it to
// the webhook. `kind` tells the parent which fix event to continue with once
// the credential exists (probe_fix vs dispatch_fix) — the two blocked states
// this control also renders for.
function createAndAssignCred() {
  if (!newCredValid.value) return;
  const kind = props.step.status === 'blocked_dispatch' ? 'dispatch' : 'probe';
  emit('create-credential', { payload: buildNewCredPayload(), kind });
}

// From a blocked_input credential field: create the credential, then continue the
// step with its new id patched into that field.
function createCredForInput() {
  if (!newCredValid.value || !credInputKey.value) return;
  emit('create-credential', { payload: buildNewCredPayload(), inputKey: credInputKey.value });
}
</script>

<template>
  <!-- blocked_input: ask for the missing values, human-labelled -->
  <div v-if="step.status === 'blocked_input'" class="space-y-4">
    <template v-for="f in missingFields(step)" :key="f.key">
      <!-- A credential reference: pick an existing vault credential or create one inline -->
      <div v-if="CRED_KEYS.includes(f.key)" class="space-y-2">
        <label class="block text-sm font-medium text-foreground">{{ fieldMeta(f.key).label }}</label>
        <p class="text-xs text-muted-foreground">{{ __('This step needs a stored auth credential. Pick one from the vault or create a new one — the secret stays in the vault and is injected only at dispatch.') }}</p>

        <!-- One-click: mint a WP Application Password for this site's own REST API -->
        <Button size="sm" variant="secondary" :disabled="busy" @click="emit('provision-app-password', { inputKey: credInputKey })">
          {{ __('Create a WP Application Password for me') }}
        </Button>

        <!-- Pick an existing vault credential -->
        <div v-if="credentials.length && !showCreateCred" class="space-y-2">
          <Input v-if="credentials.length > 8" v-model="credSearch" type="text" :placeholder="__('Search credentials…')" />
          <Select :model-value="String(inputCredDraft)" @update:model-value="(v) => (inputCredDraft = v)">
            <SelectTrigger><SelectValue :placeholder="__('Choose a credential')" /></SelectTrigger>
            <SelectContent>
              <SelectItem v-for="c in filteredCredentials" :key="c.id" :value="String(c.id)">{{ c.name }}</SelectItem>
              <div v-if="!filteredCredentials.length" class="px-2 py-1.5 text-xs text-muted-foreground">{{ __('No matches') }}</div>
            </SelectContent>
          </Select>
          <div class="flex flex-wrap gap-2">
            <Button size="sm" :disabled="busy || !inputCredDraft" @click="continueWithCred">{{ __('Use credential & continue') }}</Button>
            <Button size="sm" variant="outline" :disabled="busy" @click="showCreateCred = true">{{ __('+ New credential') }}</Button>
          </div>
        </div>

        <!-- Create a new vault credential and continue with it -->
        <div v-else class="space-y-2">
          <p v-if="!credentials.length" class="text-xs text-muted-foreground">{{ __('No credentials in the vault yet — create one and it will be used for this step.') }}</p>
          <Input v-model="newCred.name" type="text" :placeholder="__('Credential name (e.g. WP REST admin)')" />
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
            <Input v-model="newCred.password" type="password" :placeholder="__('Password (or Application Password)')" />
          </template>
          <Input v-else v-model="newCred.secret" type="password" :placeholder="newCred.type === 'bearer' ? __('Token') : __('Secret value')" />
          <Input v-if="newCred.type === 'api_key' || newCred.type === 'custom'" v-model="newCred.header_name" type="text" :placeholder="__('Header name (e.g. X-API-Key)')" />
          <div class="flex flex-wrap gap-2">
            <Button size="sm" :disabled="busy || !newCredValid" @click="createCredForInput">{{ __('Create & continue') }}</Button>
            <Button v-if="credentials.length" size="sm" variant="outline" :disabled="busy" @click="showCreateCred = false">{{ __('Use existing') }}</Button>
          </div>
        </div>
      </div>

      <!-- Any other missing field: ask for it directly -->
      <div v-else class="space-y-1.5">
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
    </template>
    <Button v-if="!credInputKey" :disabled="busy" @click="continueInput">
      <Play class="w-4 h-4 mr-1.5" /> {{ __('Continue') }}
    </Button>
  </div>

  <!--
    blocked_prereq, kind: site_defined_paths — the step maps a field that only
    exists inside a container the SITE defines (order meta, ACF, a form's
    fields). The reference payload shows that container because every site has
    one, but its keys are ours, not theirs. Distinct from the capture case
    below: there is nothing to wait for here, so "fire the event and retry" would
    be the wrong instruction.
  -->
  <div v-else-if="step.status === 'blocked_prereq' && step.prereq?.kind === 'site_defined_paths'"
    class="rounded-md border border-amber-400/40 bg-amber-50/40 dark:bg-amber-950/20 p-4 text-sm">
    <p class="text-amber-700 dark:text-amber-300 mb-1">
      {{ __('This step maps a field we cannot see from here. The example payload came from our reference library, and these paths sit inside a container whose contents are specific to your site:') }}
    </p>
    <ul class="mb-3 font-mono text-xs text-amber-700 dark:text-amber-300">
      <li v-for="p in step.prereq?.paths || []" :key="p">{{ p }}</li>
    </ul>
    <p class="text-amber-700 dark:text-amber-300 mb-3">
      {{ __('Fire the event once on this site to capture your real payload, then retry — the mapping will be built from your own fields. Or skip this step and map them by hand.') }}
      <span v-if="step.prereq?.trigger" class="font-mono text-xs">({{ step.prereq.trigger }})</span>
    </p>
    <div class="flex gap-2">
      <Button size="sm" :disabled="busy" @click="emit('retry')">{{ __('I fired it — retry') }}</Button>
      <Button size="sm" variant="outline" :disabled="busy" @click="emit('skip')">{{ __('Skip') }}</Button>
    </div>
  </div>

  <!-- blocked_prereq: need a captured payload -->
  <div v-else-if="step.status === 'blocked_prereq'"
    class="rounded-md border border-amber-400/40 bg-amber-50/40 dark:bg-amber-950/20 p-4 text-sm">
    <p class="text-amber-700 dark:text-amber-300 mb-1">
      {{ __('Waiting for an example payload. Nothing has fired this trigger yet, so there are no real field names to map against.') }}
    </p>
    <p class="text-amber-700 dark:text-amber-300 mb-3">
      {{ __('Go and fire the event once — publish a test post, submit the form, place a test order — then come back and retry.') }}
      <span v-if="step.prereq?.trigger" class="font-mono text-xs">({{ step.prereq.trigger }})</span>
    </p>
    <div class="flex gap-2">
      <Button size="sm" :disabled="busy" @click="emit('retry')">{{ __('I fired it — retry') }}</Button>
      <Button size="sm" variant="outline" :disabled="busy" @click="emit('skip')">{{ __('Skip') }}</Button>
    </div>
  </div>

  <!-- blocked_probe: the probe reached the endpoint but got an actionable status -->
  <div v-else-if="step.status === 'blocked_probe'"
    class="rounded-md border border-amber-400/40 bg-amber-50/40 dark:bg-amber-950/20 p-4 text-sm space-y-3">
    <p class="text-amber-700 dark:text-amber-300">{{ step.probe?.message }}</p>

    <!-- 401/403: attach a vault credential to the webhook, then re-probe -->
    <template v-if="step.probe?.kind === 'auth'">
      <!-- One-click: mint a WP Application Password for this site's own REST API -->
      <Button size="sm" variant="secondary" :disabled="busy" @click="emit('provision-app-password', { kind: 'probe' })">
        {{ __('Create a WP Application Password for me') }}
      </Button>

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
          <Button size="sm" :disabled="busy || !probeCredDraft" @click="fixProbeAuth">{{ __('Add credential & retry') }}</Button>
          <Button size="sm" variant="outline" :disabled="busy" @click="showCreateCred = true">{{ __('+ New credential') }}</Button>
          <Button size="sm" variant="outline" :disabled="busy" @click="emit('skip')">{{ __('Skip') }}</Button>
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
          <Button size="sm" :disabled="busy || !newCredValid" @click="createAndAssignCred">{{ __('Create & assign') }}</Button>
          <Button v-if="credentials.length" size="sm" variant="outline" :disabled="busy" @click="showCreateCred = false">{{ __('Use existing') }}</Button>
          <Button size="sm" variant="outline" :disabled="busy" @click="emit('skip')">{{ __('Skip') }}</Button>
        </div>
      </div>
    </template>

    <!-- Reachable, but an empty-body probe can't prove a create endpoint works -->
    <template v-else-if="step.probe?.kind === 'inconclusive'">
      <div class="flex gap-2">
        <Button size="sm" :disabled="busy" @click="emit('retry')">{{ __('Retry probe') }}</Button>
        <Button size="sm" variant="outline" :disabled="busy" @click="emit('skip')">{{ __('Skip') }}</Button>
      </div>
    </template>

    <!-- 404 / unreachable: provide a different endpoint URL, then re-probe -->
    <template v-else>
      <div class="space-y-2">
        <Input v-model="probeUrlDraft" type="text" :placeholder="__('https://your-endpoint.example/webhook')" />
        <div class="flex gap-2">
          <Button size="sm" :disabled="busy || !probeUrlDraft" @click="fixProbeEndpoint">{{ __('Update URL & retry') }}</Button>
          <Button size="sm" variant="outline" :disabled="busy" @click="emit('retry')">{{ __('Retry') }}</Button>
          <Button size="sm" variant="outline" :disabled="busy" @click="emit('skip')">{{ __('Skip') }}</Button>
        </div>
      </div>
    </template>
  </div>

  <!-- blocked_dispatch: the test delivery reached the endpoint but was refused -->
  <div v-else-if="step.status === 'blocked_dispatch'"
    class="rounded-md border border-amber-400/40 bg-amber-50/40 dark:bg-amber-950/20 p-4 text-sm space-y-3">
    <p class="text-amber-700 dark:text-amber-300">{{ step.dispatch?.message }}</p>
    <pre v-if="step.dispatch?.response"
      class="max-h-32 overflow-auto rounded bg-muted p-2 text-xs font-mono whitespace-pre-wrap break-all">{{ step.dispatch.response }}</pre>

    <!-- 401/403: same credential picker as a probe auth failure — the agent
         cannot see what's actually in the vault, so let it guess-and-retry a
         second stored token is exactly the loop this UI exists to short-circuit. -->
    <template v-if="step.dispatch?.kind === 'auth'">
      <Button size="sm" variant="secondary" :disabled="busy" @click="emit('provision-app-password', { kind: 'dispatch' })">
        {{ __('Create a WP Application Password for me') }}
      </Button>

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
          <Button size="sm" :disabled="busy || !probeCredDraft" @click="fixDispatchAuth">{{ __('Add credential & retry') }}</Button>
          <Button size="sm" variant="outline" :disabled="busy" @click="showCreateCred = true">{{ __('+ New credential') }}</Button>
          <Button size="sm" variant="outline" :disabled="busy" @click="emit('skip')">{{ __('Skip') }}</Button>
        </div>
      </div>

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
          <Button size="sm" :disabled="busy || !newCredValid" @click="createAndAssignCred">{{ __('Create & assign') }}</Button>
          <Button v-if="credentials.length" size="sm" variant="outline" :disabled="busy" @click="showCreateCred = false">{{ __('Use existing') }}</Button>
          <Button size="sm" variant="outline" :disabled="busy" @click="emit('skip')">{{ __('Skip') }}</Button>
        </div>
      </div>
    </template>

    <!-- Anything else (400/422/5xx): a payload-shape problem, not auth — hand it to the agent -->
    <template v-else>
      <p class="text-xs text-muted-foreground">
        {{ __('Ask the agent to fix the payload — it can see this response now — then retry. Skipping leaves the rest of the plan (including going live) to run on a delivery that failed.') }}
      </p>
      <div class="flex gap-2">
        <Button size="sm" :disabled="busy" @click="emit('fix-it')">{{ __('Fix it') }}</Button>
        <Button size="sm" variant="outline" :disabled="busy" @click="emit('retry')">{{ __('Retry') }}</Button>
        <Button size="sm" variant="outline" :disabled="busy" @click="emit('skip')">{{ __('Skip') }}</Button>
      </div>
    </template>
  </div>

  <!-- needs_confirm -->
  <div v-else-if="step.status === 'needs_confirm'"
    class="rounded-md border border-amber-400/40 bg-amber-50/40 dark:bg-amber-950/20 p-4">
    <p class="text-sm text-amber-700 dark:text-amber-300 mb-3">{{ step.confirm_notice || __('This step goes live or changes data. Confirm to run it.') }}</p>
    <div class="flex gap-2">
      <Button size="sm" :disabled="busy" @click="emit('confirm')">{{ __('Confirm & run') }}</Button>
      <Button size="sm" variant="outline" :disabled="busy" @click="emit('skip')">{{ __('Skip') }}</Button>
    </div>
  </div>

  <!-- failed -->
  <div v-else-if="step.status === 'failed'"
    class="rounded-md border border-destructive/40 bg-destructive/10 p-4">
    <p class="text-sm text-destructive mb-3">{{ step.error }}</p>
    <div class="flex gap-2">
      <Button size="sm" :disabled="busy" @click="emit('retry')">{{ __('Retry') }}</Button>
      <Button size="sm" variant="outline" :disabled="busy" @click="emit('skip')">{{ __('Skip') }}</Button>
    </div>
  </div>
</template>
