<script setup>
import { ref, computed, watch } from 'vue';
import {
  Plus,
  Trash2,
  GripVertical,
  ArrowRight,
  Eye,
  EyeOff,
  ChevronDown,
  ChevronRight,
  Lock,
  AlertTriangle,
} from 'lucide-vue-next';
import { Button, Input, Switch, Label, Badge } from '@/components/ui';

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
const showPreview = ref(true);
const expandedPaths = ref({});

// Drag and drop state
const draggedIndex = ref(null);
const dragOverIndex = ref(null);
const canDrag = ref(false);

// Flatten payload to show available fields - supports arrays with depth limit
const flattenObject = (obj, prefix = '', depth = 0, maxDepth = 4) => {
  const result = [];

  if (!obj || typeof obj !== 'object' || depth > maxDepth) {
    return result;
  }

  const entries = Array.isArray(obj)
    ? obj.map((v, i) => [String(i), v])
    : Object.entries(obj);

  for (const [key, value] of entries) {
    const path = prefix ? `${prefix}.${key}` : key;
    const isArray = Array.isArray(value);
    const isObject = value !== null && typeof value === 'object';

    if (isObject) {
      // Add the parent path as expandable
      result.push({
        path,
        value,
        type: isArray ? 'array' : 'object',
        isExpandable: true,
        depth,
        childCount: isArray ? value.length : Object.keys(value).length,
      });
      // Recursively flatten children
      result.push(...flattenObject(value, path, depth + 1, maxDepth));
    } else {
      result.push({
        path,
        value,
        type: value === null ? 'null' : typeof value,
        isExpandable: false,
        depth,
      });
    }
  }

  return result;
};

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
    const pathParts = field.path.split('.');
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
        locked: m.locked !== false, // Keep locked state, default to true for existing
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
        expandedPaths.value[key] = true;
        const value = effectivePayload.value[key];
        if (value && typeof value === 'object') {
          const subKeys = Array.isArray(value)
            ? value.map((_, i) => i)
            : Object.keys(value);
          subKeys.forEach((subKey) => {
            expandedPaths.value[`${key}.${subKey}`] = true;
          });
        }
      });
    }
  },
  { immediate: true },
);

// Emit changes
const emitUpdate = () => {
  emit('update:modelValue', {
    mappings: localMappings.value
      .filter((m) => m.source && m.target)
      .map((m) => ({ source: m.source, target: m.target })),
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

// Get value by dot-notation path
const getValueByPath = (obj, path) => {
  const keys = path.split('.');
  let current = obj;

  for (const key of keys) {
    if (current === null || current === undefined) return undefined;
    if (typeof current !== 'object') return undefined;
    current = Array.isArray(current)
      ? current[parseInt(key, 10)]
      : current[key];
  }

  return current;
};

// Check if a source path exists in the payload
const isValidSourcePath = (path) => {
  if (!path || !effectivePayload.value) return false;
  return getValueByPath(effectivePayload.value, path) !== undefined;
};

// Set value by dot-notation path
// ref: optional reference object (e.g. the original payload) used to determine
// whether intermediate containers should be arrays or plain objects.
// Without ref, large numeric-keyed objects like WC line_items { "303962": {...} }
// would be mis-created as sparse arrays with 303k empty slots.
const setValueByPath = (obj, path, value, ref = null) => {
  const keys = path.split('.');
  let current = obj;
  let currentRef = ref;

  for (let i = 0; i < keys.length - 1; i++) {
    const key = keys[i];
    const nextKey = keys[i + 1];

    if (
      current[key] === undefined ||
      current[key] === null ||
      typeof current[key] !== 'object'
    ) {
      let isNextArray = false;
      if (currentRef !== null && currentRef !== undefined && typeof currentRef === 'object') {
        // Look at what the ref holds at `key` to decide the container type
        const refVal = Array.isArray(currentRef)
          ? currentRef[parseInt(key, 10)]
          : currentRef[key];
        if (refVal !== undefined && refVal !== null) {
          isNextArray = Array.isArray(refVal);
        } else {
          // Path doesn't exist in ref — fall back to numeric heuristic
          isNextArray = /^\d+$/.test(nextKey);
        }
      } else {
        isNextArray = /^\d+$/.test(nextKey);
      }
      current[key] = isNextArray ? [] : {};
    }

    // Advance the reference pointer in parallel with current
    if (currentRef !== null && currentRef !== undefined && typeof currentRef === 'object') {
      currentRef = Array.isArray(currentRef)
        ? currentRef[parseInt(key, 10)]
        : currentRef[key];
    } else {
      currentRef = null;
    }

    current = current[key];
  }

  current[keys[keys.length - 1]] = value;
};

// Flatten for transformation - recursively flattens ALL arrays and objects
const flattenForTransform = (obj, prefix = '', depth = 0, maxDepth = 10) => {
  const result = {};

  if (!obj || typeof obj !== 'object' || depth > maxDepth) {
    return result;
  }

  const entries = Array.isArray(obj)
    ? obj.map((v, i) => [String(i), v])
    : Object.entries(obj);

  for (const [key, value] of entries) {
    const path = prefix ? `${prefix}.${key}` : key;

    if (value !== null && typeof value === 'object') {
      // Always recurse into objects and arrays
      Object.assign(
        result,
        flattenForTransform(value, path, depth + 1, maxDepth),
      );
    } else {
      result[path] = value;
    }
  }

  return result;
};

// Check if a path should be excluded
const isPathExcluded = (path, excludedPaths) => {
  for (const excludedPath of excludedPaths) {
    if (path === excludedPath || path.startsWith(excludedPath + '.')) {
      return true;
    }
  }
  return false;
};

// Compute transformed preview
const transformedPreview = computed(() => {
  if (!effectivePayload.value) return null;

  const mappings = localMappings.value.filter((m) => m.source && m.target);
  const excluded = localExcluded.value;
  const includeUnmapped = localIncludeUnmapped.value;

  // If no configuration, return original
  if (mappings.length === 0 && excluded.length === 0 && includeUnmapped) {
    return effectivePayload.value;
  }

  const flatPayload = flattenForTransform(effectivePayload.value);
  const result = {};
  const mappedSourcePaths = mappings.map((m) => m.source);

  // Apply explicit mappings first
  for (const map of mappings) {
    const value = getValueByPath(effectivePayload.value, map.source);
    if (value !== undefined) {
      setValueByPath(result, map.target, value, effectivePayload.value);
    }
  }

  // Include unmapped fields if enabled
  if (includeUnmapped) {
    for (const [path, value] of Object.entries(flatPayload)) {
      // Skip if this path is mapped
      if (mappedSourcePaths.includes(path)) continue;

      // Skip if this path is excluded
      if (isPathExcluded(path, excluded)) continue;

      // Include this field at its original path
      setValueByPath(result, path, value, effectivePayload.value);
    }
  }

  return result;
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
                field.path.split('.').pop()
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
          <Button size="sm" variant="ghost" @click="showPreview = !showPreview">
            <component :is="showPreview ? EyeOff : Eye" class="h-4 w-4 mr-1" />
            {{ showPreview ? 'Hide' : 'Show' }}
          </Button>
        </div>

        <div
          v-if="showPreview"
          class="border rounded-md bg-muted/30 p-3 overflow-x-auto"
        >
          <pre
            class="text-xs font-mono leading-relaxed"
            v-html="previewHtml"
          ></pre>
        </div>
      </div>
    </template>
  </div>
</template>
