<script setup>
import { ref, computed, watch } from 'vue';
import { escapeKey, unescapeKey, splitPathRaw, splitPath, joinPath, flattenObject, applyCast, getValueByPath, setValueByPath, flattenForTransform, isPathExcluded, applyMappingTransform } from '@/utils/payloadTransform';
import {
  Plus,
  Trash2,
  GripVertical,
  ArrowRight,
  ChevronDown,
  ChevronRight,
  Lock,
  AlertTriangle,
} from 'lucide-vue-next';
import { Button, Input, Switch, Label, Badge, Select, SelectTrigger, SelectContent, SelectItem } from '@/components/ui';

const props = defineProps({
  examplePayload: {
    type: Object,
    default: null,
  },
  modelValue: {
    type: Object,
    default: () => ({
      mappings: [],
      excluded: [],
      includeUnmapped: true,
    }),
  },
  includeUserData: {
    type: Boolean,
    default: false,
  },
  originalPayload: {
    type: Object,
    default: null,
  },
});

// Example user data structure (matches PayloadTransformer::getUserData)
const exampleUserData = {
  id: 1,
  login: 'johndoe',
  email: 'john@example.com',
  display_name: 'John Doe',
  first_name: 'John',
  last_name: 'Doe',
  roles: ['subscriber'],
  registered: '2024-01-15 10:30:00',
  meta: {
    nickname: 'Johnny',
    description: 'A sample user',
    locale: 'en_US',
  },
};

const emit = defineEmits(['update:modelValue']);

// Local state
const localMappings = ref([]);
const localExcluded = ref([]);
const localIncludeUnmapped = ref(true);
const previewExpanded = ref(false);
const originalExpanded = ref(false);
const expandedPaths = ref({});

// Drag and drop state
const draggedIndex = ref(null);
const dragOverIndex = ref(null);
const canDrag = ref(false);



// Merged payload including user data when enabled
const effectivePayload = computed(() => {
  if (!props.examplePayload) return null;
  if (!props.includeUserData) return props.examplePayload;
  return {
    ...props.examplePayload,
    user: exampleUserData,
  };
});

const availableFields = computed(() => {
  if (!effectivePayload.value) return [];
  return flattenObject(effectivePayload.value);
});

// Filter fields based on expansion state
const visibleFields = computed(() => {
  const fields = availableFields.value;
  const visible = [];

  for (const field of fields) {
    // Check if any parent is collapsed
    const pathParts = splitPathRaw(field.path);
    let isHidden = false;

    for (let i = 1; i < pathParts.length; i++) {
      const parentPath = pathParts.slice(0, i).join('.');
      if (expandedPaths.value[parentPath] === false) {
        isHidden = true;
        break;
      }
    }

    if (!isHidden) {
      visible.push(field);
    }
  }

  return visible;
});

// Initialize from modelValue
watch(
  () => props.modelValue,
  (val) => {
    if (val) {
      localMappings.value = (val.mappings || []).map((m) => ({
        ...m,
        locked: m.locked !== false,
        cast: m.cast || null,
      }));
      localExcluded.value = [...(val.excluded || [])];
      localIncludeUnmapped.value = val.includeUnmapped !== false;
    }
  },
  { immediate: true, deep: true },
);

// Auto-expand top-level paths on mount or when user data is toggled
watch(
  [() => props.examplePayload, () => props.includeUserData],
  () => {
    if (effectivePayload.value) {
      // Auto-expand first two levels
      const topLevel = Object.keys(effectivePayload.value);
      topLevel.forEach((key) => {
        const escapedKey = escapeKey(key);
        expandedPaths.value[escapedKey] = true;
        const value = effectivePayload.value[key];
        if (value && typeof value === 'object') {
          const subKeys = Array.isArray(value)
            ? value.map((_, i) => String(i))
            : Object.keys(value);
          subKeys.forEach((subKey) => {
            expandedPaths.value[joinPath(escapedKey, subKey)] = true;
          });
        }
      });
    }
  },
  { immediate: true },
);

const CAST_OPTIONS = [
  { value: 'auto',    label: 'auto' },
  { value: 'number',  label: 'number' },
  { value: 'string',  label: 'string' },
  { value: 'boolean', label: 'bool' },
]

const castToSelect = (cast) => cast || 'auto'
const castFromSelect = (val) => (val === 'auto' ? null : val)

// Emit changes
const emitUpdate = () => {
  emit('update:modelValue', {
    mappings: localMappings.value
      .filter((m) => m.source && m.target)
      .map((m) => {
        const row = { source: m.source, target: m.target }
        if (m.cast) row.cast = m.cast
        return row
      }),
    excluded: localExcluded.value,
    includeUnmapped: localIncludeUnmapped.value,
  });
};

// Add a new mapping from field list (source is locked/read-only)
const addMappingFromField = (sourcePath) => {
  localMappings.value = [
    ...localMappings.value,
    {
      source: sourcePath,
      target: sourcePath,
      locked: true,
    },
  ];
  emitUpdate();
};

// Add a new empty mapping (both fields editable)
const addEmptyMapping = () => {
  localMappings.value = [
    ...localMappings.value,
    {
      source: '',
      target: '',
      locked: false,
    },
  ];
  // Don't emit yet - wait for user to fill in values
};

// Remove a mapping
const removeMapping = (index) => {
  localMappings.value = localMappings.value.filter((_, i) => i !== index);
  emitUpdate();
};

// Update mapping source (only for unlocked mappings)
const updateMappingSource = (index, value) => {
  const updated = [...localMappings.value];
  updated[index] = { ...updated[index], source: value };
  localMappings.value = updated;

  if (updated[index].source && updated[index].target) {
    emitUpdate();
  }
};

// Update mapping cast
const updateMappingCast = (index, value) => {
  const updated = [...localMappings.value]
  updated[index] = { ...updated[index], cast: value || null }
  localMappings.value = updated
  if (updated[index].source && updated[index].target) {
    emitUpdate()
  }
}

// Update mapping target
const updateMappingTarget = (index, value) => {
  const updated = [...localMappings.value];
  updated[index] = { ...updated[index], target: value };
  localMappings.value = updated;

  if (updated[index].source && updated[index].target) {
    emitUpdate();
  }
};

// Drag and drop handlers
const handleDragStart = (e, index) => {
  if (!canDrag.value) {
    e.preventDefault();
    return;
  }
  draggedIndex.value = index;
};

const handleDragOver = (e, index) => {
  e.preventDefault();
  dragOverIndex.value = index;
};

const handleDragEnd = () => {
  if (
    draggedIndex.value !== null &&
    dragOverIndex.value !== null &&
    draggedIndex.value !== dragOverIndex.value
  ) {
    const updated = [...localMappings.value];
    const [removed] = updated.splice(draggedIndex.value, 1);
    updated.splice(dragOverIndex.value, 0, removed);
    localMappings.value = updated;
    emitUpdate();
  }
  draggedIndex.value = null;
  dragOverIndex.value = null;
  canDrag.value = false;
};

const enableDrag = () => {
  canDrag.value = true;
};

const disableDrag = () => {
  canDrag.value = false;
};

// Toggle field exclusion
const toggleExcluded = (path) => {
  const index = localExcluded.value.indexOf(path);
  if (index >= 0) {
    localExcluded.value = localExcluded.value.filter((_, i) => i !== index);
  } else {
    localExcluded.value = [...localExcluded.value, path];
  }
  emitUpdate();
};

// Check if field is excluded (also checks if parent path is excluded)
const isExcluded = (path) => {
  // Direct match
  if (localExcluded.value.includes(path)) {
    return true;
  }
  // Check if any parent path is excluded
  for (const excludedPath of localExcluded.value) {
    if (path.startsWith(excludedPath + '.')) {
      return true;
    }
  }
  return false;
};

// Check if field is directly excluded (not via parent)
const isDirectlyExcluded = (path) => {
  return localExcluded.value.includes(path);
};

// Check if field is mapped
const isMapped = (path) => {
  return localMappings.value.some((m) => m.source === path);
};

// Toggle include unmapped
const toggleIncludeUnmapped = (value) => {
  localIncludeUnmapped.value = value;
  emitUpdate();
};

// Toggle path expansion
const toggleExpanded = (path) => {
  expandedPaths.value = {
    ...expandedPaths.value,
    [path]: !isExpanded(path),
  };
};

// Check if path is expanded
const isExpanded = (path) => {
  return expandedPaths.value[path] !== false;
};

// Get preview value display
const getValuePreview = (value) => {
  if (value === null) return 'null';
  if (value === undefined) return 'undefined';
  if (typeof value === 'boolean') return value ? 'true' : 'false';
  if (typeof value === 'number') return String(value);
  if (typeof value === 'string') {
    return value.length > 25 ? `"${value.substring(0, 25)}..."` : `"${value}"`;
  }
  if (Array.isArray(value)) {
    return `Array[${value.length}]`;
  }
  if (typeof value === 'object') {
    return `Object{${Object.keys(value).length}}`;
  }
  return String(value);
};

// Get type badge variant
const getTypeBadgeVariant = (type) => {
  const variants = {
    string: 'secondary',
    number: 'default',
    boolean: 'warning',
    array: 'outline',
    object: 'outline',
    null: 'destructive',
  };
  return variants[type] || 'secondary';
};

// Get indentation style for nested fields
const getIndentStyle = (depth) => {
  return { paddingLeft: `${depth * 16 + 12}px` };
};

// ============ PREVIEW LOGIC ============

// Check if a source path exists in the payload
const isValidSourcePath = (path) => {
  if (!path || !effectivePayload.value) return false;
  return getValueByPath(effectivePayload.value, path) !== undefined;
};

// Compute transformed preview
const transformedPreview = computed(() => {
  return applyMappingTransform(effectivePayload.value, {
    mappings: localMappings.value,
    excluded: localExcluded.value,
    includeUnmapped: localIncludeUnmapped.value,
  });
});

// Format JSON for display with syntax highlighting
const formatJsonWithHighlight = (obj, indent = 0) => {
  if (obj === null) return '<span class="text-orange-500">null</span>';
  if (obj === undefined) return '<span class="text-gray-400">undefined</span>';

  const indentStr = '  '.repeat(indent);
  const nextIndent = '  '.repeat(indent + 1);

  if (typeof obj === 'string') {
    const escaped = obj.replace(/"/g, '\\"').replace(/\n/g, '\\n');

    return `<span class="text-green-600 dark:text-green-400">"${escaped}"</span>`;
  }

  if (typeof obj === 'number') {
    return `<span class="text-blue-600 dark:text-blue-400">${obj}</span>`;
  }

  if (typeof obj === 'boolean') {
    return `<span class="text-purple-600 dark:text-purple-400">${obj}</span>`;
  }

  if (Array.isArray(obj)) {
    if (obj.length === 0) return '[]';
    const items = obj
      .map(
        (item) => `${nextIndent}${formatJsonWithHighlight(item, indent + 1)}`,
      );

    return `[\n${items.join(',\n')}\n${indentStr}]`;
  }

  if (typeof obj === 'object') {
    const keys = Object.keys(obj);
    if (keys.length === 0) return '{}';
    const entries = keys.map((key) => {
      const value = formatJsonWithHighlight(obj[key], indent + 1);
      return `${nextIndent}<span class="text-red-600 dark:text-red-400">"${key}"</span>: ${value}`;
    });

    return `{\n${entries.join(',\n')}\n${indentStr}}`;
  }

  return String(obj);
};

const previewHtml = computed(() => {
  if (!transformedPreview.value) return '';
  return formatJsonWithHighlight(transformedPreview.value);
});

</script>

<template>
  <div class="space-y-6">
    <!-- No example payload state -->
    <div v-if="!examplePayload" class="text-center py-8 text-muted-foreground">
      <p>No example payload captured yet.</p>
      <p class="text-sm mt-1">
        Trigger this event to capture an example payload for mapping.
      </p>
    </div>

    <template v-else>
      <!-- Available Fields -->
      <div>
        <div class="flex flex-wrap items-center justify-between mb-3">
          <Label class="text-sm font-medium">Available Fields</Label>
          <div class="flex items-center gap-2">
            <Label class="text-xs text-muted-foreground"
              >Include unmapped fields</Label
            >
            <Switch
              :modelValue="localIncludeUnmapped"
              @update:modelValue="toggleIncludeUnmapped"
            />
          </div>
        </div>

        <div class="border rounded-md divide-y max-h-72 overflow-y-auto">
          <div
            v-for="field in visibleFields"
            :key="field.path"
            class="flex flex-wrap items-center sm:justify-between py-1.5 pr-3 text-sm hover:bg-muted/50"
            :class="{ 'opacity-50': isExcluded(field.path) }"
            :style="getIndentStyle(field.depth)"
          >
            <div class="flex items-center gap-1.5 min-w-0 shrink-0">
              <!-- Expand/collapse for objects/arrays -->
              <button
                v-if="field.isExpandable"
                class="p-0.5 hover:bg-muted rounded"
                @click="toggleExpanded(field.path)"
              >
                <component
                  :is="isExpanded(field.path) ? ChevronDown : ChevronRight"
                  class="h-3 w-3 text-muted-foreground"
                />
              </button>
              <span v-else class="w-4" />

              <code class="text-xs font-mono truncate">{{
                splitPath(field.path).pop()
              }}</code>
              <Badge
                :variant="getTypeBadgeVariant(field.type)"
                class="text-[10px] px-1 py-0 shrink-0"
              >
                {{ field.type }}
              </Badge>
            </div>
            <div class="flex items-center gap-1.5 ml-2 shrink-0">
              <span class="text-xs text-muted-foreground max-w-28 truncate">
                {{ getValuePreview(field.value) }}
              </span>
              <Button
                v-if="!isMapped(field.path) && (isDirectlyExcluded(field.path) || !isExcluded(field.path))"
                size="icon"
                variant="ghost"
                class="h-5 w-5"
                :title="
                  isDirectlyExcluded(field.path)
                    ? 'Include field'
                    : field.isExpandable
                      ? 'Exclude entire ' + field.type
                      : 'Exclude field'
                "
                @click="toggleExcluded(field.path)"
              >
                <Trash2
                  v-if="!isExcluded(field.path)"
                  class="h-3 w-3 text-muted-foreground"
                />
                <Plus v-else class="h-3 w-3 text-muted-foreground" />
              </Button>
              <span
                v-if="isExcluded(field.path) && !isDirectlyExcluded(field.path)"
                class="text-[10px] text-muted-foreground italic"
                >excluded via parent</span
              >
              <Button
                v-if="
                  !isMapped(field.path) &&
                  !isExcluded(field.path) &&
                  !field.isExpandable
                "
                size="icon"
                variant="ghost"
                class="h-5 w-5"
                title="Add mapping for this field"
                @click="addMappingFromField(field.path)"
              >
                <ArrowRight class="h-3 w-3" />
              </Button>
              <Badge
                v-if="isMapped(field.path)"
                variant="success"
                class="text-[10px] px-1 py-0"
                >mapped</Badge
              >
            </div>
          </div>
          <div
            v-if="visibleFields.length === 0"
            class="px-3 py-4 text-center text-muted-foreground text-sm"
          >
            No fields found in payload
          </div>
        </div>
      </div>

      <!-- Field Mappings -->
      <div>
        <div class="flex items-center justify-between mb-3">
          <Label class="text-sm font-medium">Field Mappings</Label>
          <Button size="sm" variant="outline" @click="addEmptyMapping">
            <Plus class="h-4 w-4 mr-1" />
            Add Mapping
          </Button>
        </div>

        <div
          v-if="localMappings.length === 0"
          class="text-center py-6 text-muted-foreground text-sm border rounded-md"
        >
          No field mappings configured. Fields will be sent as-is.
        </div>

        <div v-else class="space-y-2">
          <!-- Column legend -->
          <div class="hidden sm:flex items-center gap-2 px-2 text-xs text-muted-foreground">
            <span class="w-4 shrink-0" />
            <span class="flex-1">Source <span class="opacity-60">(value)</span></span>
            <span class="w-24 shrink-0">Cast</span>
            <span class="w-4 shrink-0" />
            <span class="flex-0 sm:flex-1">Target <span class="opacity-60">(param name)</span></span>
            <span class="w-8 shrink-0" />
          </div>

          <div
            v-for="(mapping, index) in localMappings"
            :key="index"
            draggable="true"
            :class="[
              'flex flex-wrap items-center gap-2 px-2 py-6 border rounded-md bg-muted/30 transition-opacity',
              draggedIndex === index ? 'opacity-50' : '',
              dragOverIndex === index && draggedIndex !== index
                ? 'border-primary border-dashed'
                : '',
            ]"
            @dragstart="handleDragStart($event, index)"
            @dragover="handleDragOver($event, index)"
            @dragend="handleDragEnd"
          >
            <GripVertical
              class="h-4 w-4 text-muted-foreground shrink-0 cursor-grab active:cursor-grabbing"
              @mousedown="enableDrag"
              @mouseup="disableDrag"
            />

            <!-- Source field - read-only if locked -->
            <div class="flex-1">
              <div class="relative">
                <Input
                  :modelValue="mapping.source"
                  :disabled="mapping.locked"
                  :placeholder="
                    mapping.locked ? '' : 'Source path (e.g., args.0.user_id)'
                  "
                  :class="[
                    'text-sm font-mono',
                    mapping.locked ? 'bg-muted pr-8' : '',
                    mapping.source && !isValidSourcePath(mapping.source)
                      ? '!border-orange-500'
                      : '',
                  ]"
                  @update:modelValue="updateMappingSource(index, $event)"
                />
                <Lock
                  v-if="mapping.locked"
                  class="absolute right-2 top-1/2 -translate-y-1/2 h-3 w-3 text-muted-foreground"
                />
              </div>
              <div
                v-if="mapping.source && !isValidSourcePath(mapping.source)"
                class="absolute flex items-center gap-1 mt-1 text-orange-500 text-xs"
              >
                <AlertTriangle class="size-3" />
                <span>Path not found in payload</span>
              </div>
            </div>

            <Select
              :model-value="castToSelect(mapping.cast)"
              @update:model-value="updateMappingCast(index, castFromSelect($event))"
            >
              <SelectTrigger class="w-24 shrink-0">
                <span class="truncate text-xs" :class="!mapping.cast ? 'text-muted-foreground' : ''">
                  {{ CAST_OPTIONS.find(c => c.value === castToSelect(mapping.cast))?.label ?? 'auto' }}
                </span>
              </SelectTrigger>
              <SelectContent to="#fswa-app">
                <SelectItem v-for="c in CAST_OPTIONS" :key="c.value" :value="c.value">
                  <span :class="c.value === 'auto' ? 'text-muted-foreground' : ''">{{ c.label }}</span>
                </SelectItem>
              </SelectContent>
            </Select>

            <ArrowRight class="h-4 w-4 text-muted-foreground shrink-0" />

            <!-- Target field - always editable -->
            <Input
              :modelValue="mapping.target"
              placeholder="Target path (e.g., user_id)"
              class="flex-0 sm:flex-1 text-sm font-mono"
              @update:modelValue="updateMappingTarget(index, $event)"
            />

            <Button
              size="icon"
              variant="ghost"
              class="h-8 w-8 shrink-0"
              @click="removeMapping(index)"
            >
              <Trash2 class="h-4 w-4 text-destructive" />
            </Button>
          </div>
        </div>
      </div>

      <!-- Excluded Fields -->
      <div v-if="localExcluded.length > 0">
        <Label class="text-sm font-medium mb-2 block">Excluded Fields</Label>
        <div class="flex flex-wrap gap-2">
          <Badge
            v-for="path in localExcluded"
            :key="path"
            variant="secondary"
            class="font-mono text-xs cursor-pointer hover:bg-destructive/20"
            @click="toggleExcluded(path)"
          >
            {{ path }}
            <Trash2 class="h-3 w-3 ml-1" />
          </Badge>
        </div>
      </div>

      <!-- JSON Preview -->
      <div>
        <div class="flex items-center justify-between mb-3">
          <Label class="text-sm font-medium">Transformed Payload Preview</Label>
          <Button size="sm" variant="ghost" @click="previewExpanded = !previewExpanded">
            <component :is="previewExpanded ? ChevronDown : ChevronRight" class="h-4 w-4 mr-1" />
            {{ previewExpanded ? 'Show less' : 'Show full' }}
          </Button>
        </div>

        <div
          class="border rounded-md bg-muted/30 p-3 overflow-x-auto overflow-y-auto"
          :class="previewExpanded ? '' : 'max-h-72'"
        >
          <pre
            class="text-xs font-mono leading-relaxed"
            v-html="previewHtml"
          ></pre>
        </div>
      </div>

      <!-- Original Payload -->
      <div v-if="originalPayload">
        <div class="flex items-center justify-between mb-3">
          <Label class="text-sm font-medium">Original Payload</Label>
          <Button size="sm" variant="ghost" @click="originalExpanded = !originalExpanded">
            <component :is="originalExpanded ? ChevronDown : ChevronRight" class="h-4 w-4 mr-1" />
            {{ originalExpanded ? 'Show less' : 'Show full' }}
          </Button>
        </div>

        <div
          class="border rounded-md bg-muted/30 p-3 overflow-x-auto overflow-y-auto"
          :class="originalExpanded ? '' : 'max-h-72'"
        >
          <pre
            class="text-xs font-mono leading-relaxed"
            v-html="formatJsonWithHighlight(originalPayload)"
          ></pre>
        </div>
      </div>
    </template>
  </div>
</template>
