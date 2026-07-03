<script setup>
import { ref, reactive, computed } from 'vue';
import { Loader2, Check, Trash2, KeyRound, Plus, AlertTriangle } from 'lucide-vue-next';
import { Input, Label, Button, Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/components/ui';
import ProviderLogo from './ProviderLogo.vue';
import { api } from '@/lib/api';
import { __ } from '@/i18n';

const props = defineProps({ status: { type: Object, required: true } });
const emit = defineEmits(['update']);

const busy = ref('');
const error = ref('');

// ---- Source ---------------------------------------------------------------
const aiAvailable = computed(() => props.status?.wp_ai_client?.available === true);
const source = computed(() => props.status?.source || 'auto');

// Is the WordPress 7.0 AI Client code even present? (function_exists on the server)
const wpClientPresent = computed(() => props.status?.wp_ai_client?.present === true);

// True when the site was set up to use the WordPress AI Client but it is no
// longer usable — e.g. WordPress was downgraded below 7.0, or every connector
// was removed. We detect this from a lingering preference or a pinned source
// while the client reports unavailable, so a fresh pre-7.0 site (which never
// used it) does not see the notice.
const wpClientLost = computed(() => {
  if (aiAvailable.value) return false;
  const pref = props.status?.wp_ai_client?.preference || {};
  return source.value === 'wp_ai_client' || !!(pref.provider || pref.model);
});

// Which configuration UI to show given the toggle + what's actually available.
const displaySource = computed(() => {
  if (source.value === 'byok') return 'byok';
  if (source.value === 'wp_ai_client') return aiAvailable.value ? 'wp_ai_client' : 'byok';
  return aiAvailable.value ? 'wp_ai_client' : 'byok'; // auto
});

const sourceOptions = [
  { value: 'auto', label: __('Auto') },
  { value: 'wp_ai_client', label: __('WordPress connectors') },
  { value: 'byok', label: __('My own keys') },
];

async function run(marker, fn) {
  busy.value = marker;
  error.value = '';
  try {
    // Pass the action marker so the parent can react (e.g. close the "Change
    // model" panel once a model has actually been picked/activated).
    emit('update', await fn(), marker);
  } catch (e) {
    error.value = e.message;
  } finally {
    busy.value = '';
  }
}

const setSource = (s) => run('source:' + s, () => api.agent.saveSource({ source: s }));

// ---- WP AI Client picker --------------------------------------------------
const wpProviders = computed(() => props.status?.wp_ai_client?.providers || []);
const wpPref = computed(() => props.status?.wp_ai_client?.preference || {});

const wpProvider = ref('');
const wpModel = ref('');
const wpShowAll = ref(false);

const wpProviderModels = computed(() => wpProviders.value.find((p) => p.id === wpProvider.value)?.models || []);
const wpRecommended = computed(() => wpProviderModels.value.filter((m) => m.recommended));
const wpDisplayed = computed(() => (wpShowAll.value ? wpProviderModels.value : (wpRecommended.value.length ? wpRecommended.value : wpProviderModels.value)));
const wpHidden = computed(() => wpProviderModels.value.length - wpRecommended.value.length);

function initWp() {
  wpProvider.value = wpPref.value.provider || props.status?.active_provider || wpProviders.value[0]?.id || '';
  wpModel.value = wpPref.value.model || wpRecommended.value[0]?.id || wpProviderModels.value[0]?.id || '';
  wpShowAll.value = !!wpModel.value && !wpRecommended.value.some((m) => m.id === wpModel.value);
}

function onWpProvider(id) {
  wpProvider.value = id;
  wpShowAll.value = false;
  const models = wpProviders.value.find((p) => p.id === id)?.models || [];
  if (!models.some((m) => m.id === wpModel.value)) {
    const rec = models.filter((m) => m.recommended);
    wpModel.value = (rec[0] || models[0])?.id || '';
  }
}

const saveWp = () => run('wp', () => api.agent.savePreference({ provider: wpProvider.value, model: wpModel.value }));

// ---- BYO connectors -------------------------------------------------------
const byokProviders = computed(() => props.status?.byok?.providers || []);
const byokActive = computed(() => props.status?.byok?.active || null);

// Per-provider UI state: connect form open, key/model drafts, show-all toggle.
const ui = reactive({});
function uiFor(id) {
  if (!ui[id]) ui[id] = { open: false, key: '', model: '', showAll: false };
  return ui[id];
}

function recModels(p) { return (p.models || []).filter((m) => m.recommended); }
function displayedModels(p) {
  const u = uiFor(p.id);
  if (u.showAll) return p.models || [];
  const rec = recModels(p);
  return rec.length ? rec : (p.models || []);
}
function hiddenCount(p) { return (p.models || []).length - recModels(p).length; }

function openConnect(p) {
  const u = uiFor(p.id);
  u.open = true;
  u.key = '';
  u.model = p.model || recModels(p)[0]?.id || (p.models || [])[0]?.id || '';
}

const connect = (p) => run('connect:' + p.id, async () => {
  const u = uiFor(p.id);
  const res = await api.agent.saveByok({ provider: p.id, api_key: u.key.trim(), model: u.model });
  u.open = false; u.key = '';
  return res;
});

// Change a connected provider's model (also makes it the active provider).
const selectModel = (p, model) => run('model:' + p.id, () => api.agent.saveByok({ provider: p.id, model }));

// Make a connected provider active without changing its model.
const useProvider = (p) => run('use:' + p.id, () => api.agent.saveByok({ provider: p.id, model: p.model }));

const removeProvider = (p) => {
  if (!confirm(__('Remove this provider and delete its stored key?'))) return;
  run('remove:' + p.id, () => api.agent.deleteByok(p.id));
};

// Initialise the WP picker fields when this panel mounts with AI Client active.
initWp();
</script>

<template>
  <div class="space-y-4">
    <!-- Source toggle (only when WordPress connectors are available) -->
    <div v-if="aiAvailable" class="space-y-1.5">
      <Label>{{ __('Credentials source') }}</Label>
      <div class="inline-flex rounded-md border border-border bg-background p-0.5">
        <button v-for="opt in sourceOptions" :key="opt.value" type="button"
          @click="setSource(opt.value)"
          :class="['px-3 py-1.5 text-xs font-medium rounded transition-colors',
            source === opt.value ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground']">
          <Loader2 v-if="busy === 'source:' + opt.value" class="w-3 h-3 animate-spin inline mr-1" />
          {{ opt.label }}
        </button>
      </div>
      <p class="text-xs text-muted-foreground">
        {{ __('Auto prefers your WordPress connectors when available, otherwise your own keys.') }}
      </p>
    </div>

    <!-- WP AI Client provider/model picker -->
    <div v-if="displaySource === 'wp_ai_client'" class="space-y-3">
      <div class="flex flex-col sm:flex-row gap-3">
        <div class="flex-1 space-y-1.5">
          <div class="flex items-center h-5"><Label>{{ __('Provider') }}</Label></div>
          <Select :model-value="wpProvider" @update:model-value="onWpProvider">
            <SelectTrigger><SelectValue :placeholder="__('Select a provider')" /></SelectTrigger>
            <SelectContent>
              <SelectItem v-for="p in wpProviders" :key="p.id" :value="p.id">
                <span class="flex items-center gap-2"><ProviderLogo :provider="p.id" :size="16" /> {{ p.label }}</span>
              </SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div class="flex-1 space-y-1.5">
          <div class="flex items-center justify-between h-5">
            <Label>{{ __('Model') }}</Label>
            <button v-if="wpHidden > 0" type="button" @click="wpShowAll = !wpShowAll" class="text-xs text-primary hover:underline leading-none">
              {{ wpShowAll ? __('Show recommended') : __('Show all compatible (%d)').replace('%d', wpHidden) }}
            </button>
          </div>
          <Select v-model="wpModel">
            <SelectTrigger><SelectValue :placeholder="__('Select a model')" /></SelectTrigger>
            <SelectContent class="max-h-72">
              <SelectItem v-for="m in wpDisplayed" :key="m.id" :value="m.id">{{ m.name }}</SelectItem>
            </SelectContent>
          </Select>
        </div>
      </div>
      <Button size="sm" @click="saveWp" :disabled="busy === 'wp' || !wpProvider || !wpModel">
        <Loader2 v-if="busy === 'wp'" class="w-4 h-4 animate-spin mr-1.5" /><Check v-else class="w-4 h-4 mr-1.5" />
        {{ __('Save') }}
      </Button>
    </div>

    <!-- BYO connectors manager -->
    <div v-else class="space-y-2">
      <!-- WP AI Client was in use but is gone (e.g. WordPress downgraded below 7.0) -->
      <div v-if="wpClientLost" class="rounded-md border border-amber-400/50 bg-amber-50/60 dark:bg-amber-950/20 px-3 py-2.5 flex gap-2.5">
        <AlertTriangle class="w-4 h-4 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
        <div class="text-xs space-y-0.5">
          <p class="font-medium text-amber-800 dark:text-amber-200">
            {{ __('Your WordPress AI connectors are no longer available.') }}
          </p>
          <p class="text-amber-700/90 dark:text-amber-300/80">
            <template v-if="!wpClientPresent">
              {{ __('This site no longer includes the WordPress 7.0 AI Client. Connect a provider key below, or restore WordPress 7.0 or later to use your connectors again.') }}
            </template>
            <template v-else>
              {{ __('No WordPress AI connector is currently configured. Add one under Settings → Connectors, or connect a provider key below.') }}
            </template>
          </p>
        </div>
      </div>

      <p class="text-xs text-muted-foreground">
        {{ __('Add an API key for one or more providers. The active provider is used first; if it is rate-limited or fails, the agent automatically falls back to another connected provider.') }}
      </p>

      <div v-for="p in byokProviders" :key="p.id" class="rounded-md border border-border bg-background">
        <div class="flex items-center justify-between gap-3 px-3 py-2.5">
          <div class="flex items-center gap-2 min-w-0">
            <ProviderLogo :provider="p.id" :size="20" />
            <span class="text-sm font-medium text-foreground">{{ p.label }}</span>
            <span v-if="p.connected && byokActive === p.id"
              class="text-[10px] uppercase tracking-wide px-1.5 py-0.5 rounded bg-primary/15 text-primary border border-primary/30">{{ __('Active') }}</span>
            <span v-else-if="p.connected" class="text-xs text-muted-foreground font-mono">{{ p.hint }}</span>
          </div>

          <div class="flex items-center gap-2 shrink-0">
            <Button v-if="!p.connected && !uiFor(p.id).open" size="sm" variant="outline" @click="openConnect(p)">
              <Plus class="w-4 h-4 mr-1" /> {{ __('Connect') }}
            </Button>
            <Button v-if="p.connected && byokActive !== p.id" size="sm" variant="outline"
              @click="useProvider(p)" :disabled="busy === 'use:' + p.id">
              <Loader2 v-if="busy === 'use:' + p.id" class="w-4 h-4 animate-spin" />
              <span v-else>{{ __('Use') }}</span>
            </Button>
            <button v-if="p.connected" type="button" @click="removeProvider(p)"
              class="text-muted-foreground hover:text-destructive p-1" :title="__('Remove')">
              <Trash2 class="w-4 h-4" />
            </button>
          </div>
        </div>

        <!-- Connect form -->
        <div v-if="!p.connected && uiFor(p.id).open" class="border-t border-border p-3 space-y-2">
          <div class="space-y-1.5">
            <Label>{{ __('API key') }}</Label>
            <Input v-model="uiFor(p.id).key" type="password" placeholder="sk-…" class="font-mono" />
          </div>
          <div class="flex items-center gap-2">
            <Button size="sm" @click="connect(p)" :disabled="busy === 'connect:' + p.id || !uiFor(p.id).key.trim()">
              <Loader2 v-if="busy === 'connect:' + p.id" class="w-4 h-4 animate-spin mr-1.5" />
              <KeyRound v-else class="w-4 h-4 mr-1.5" /> {{ __('Connect') }}
            </Button>
            <Button size="sm" variant="ghost" @click="uiFor(p.id).open = false">{{ __('Cancel') }}</Button>
          </div>
        </div>

        <!-- Connected: model selection -->
        <div v-else-if="p.connected" class="border-t border-border p-3 space-y-1.5">
          <div class="flex items-center justify-between h-5">
            <Label>{{ __('Model') }}</Label>
            <button v-if="hiddenCount(p) > 0" type="button" @click="uiFor(p.id).showAll = !uiFor(p.id).showAll"
              class="text-xs text-primary hover:underline leading-none">
              {{ uiFor(p.id).showAll ? __('Show recommended') : __('Show all compatible (%d)').replace('%d', hiddenCount(p)) }}
            </button>
          </div>
          <div class="flex items-center gap-2">
            <Select :model-value="p.model" @update:model-value="(m) => selectModel(p, m)" class="flex-1">
              <SelectTrigger><SelectValue :placeholder="__('Select a model')" /></SelectTrigger>
              <SelectContent class="max-h-72">
                <SelectItem v-for="m in displayedModels(p)" :key="m.id" :value="m.id">{{ m.name }}</SelectItem>
              </SelectContent>
            </Select>
            <Loader2 v-if="busy === 'model:' + p.id" class="w-4 h-4 animate-spin text-muted-foreground" />
          </div>
          <p v-if="!p.models.length" class="text-xs text-amber-600 dark:text-amber-400">
            {{ __('Could not load models for this key — check that the key is valid.') }}
          </p>
        </div>
      </div>
    </div>

    <div v-if="error" class="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
      {{ error }}
    </div>
  </div>
</template>
