<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import {
  ChevronDown,
  ChevronRight,
  RefreshCw,
  User,
  Clock,
  Check,
  AlertCircle,
  Braces,
  EqualNot,
  Waypoints,
  Code2,
  Pencil,
  Info,
  Share2,
} from 'lucide-vue-next';
import { Button, Card, Badge, Switch, Label, Alert, Tooltip, UpgradeBadge } from '@/components/ui';
import { formatUtcDate } from '@/lib/dates';
import MappingEditor from '@/components/MappingEditor.vue';
import ConditionsEditor from '@/components/ConditionsEditor.vue';
import PayloadGlueDrawer from '@/components/PayloadGlueDrawer.vue';
import { useSchemas, useUserTriggers } from '@/composables/useSchemas';
import { usePro } from '@/composables/usePro';
import { useTriggerSnippet } from '@/composables/useSnippets';
import { applyMappingTransform } from '@/utils/payloadTransform';
import { __, sprintf } from '@/i18n';

const props = defineProps({
  webhookId: {
    type: [Number, String],
    required: true,
  },
  triggers: {
    type: Array,
    default: () => [],
  },
});

const emit = defineEmits(['glue-preview-change']);

const {
  schemas,
  loading,
  error,
  fetchSchemas,
  updateSchema,
  resetCapture,
  getSchemaForTrigger,
} = useSchemas(props.webhookId);
const { userTriggers, fetchUserTriggers, isUserTrigger } = useUserTriggers();
const { proActive } = usePro();

// Track expanded triggers
const expandedTriggers = ref({});

// Track expanded sections per trigger (mapping / conditions), default open
const sectionsExpanded = ref({});

const isSectionExpanded = (trigger, section) => {
  const key = `${trigger}:${section}`;
  return sectionsExpanded.value[key] !== false; // default true
};

const toggleSection = (trigger, section) => {
  const key = `${trigger}:${section}`;
  sectionsExpanded.value = {
    ...sectionsExpanded.value,
    [key]: !isSectionExpanded(trigger, section),
  };
};

// Track saving state per trigger
const savingState = ref({});

// Track local changes per trigger
const localMappings = ref({});
const localUserData = ref({});
const localConditions = ref({});
const localConditionsEvaluateOn = ref({});

// Code Glue state
const glueDrawer = ref({ open: false, trigger: '', tab: 'pre' });
const gluePreviewPayloads = ref({}); // trigger → preview result (virtual effective payload for mapping/conditions)
watch(gluePreviewPayloads, (val) => emit('glue-preview-change', val), { deep: true });
const gluePreviewSaved = ref({});    // trigger → bool: pre preview was saved (clears warning without clearing payload)
const postGluePreviewPending = ref({}); // trigger → bool: post preview run but not saved
const triggerSnippetAssignments = ref({}); // trigger → { pre_snippet_id, pre_enabled, ... }

const openGlueDrawer = (trigger, tab = 'pre') => {
  glueDrawer.value = { open: true, trigger, tab };
};

// Auto-apply saved pre-dispatch glue against the captured payload silently.
// Marks result as saved so no warning is shown — same as if the user had run + saved manually.
const autoApplyGluePreview = async (trigger, assignment) => {
  if (!assignment?.pre_enabled || !assignment?.pre_snippet?.code) return;
  const payload = getParsedExamplePayload(trigger);
  if (!payload) return;
  try {
    const { api } = await import('@/lib/api');
    const res = await api.snippets.preview({ code: assignment.pre_snippet.code, payload, mode: 'pre' });
    if (res.result && !res.error) {
      gluePreviewPayloads.value = { ...gluePreviewPayloads.value, [trigger]: res.result };
      gluePreviewSaved.value = { ...gluePreviewSaved.value, [trigger]: true };
    }
  } catch { /* ignore */ }
};

const onGluePreview = ({ trigger, result }) => {
  gluePreviewPayloads.value = { ...gluePreviewPayloads.value, [trigger]: result };
  // Manual preview = unsaved (show warning)
  const { [trigger]: _, ...rest } = gluePreviewSaved.value;
  gluePreviewSaved.value = rest;
};

const onGluePostPreview = ({ trigger }) => {
  postGluePreviewPending.value = { ...postGluePreviewPending.value, [trigger]: true };
};

const onGlueSaved = ({ trigger, mode }) => {
  if (mode === 'pre') {
    // Re-apply auto-preview with (potentially updated) saved code
    const a = triggerSnippetAssignments.value[trigger];
    if (a) autoApplyGluePreview(trigger, a);
  } else {
    const { [trigger]: _, ...rest } = postGluePreviewPending.value;
    postGluePreviewPending.value = rest;
  }
};

const triggerHasActiveGlue = (trigger) => {
  const a = triggerSnippetAssignments.value[trigger];
  return a && (a.pre_enabled || a.post_enabled);
};

const onGlueDrawerClose = async () => {
  const trigger = glueDrawer.value.trigger;
  glueDrawer.value = { ...glueDrawer.value, open: false };
  if (trigger && proActive.value) {
    try {
      const { api } = await import('@/lib/api');
      const a = await api.snippets.getTriggerSnippet(props.webhookId, trigger);
      triggerSnippetAssignments.value = { ...triggerSnippetAssignments.value, [trigger]: a };
      autoApplyGluePreview(trigger, a);
    } catch { /* ignore */ }
  }
};

const formatDate = formatUtcDate;

// Toggle trigger expansion
const toggleExpanded = async (trigger) => {
  const wasExpanded = expandedTriggers.value[trigger];
  expandedTriggers.value = {
    ...expandedTriggers.value,
    [trigger]: !wasExpanded,
  };
  // Lazy-load trigger snippet assignment on first expand (pro only)
  if (!wasExpanded && proActive.value && triggerSnippetAssignments.value[trigger] === undefined) {
    try {
      const { api } = await import('@/lib/api');
      const a = await api.snippets.getTriggerSnippet(props.webhookId, trigger);
      triggerSnippetAssignments.value = { ...triggerSnippetAssignments.value, [trigger]: a };
      autoApplyGluePreview(trigger, a);
    } catch {
      triggerSnippetAssignments.value = { ...triggerSnippetAssignments.value, [trigger]: null };
    }
  }
};

// Check if trigger is expanded
const isExpanded = (trigger) => {
  return expandedTriggers.value[trigger] || false;
};

// Get schema for a trigger
const getSchema = (trigger) => {
  return getSchemaForTrigger(trigger);
};

// Get capture status
const getCaptureStatus = (trigger) => {
  const schema = getSchema(trigger);
  if (!schema || !schema.example_payload) {
    return { status: 'waiting', date: null };
  }
  if (schema.example_source === 'shared') {
    return { status: 'shared', date: null, fromWebhookId: schema.example_from_webhook_id };
  }
  return { status: 'captured', date: schema.captured_at };
};

// Whether this webhook may reuse an example captured for the same trigger on
// another webhook (default on when the schema has no stored preference yet).
const isReuseEnabled = (trigger) => {
  const schema = getSchema(trigger);
  if (!schema || schema.use_shared_example === undefined || schema.use_shared_example === null) {
    return true;
  }
  return !!Number(schema.use_shared_example);
};

const handleReuseToggle = async (trigger, value) => {
  savingState.value = { ...savingState.value, [trigger]: true };
  try {
    await updateSchema(trigger, { use_shared_example: value });
    await fetchSchemas(); // refresh so the borrowed example / source updates
  } finally {
    savingState.value = { ...savingState.value, [trigger]: false };
  }
};

// Handle re-capture
const handleReCapture = async (trigger) => {
  savingState.value = { ...savingState.value, [trigger]: true };
  try {
    await resetCapture(trigger);
  } finally {
    savingState.value = { ...savingState.value, [trigger]: false };
  }
};

// Handle user data toggle
const handleUserDataToggle = async (trigger, value) => {
  localUserData.value = { ...localUserData.value, [trigger]: value };
};

// Handle mapping change
const handleMappingChange = (trigger, value) => {
  localMappings.value = { ...localMappings.value, [trigger]: value };
};

// Handle conditions change
const handleConditionsChange = (trigger, value) => {
  localConditions.value = { ...localConditions.value, [trigger]: value };
};

// Save schema for a trigger
const saveSchema = async (trigger) => {
  savingState.value = { ...savingState.value, [trigger]: true };

  try {
    const data = {};

    // Include user data setting if changed
    if (localUserData.value[trigger] !== undefined) {
      data.include_user_data = localUserData.value[trigger];
    } else {
      const schema = getSchema(trigger);
      data.include_user_data = schema?.include_user_data || false;
    }

    // Include field mapping if configured
    if (localMappings.value[trigger]) {
      data.field_mapping = localMappings.value[trigger];
    }

    // Include conditions if changed
    if (localConditions.value[trigger] !== undefined) {
      data.conditions = localConditions.value[trigger];
    }

    // Include conditions_evaluate_on if changed
    if (localConditionsEvaluateOn.value[trigger] !== undefined) {
      data.conditions_evaluate_on = localConditionsEvaluateOn.value[trigger];
    }

    await updateSchema(trigger, data);

    // Clear local state after save
    delete localMappings.value[trigger];
    delete localUserData.value[trigger];
    delete localConditions.value[trigger];
    delete localConditionsEvaluateOn.value[trigger];
  } finally {
    savingState.value = { ...savingState.value, [trigger]: false };
  }
};

// Check if trigger has unsaved changes
const hasChanges = (trigger) => {
  return (
    localMappings.value[trigger] !== undefined ||
    localUserData.value[trigger] !== undefined ||
    localConditions.value[trigger] !== undefined ||
    localConditionsEvaluateOn.value[trigger] !== undefined
  );
};

// Get current user data value (local or from schema)
const getUserDataValue = (trigger) => {
  if (localUserData.value[trigger] !== undefined) {
    return localUserData.value[trigger];
  }
  const schema = getSchema(trigger);
  return schema?.include_user_data || false;
};

// Get current mapping value (local or from schema)
const getMappingValue = (trigger) => {
  if (localMappings.value[trigger]) {
    return localMappings.value[trigger];
  }
  const schema = getSchema(trigger);
  return (
    schema?.field_mapping || {
      mappings: [],
      excluded: [],
      includeUnmapped: true,
    }
  );
};

// Get current conditions value (local or from schema)
const getConditionsValue = (trigger) => {
  if (localConditions.value[trigger] !== undefined) {
    return localConditions.value[trigger];
  }
  const schema = getSchema(trigger);
  return schema?.conditions ?? { enabled: false, type: 'and', rules: [] };
};

// Get conditions_evaluate_on value (local or from schema), default 'original'
const getConditionsEvaluateOn = (trigger) => {
  if (localConditionsEvaluateOn.value[trigger] !== undefined) {
    return localConditionsEvaluateOn.value[trigger];
  }
  const schema = getSchema(trigger);
  return schema?.conditions_evaluate_on ?? 'original';
};

const handleConditionsEvaluateOnChange = (trigger, value) => {
  localConditionsEvaluateOn.value = { ...localConditionsEvaluateOn.value, [trigger]: value };
};

// Compute post-mapping payload for a trigger (for conditions field picker when mode = 'transformed')
const getTransformedPayload = (trigger) => {
  const payload = getExamplePayload(trigger);
  if (!payload) return null;
  const mapping = getMappingValue(trigger);
  return applyMappingTransform(payload, {
    mappings: mapping.mappings || [],
    excluded: mapping.excluded || [],
    includeUnmapped: mapping.includeUnmapped !== false,
  });
};

// Payload passed to ConditionsEditor — depends on evaluate_on setting
const getConditionsPayload = (trigger) => {
  return getConditionsEvaluateOn(trigger) === 'transformed'
    ? getTransformedPayload(trigger)
    : getParsedExamplePayload(trigger);
};

// Get raw captured example payload
const getRawExamplePayload = (trigger) => {
  const schema = getSchema(trigger);
  return schema?.example_payload || null;
};

// Always returns a parsed object (or null) — the API returns example_payload as a JSON string.
const getParsedExamplePayload = (trigger) => {
  const raw = getRawExamplePayload(trigger);
  if (!raw) return null;
  if (typeof raw === 'string') {
    try { return JSON.parse(raw); } catch { return null; }
  }
  return raw;
};

// Get effective example payload: glue preview if available, else raw captured (parsed)
const getExamplePayload = (trigger) => {
  return gluePreviewPayloads.value[trigger] ?? getParsedExamplePayload(trigger);
};

// Check if saving
const isSaving = (trigger) => {
  return savingState.value[trigger] || false;
};

const preloadGlueAssignments = async () => {
  if (!proActive.value || !props.triggers.length) return;
  const { api } = await import('@/lib/api');
  await Promise.all(
    props.triggers.map(async (trigger) => {
      try {
        const a = await api.snippets.getTriggerSnippet(props.webhookId, trigger);
        triggerSnippetAssignments.value = { ...triggerSnippetAssignments.value, [trigger]: a };
        await autoApplyGluePreview(trigger, a);
      } catch { /* ignore */ }
    })
  );
};

// Load schemas on mount
onMounted(async () => {
  await Promise.all([fetchSchemas(), fetchUserTriggers()]);
  preloadGlueAssignments();
});

// Reload when webhookId or triggers change
watch(
  () => props.webhookId,
  () => {
    fetchSchemas();
  },
);

watch(
  () => props.triggers,
  () => {
    fetchSchemas();
  },
);
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <div class="flex items-center gap-2">

        <Waypoints class="h-6 w-6" />
        <h3 class="text-lg font-medium flex items-center gap-2">
          {{ __('Mapping & Conditions') }}
        </h3>
      </div>

      <Button v-if="loading" variant="ghost" size="sm" disabled>
        <RefreshCw class="h-4 w-4 animate-spin" />
      </Button>
    </div>

    <Alert v-if="error" variant="destructive">
      {{ error }}
    </Alert>

    <div
      v-if="triggers.length === 0"
      class="text-center py-6 text-muted-foreground"
    >
      {{ __('No triggers configured for this webhook.') }}
    </div>

    <div v-else class="space-y-2">
      <Card v-for="trigger in triggers" :key="trigger" class="overflow-hidden">
        <!-- Trigger Header -->
        <button
          class="w-full flex flex-wrap items-center justify-between p-4 hover:bg-muted/50 transition-colors text-left gap-3"
          @click="toggleExpanded(trigger)"
        >
          <div class="flex flex-wrap items-center gap-3">
            <component
              :is="isExpanded(trigger) ? ChevronDown : ChevronRight"
              class="h-4 w-4 text-muted-foreground"
            />
            <code class="text-sm font-mono">{{ trigger }}</code>

            <!-- Status badges -->
            <Badge
              v-if="getCaptureStatus(trigger).status === 'captured'"
              variant="success"
              class="text-xs"
            >
              <Check class="h-3 w-3 mr-1" />
              {{ __('Payload Example Captured') }}
            </Badge>
            <Badge
              v-else-if="getCaptureStatus(trigger).status === 'shared'"
              variant="secondary"
              class="text-xs"
            >
              <Share2 class="h-3 w-3 mr-1" />
              {{ __('Shared Example') }}
            </Badge>
            <Badge v-else variant="secondary" class="text-xs">
              <Clock class="h-3 w-3 mr-1" />
              {{ __('No Payload Captured Yet') }}
            </Badge>

            <Badge
              v-if="isUserTrigger(trigger)"
              variant="outline"
              class="text-xs"
            >
              <User class="h-3 w-3 mr-1" />
              {{ __('User trigger') }}
            </Badge>

            <Badge
              v-if="getSchema(trigger)?.field_mapping"
              variant="default"
              class="text-xs"
            >
              <Braces class="h-3 w-3 mr-1" />
              {{ __('Mapped') }}
            </Badge>

            <Badge
              v-if="getSchema(trigger)?.conditions?.enabled && getSchema(trigger)?.conditions?.rules?.length"
              variant="default"
              class="text-xs"
            >
              <EqualNot class="h-3 w-3 mr-1" />
              {{ __('Conditions') }}
            </Badge>

            <Badge
              v-if="proActive && triggerSnippetAssignments[trigger]?.pre_enabled && triggerSnippetAssignments[trigger]?.pre_snippet_id"
              variant="default"
              class="text-xs"
            >
              <Code2 class="h-3 w-3 mr-1" />
              {{ __('Pre Glue') }}
            </Badge>

            <Badge
              v-if="proActive && triggerSnippetAssignments[trigger]?.post_enabled && triggerSnippetAssignments[trigger]?.post_snippet_id"
              variant="default"
              class="text-xs"
            >
              <Code2 class="h-3 w-3 mr-1" />
              {{ __('Post Glue') }}
            </Badge>
          </div>
        </button>

        <!-- Expanded Content -->
        <div
          v-if="isExpanded(trigger)"
          class="border-t p-4 space-y-4 bg-muted/20"
        >
          <!-- Capture Status -->
          <div class="flex items-center justify-between">
            <div class="text-sm">
              <template v-if="getCaptureStatus(trigger).status === 'captured'">
                <span class="text-green-600 dark:text-green-400"
                  >{{ __('Example payload captured') }}</span
                >
                <span class="text-muted-foreground ml-2">{{
                  formatDate(getCaptureStatus(trigger).date)
                }}</span>
              </template>
              <template v-else-if="getCaptureStatus(trigger).status === 'shared'">
                <span class="text-foreground inline-flex items-center gap-1">
                  <Share2 class="h-3.5 w-3.5 text-muted-foreground" />
                  {{ __('Reusing an example captured for this trigger on another webhook') }}
                  <template v-if="getCaptureStatus(trigger).fromWebhookId">
                    (#{{ getCaptureStatus(trigger).fromWebhookId }})</template
                  >
                </span>
              </template>
              <template v-else>
                <span class="text-muted-foreground"
                  >{{ __('Waiting for trigger to capture example payload...') }}</span
                >
              </template>
            </div>
            <Button
              v-if="getCaptureStatus(trigger).status === 'captured'"
              size="sm"
              variant="outline"
              :disabled="isSaving(trigger)"
              @click.stop="handleReCapture(trigger)"
            >
              <RefreshCw
                class="h-4 w-4 mr-1"
                :class="{ 'animate-spin': isSaving(trigger) }"
              />
              {{ __('Re-capture') }}
            </Button>
          </div>

          <!-- Reuse a shared example from another webhook for the same trigger -->
          <div class="flex items-start justify-between gap-3">
            <div class="text-sm">
              <Label class="font-medium">{{ __('Reuse example from other webhooks') }}</Label>
              <p class="text-xs text-muted-foreground mt-0.5 max-w-md">
                {{ __('The payload shape is the same for a trigger everywhere, so a new webhook can borrow an example already captured elsewhere. Turn off to require this webhook to capture its own.') }}
              </p>
            </div>
            <Switch
              :model-value="isReuseEnabled(trigger)"
              :disabled="isSaving(trigger)"
              @update:model-value="(v) => handleReuseToggle(trigger, v)"
            />
          </div>

          <!-- Pre-dispatch Code Glue — inline, right after capture status -->
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-1.5 text-sm">
              <Code2 class="h-4 w-4 text-muted-foreground shrink-0" />
              <span>{{ __('Pre-dispatch Code Glue') }}</span>
              <UpgradeBadge v-if="!proActive" />
              <Badge v-else-if="triggerSnippetAssignments[trigger]?.pre_snippet_id" variant="default" class="text-xs">
                {{ __('Active') }}
              </Badge>
            </div>
            <div class="flex items-center gap-2">
              <div v-if="proActive && gluePreviewPayloads[trigger]" class="flex items-center gap-1 text-xs text-green-600 dark:text-green-400">
                <Check class="h-3 w-3 shrink-0" />
                {{ __('Preview active') }}
              </div>
              <Button size="sm" variant="outline" class="gap-1" :disabled="!proActive" @click.stop="openGlueDrawer(trigger, 'pre')">
                <Pencil class="h-3.5 w-3.5" />
                {{ triggerSnippetAssignments[trigger]?.pre_snippet_id ? __('Edit') : __('Add') }}
              </Button>
            </div>
          </div>

          <!-- User Data Toggle (only for user triggers) -->
          <div
            v-if="isUserTrigger(trigger)"
            class="flex items-center justify-between p-3 border rounded-md bg-background"
          >
            <div>
              <Label class="text-sm font-medium">{{ __('Include User Data') }}</Label>
              <p class="text-xs text-muted-foreground mt-0.5">
                {{ __('Automatically enrich payload with user profile information') }}
              </p>
            </div>
            <Switch
              :modelValue="getUserDataValue(trigger)"
              @update:modelValue="handleUserDataToggle(trigger, $event)"
            />
          </div>

          <!-- Payload Mapping Section -->
          <div class="border-t pt-2">
            <button
              class="w-full flex items-center gap-2 py-2 hover:text-foreground text-left transition-colors"
              @click.stop="toggleSection(trigger, 'mapping')"
            >
              <component :is="isSectionExpanded(trigger, 'mapping') ? ChevronDown : ChevronRight" class="h-4 w-4 text-muted-foreground shrink-0" />
              <Braces class="h-5 w-5 shrink-0" />
              <span class="text-sm font-semibold">{{ __('Payload Mapping') }}</span>
            </button>
            <div v-if="isSectionExpanded(trigger, 'mapping')" class="pt-2">
              <MappingEditor
                :examplePayload="getExamplePayload(trigger)"
                :originalPayload="getParsedExamplePayload(trigger)"
                :modelValue="getMappingValue(trigger)"
                :includeUserData="getUserDataValue(trigger)"
                @update:modelValue="handleMappingChange(trigger, $event)"
              />
            </div>
          </div>

          <!-- Conditions Section -->
          <div class="border-t pt-2">
            <button
              class="w-full flex items-center gap-2 py-2 hover:text-foreground text-left transition-colors"
              @click.stop="toggleSection(trigger, 'conditions')"
            >
              <component :is="isSectionExpanded(trigger, 'conditions') ? ChevronDown : ChevronRight" class="h-4 w-4 text-muted-foreground shrink-0" />
              <EqualNot class="h-5 w-5 shrink-0" />
              <span class="text-sm font-semibold">{{ __('Conditions') }}</span>
            </button>
            <div v-if="isSectionExpanded(trigger, 'conditions')" class="pt-2 space-y-2">
              <div class="flex items-center justify-between px-1">
                <p class="text-xs text-muted-foreground flex items-center gap-1.5">
                  {{ __('Evaluate conditions against') }}
                  <Tooltip :content="__('Choose which payload the conditions read from: Original is the raw payload before field mapping; Transformed is the payload after mapping is applied.')" side="right">
                    <Info class="h-3.5 w-3.5 text-muted-foreground cursor-help shrink-0" />
                  </Tooltip>
                </p>
                <div class="flex items-center gap-1 rounded-md border p-0.5 text-xs">
                  <button
                    class="px-2 py-0.5 rounded transition-colors"
                    :class="getConditionsEvaluateOn(trigger) === 'original' ? 'bg-background shadow text-foreground font-medium' : 'text-muted-foreground hover:text-foreground'"
                    @click="handleConditionsEvaluateOnChange(trigger, 'original')"
                  >{{ __('Original') }}</button>
                  <button
                    class="px-2 py-0.5 rounded transition-colors"
                    :class="getConditionsEvaluateOn(trigger) === 'transformed' ? 'bg-background shadow text-foreground font-medium' : 'text-muted-foreground hover:text-foreground'"
                    @click="handleConditionsEvaluateOnChange(trigger, 'transformed')"
                  >{{ __('Transformed') }}</button>
                </div>
              </div>
              <ConditionsEditor
                :modelValue="getConditionsValue(trigger)"
                :examplePayload="getConditionsPayload(trigger)"
                :is-pro="proActive"
                @update:modelValue="handleConditionsChange(trigger, $event)"
              />
            </div>
          </div>

          <!-- Post-dispatch Code Glue Section -->
          <div class="border-t pt-2">
            <button
              class="w-full flex items-center gap-2 py-2 hover:text-foreground text-left transition-colors"
              :disabled="!proActive"
              @click.stop="proActive && toggleSection(trigger, 'post-glue')"
            >
              <component :is="isSectionExpanded(trigger, 'post-glue') ? ChevronDown : ChevronRight" class="h-4 w-4 text-muted-foreground shrink-0" />
              <Code2 class="h-5 w-5 shrink-0" />
              <span class="text-sm font-semibold">{{ __('Post-dispatch Code Glue') }}</span>
              <UpgradeBadge v-if="!proActive" class="ml-1" />
              <Badge v-else-if="triggerSnippetAssignments[trigger]?.post_snippet_id" variant="default" class="text-xs ml-1">
                {{ __('Active') }}
              </Badge>
            </button>
            <div v-if="proActive && isSectionExpanded(trigger, 'post-glue')" class="pt-2">
              <div class="flex items-center justify-between p-3 border rounded-md bg-background">
                <div>
                  <p class="text-xs text-muted-foreground" v-html="sprintf(__('PHP runs after successful dispatch; %1$s$responseBody%2$s and %1$s$originalPayload%2$s available'), '<code class=&quot;font-mono&quot;>', '</code>')"></p>
                </div>
                <div class="flex items-center gap-2 ml-4">
                  <Button size="sm" variant="outline" class="gap-1" @click.stop="openGlueDrawer(trigger, 'post')">
                    <Pencil class="h-3.5 w-3.5" />
                    {{ triggerSnippetAssignments[trigger]?.post_snippet_id ? __('Edit') : __('Add') }}
                  </Button>
                </div>
              </div>
            </div>
          </div>

          <!-- Save Button -->
          <div class="flex flex-col gap-2 pt-2 border-t">
            <!-- Pre-dispatch glue preview blocking warning -->
            <div
              v-if="gluePreviewPayloads[trigger] && !gluePreviewSaved[trigger]"
              class="flex flex-col sm:flex-row sm:items-center gap-2 rounded-md border border-yellow-300 bg-yellow-50 dark:border-yellow-800 dark:bg-yellow-950 px-3 py-2.5 text-sm text-yellow-800 dark:text-yellow-300"
            >
              <AlertCircle class="h-4 w-4 shrink-0" />
              <span class="flex-1">{{ __('Pre-dispatch Code Glue preview is active. Save or discard it before saving Mapping & Conditions.') }}</span>
              <div class="flex gap-2 shrink-0">
                <Button
                  size="sm"
                  variant="outline"
                  class="border-yellow-400 dark:border-yellow-700 text-yellow-800 dark:text-yellow-300 hover:bg-yellow-100 dark:hover:bg-yellow-900"
                  @click.stop="openGlueDrawer(trigger, 'pre')"
                >
                  <Code2 class="h-3.5 w-3.5 mr-1" />
                  {{ __('Save Glue') }}
                </Button>
                <Button
                  size="sm"
                  variant="ghost"
                  class="text-yellow-800 dark:text-yellow-300"
                  @click.stop="() => { gluePreviewPayloads = { ...gluePreviewPayloads, [trigger]: undefined }; gluePreviewSaved = { ...gluePreviewSaved, [trigger]: undefined }; }"
                >
                  {{ __('Discard') }}
                </Button>
              </div>
            </div>

            <!-- Post-dispatch glue preview pending warning -->
            <div
              v-if="postGluePreviewPending[trigger]"
              class="flex flex-col sm:flex-row sm:items-center gap-2 rounded-md border border-yellow-300 bg-yellow-50 dark:border-yellow-800 dark:bg-yellow-950 px-3 py-2.5 text-sm text-yellow-800 dark:text-yellow-300"
            >
              <AlertCircle class="h-4 w-4 shrink-0" />
              <span class="flex-1">{{ __('Post-dispatch Code Glue preview ran but is not saved. Save or discard before continuing.') }}</span>
              <div class="flex gap-2 shrink-0">
                <Button
                  size="sm"
                  variant="outline"
                  class="border-yellow-400 dark:border-yellow-700 text-yellow-800 dark:text-yellow-300 hover:bg-yellow-100 dark:hover:bg-yellow-900"
                  @click.stop="openGlueDrawer(trigger, 'post')"
                >
                  <Code2 class="h-3.5 w-3.5 mr-1" />
                  {{ __('Save Glue') }}
                </Button>
                <Button
                  size="sm"
                  variant="ghost"
                  class="text-yellow-800 dark:text-yellow-300"
                  @click.stop="postGluePreviewPending = { ...postGluePreviewPending, [trigger]: undefined }"
                >
                  {{ __('Discard') }}
                </Button>
              </div>
            </div>

            <div class="flex flex-col sm:flex-row justify-end gap-2">
              <Badge
                v-if="hasChanges(trigger)"
                variant="warning"
                class="mr-auto w-full sm:w-auto justify-center"
              >
                <AlertCircle class="h-3 w-3 mr-1" />
                {{ __('Unsaved changes') }}
              </Badge>
              <Button
                :disabled="!hasChanges(trigger) || isSaving(trigger) || (!!gluePreviewPayloads[trigger] && !gluePreviewSaved[trigger]) || !!postGluePreviewPending[trigger]"
                @click.stop="saveSchema(trigger)"
              >
                <RefreshCw
                  v-if="isSaving(trigger)"
                  class="h-4 w-4 mr-1 animate-spin"
                />
                {{ __('Save') }}
              </Button>
            </div>
          </div>
        </div>
      </Card>
    </div>
  </div>

  <!-- Code Glue Drawer -->
  <PayloadGlueDrawer
    :open="glueDrawer.open"
    :webhookId="webhookId"
    :trigger="glueDrawer.trigger"
    :examplePayload="getParsedExamplePayload(glueDrawer.trigger)"
    :initialTab="glueDrawer.tab"
    @close="onGlueDrawerClose"
    @glue-preview="onGluePreview"
    @glue-post-preview="onGluePostPreview"
    @glue-saved="onGlueSaved"
  />
</template>
