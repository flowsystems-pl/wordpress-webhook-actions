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
} from 'lucide-vue-next';
import { Button, Card, Badge, Switch, Label, Alert } from '@/components/ui';
import MappingEditor from '@/components/MappingEditor.vue';
import { useSchemas, useUserTriggers } from '@/composables/useSchemas';
import { Braces } from 'lucide-vue-next';

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

// Track expanded triggers
const expandedTriggers = ref({});

// Track saving state per trigger
const savingState = ref({});

// Track local changes per trigger
const localMappings = ref({});
const localUserData = ref({});

// Format date for display
const formatDate = (date) => {
  if (!date) return null;
  return new Date(date).toLocaleString();
};

// Toggle trigger expansion
const toggleExpanded = (trigger) => {
  expandedTriggers.value = {
    ...expandedTriggers.value,
    [trigger]: !expandedTriggers.value[trigger],
  };
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
  return { status: 'captured', date: schema.captured_at };
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

    await updateSchema(trigger, data);

    // Clear local state after save
    delete localMappings.value[trigger];
    delete localUserData.value[trigger];
  } finally {
    savingState.value = { ...savingState.value, [trigger]: false };
  }
};

// Check if trigger has unsaved changes
const hasChanges = (trigger) => {
  return (
    localMappings.value[trigger] !== undefined ||
    localUserData.value[trigger] !== undefined
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

// Get example payload for a trigger
const getExamplePayload = (trigger) => {
  const schema = getSchema(trigger);
  return schema?.example_payload || null;
};

// Check if saving
const isSaving = (trigger) => {
  return savingState.value[trigger] || false;
};

// Load schemas on mount
onMounted(async () => {
  await Promise.all([fetchSchemas(), fetchUserTriggers()]);
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
        <Braces class="h-6 w-6" />
        <h3 class="text-lg font-medium">Payload Mapping</h3>
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
      No triggers configured for this webhook.
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
              Payload Example Captured
            </Badge>
            <Badge v-else variant="secondary" class="text-xs">
              <Clock class="h-3 w-3 mr-1" />
              No Payload Captured Yet
            </Badge>

            <Badge
              v-if="isUserTrigger(trigger)"
              variant="outline"
              class="text-xs"
            >
              <User class="h-3 w-3 mr-1" />
              User trigger
            </Badge>

            <Badge
              v-if="getSchema(trigger)?.field_mapping"
              variant="default"
              class="text-xs"
            >
              <Braces class="h-3 w-3 mr-1" />
              Mapped
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
                  >Example payload captured</span
                >
                <span class="text-muted-foreground ml-2">{{
                  formatDate(getCaptureStatus(trigger).date)
                }}</span>
              </template>
              <template v-else>
                <span class="text-muted-foreground"
                  >Waiting for trigger to capture example payload...</span
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
              Re-capture
            </Button>
          </div>

          <!-- User Data Toggle (only for user triggers) -->
          <div
            v-if="isUserTrigger(trigger)"
            class="flex items-center justify-between p-3 border rounded-md bg-background"
          >
            <div>
              <Label class="text-sm font-medium">Include User Data</Label>
              <p class="text-xs text-muted-foreground mt-0.5">
                Automatically enrich payload with user profile information
              </p>
            </div>
            <Switch
              :modelValue="getUserDataValue(trigger)"
              @update:modelValue="handleUserDataToggle(trigger, $event)"
            />
          </div>

          <!-- Mapping Editor -->
          <MappingEditor
            :examplePayload="getExamplePayload(trigger)"
            :modelValue="getMappingValue(trigger)"
            :includeUserData="getUserDataValue(trigger)"
            @update:modelValue="handleMappingChange(trigger, $event)"
          />

          <!-- Save Button -->
          <div
            class="flex flex-col sm:flex-row justify-end gap-2 pt-2 border-t"
          >
            <Badge
              v-if="hasChanges(trigger)"
              variant="warning"
              class="mr-auto w-full sm:w-auto justify-center"
            >
              <AlertCircle class="h-3 w-3 mr-1" />
              Unsaved changes
            </Badge>
            <Button
              :disabled="!hasChanges(trigger) || isSaving(trigger)"
              @click.stop="saveSchema(trigger)"
            >
              <RefreshCw
                v-if="isSaving(trigger)"
                class="h-4 w-4 mr-1 animate-spin"
              />
              Save Mapping
            </Button>
          </div>
        </div>
      </Card>
    </div>
  </div>
</template>
