<script setup>
import { ref, computed, watch } from 'vue'
import { ChevronDown, ChevronLeft, ChevronRight, Search, Hash, FolderOpen, AlertTriangle, PenLine, ListTree, Plus } from 'lucide-vue-next'
import { Popover, Badge, Input } from '@/components/ui'

const props = defineProps({
  modelValue: { type: String, default: '' },
  examplePayload: { type: Object, default: null },
  textMode: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'update:fieldType', 'update:textMode'])

const open = ref(false)
const search = ref('')
const navigationPath = ref([])

// Path helpers (same as MappingEditor)
const escapeKey = (key) => key.replace(/\./g, '\\.')
const splitPath = (path) =>
  path.split(/(?<!\\)\./).map((p) => p.replace(/\\\./g, '.'))

// Traverse the payload to the current navigation level
const currentLevelData = computed(() => {
  if (!props.examplePayload) return null
  let data = props.examplePayload
  for (const key of navigationPath.value) {
    if (data === null || typeof data !== 'object') return null
    data = Object.prototype.hasOwnProperty.call(data, key)
      ? data[key]
      : data[parseInt(key)]
    if (data === undefined) return null
  }
  return data
})

const currentItems = computed(() => {
  const data = currentLevelData.value
  if (data === null || data === undefined || typeof data !== 'object') return []
  const entries = Array.isArray(data)
    ? data.map((v, i) => [String(i), v])
    : Object.entries(data)
  return entries.map(([key, value]) => ({
    key,
    value,
    type: Array.isArray(value) ? 'array' : value === null ? 'null' : typeof value,
    isLeaf: value === null || typeof value !== 'object',
  }))
})

const filteredItems = computed(() => {
  const q = search.value.toLowerCase()
  if (!q) return currentItems.value
  return currentItems.value.filter((item) => item.key.toLowerCase().includes(q))
})

// Validation for text mode
const getValueByPath = (obj, path) => {
  const keys = splitPath(path)
  let current = obj
  for (const key of keys) {
    if (current === null || current === undefined || typeof current !== 'object') return undefined
    current = Array.isArray(current) ? current[parseInt(key, 10)] : current[key]
  }
  return current
}

const isValidPath = computed(() => {
  if (!props.modelValue || !props.examplePayload) return true // no validation without payload
  return getValueByPath(props.examplePayload, props.modelValue) !== undefined
})

// Derive type from a path in the payload
const resolveType = (path) => {
  if (!path || !props.examplePayload) return null
  const val = getValueByPath(props.examplePayload, path)
  if (val === undefined) return null
  if (val === null) return 'null'
  if (Array.isArray(val)) return 'array'
  return typeof val
}

const selectItem = (item) => {
  const fullPath = [...navigationPath.value, item.key].map(escapeKey).join('.')
  emit('update:modelValue', fullPath)
  emit('update:fieldType', item.type)
  open.value = false
  search.value = ''
  navigationPath.value = []
}

const navigateInto = (item) => {
  navigationPath.value = [...navigationPath.value, item.key]
  search.value = ''
}

const goBack = () => {
  navigationPath.value = navigationPath.value.slice(0, -1)
  search.value = ''
}

const handleTextInput = (val) => {
  emit('update:modelValue', val)
  emit('update:fieldType', resolveType(val))
}

const switchToText = () => emit('update:textMode', true)
const switchToSelector = () => emit('update:textMode', false)

// Reset navigation when popover closes
watch(open, (val) => {
  if (!val) {
    search.value = ''
    navigationPath.value = []
  }
})

const getTypeBadgeVariant = (type) => {
  const variants = { string: 'secondary', number: 'default', boolean: 'warning', array: 'outline', object: 'outline', null: 'destructive' }
  return variants[type] || 'secondary'
}

const getValuePreview = (value) => {
  if (value === null) return 'null'
  if (typeof value === 'boolean') return value ? 'true' : 'false'
  if (typeof value === 'number') return String(value)
  if (typeof value === 'string') return value.length > 18 ? `"${value.substring(0, 18)}…"` : `"${value}"`
  return ''
}
</script>

<template>
  <div class="flex-1 min-w-0 space-y-1">
    <div class="flex items-center gap-1">

      <!-- TEXT MODE -->
      <template v-if="textMode || !examplePayload">
        <div class="flex-1 min-w-0">
          <Input
            :model-value="modelValue"
            placeholder="data.field_name"
            :class="[
              'font-mono text-sm',
              modelValue && !isValidPath ? '!border-orange-500' : '',
            ]"
            @update:model-value="handleTextInput"
          />
        </div>
        <button
          type="button"
          title="Switch to field selector"
          class="shrink-0 p-1.5 rounded hover:bg-muted text-muted-foreground hover:text-foreground transition-colors"
          @click="switchToSelector"
        >
          <ListTree class="h-4 w-4" />
        </button>
      </template>

      <!-- SELECTOR MODE -->
      <template v-else-if="!textMode">
        <Popover
          :open="open"
          content-class="p-0 w-72"
          @update:open="open = $event"
        >
          <template #trigger>
            <button
              type="button"
              class="flex-1 flex items-center justify-between gap-2 h-10 px-3 rounded-md border border-input bg-background text-sm font-mono hover:bg-muted/50 transition-colors min-w-0"
              :class="modelValue ? 'text-foreground' : 'text-muted-foreground'"
            >
              <span class="truncate">{{ modelValue || 'Select field…' }}</span>
              <ChevronDown class="h-4 w-4 shrink-0 opacity-50" />
            </button>
          </template>

          <!-- Breadcrumb navigation -->
          <div v-if="navigationPath.length" class="flex items-center gap-1.5 px-3 py-2 border-b">
            <button
              type="button"
              class="p-0.5 hover:bg-muted rounded"
              @click.stop="goBack"
            >
              <ChevronLeft class="h-4 w-4 text-muted-foreground" />
            </button>
            <span class="text-xs font-mono text-muted-foreground truncate">
              {{ navigationPath.join(' › ') }}
            </span>
          </div>

          <!-- Search input -->
          <div class="flex items-center border-b px-3" :class="{ 'border-t': !navigationPath.length }">
            <Search class="h-4 w-4 text-muted-foreground mr-2 shrink-0" />
            <input
              v-model="search"
              placeholder="Search fields…"
              class="py-2.5 text-sm bg-transparent outline-none w-full placeholder:text-muted-foreground"
              @keydown.esc.stop="open = false"
            />
          </div>

          <!-- Item list -->
          <div class="max-h-56 overflow-y-auto py-1">
            <div
              v-if="!filteredItems.length"
              class="text-xs text-muted-foreground px-3 py-4 text-center"
            >
              No fields found
            </div>
            <div
              v-for="item in filteredItems"
              :key="item.key"
              class="w-full flex items-center gap-2 px-3 py-1.5 text-sm hover:bg-muted text-left group"
            >
              <button
                type="button"
                class="flex items-center gap-2 flex-1 min-w-0 text-left"
                @click.stop="item.isLeaf ? selectItem(item) : navigateInto(item)"
              >
                <component
                  :is="item.isLeaf ? Hash : FolderOpen"
                  class="h-3.5 w-3.5 text-muted-foreground shrink-0"
                />
                <code class="font-mono text-xs flex-1 truncate">{{ item.key }}</code>
                <Badge
                  :variant="getTypeBadgeVariant(item.type)"
                  class="text-[10px] px-1 py-0 shrink-0"
                >
                  {{ item.type }}
                </Badge>
                <span
                  v-if="item.isLeaf && getValuePreview(item.value)"
                  class="text-xs text-muted-foreground shrink-0 max-w-20 truncate"
                >
                  {{ getValuePreview(item.value) }}
                </span>
                <ChevronRight
                  v-if="!item.isLeaf"
                  class="h-3.5 w-3.5 text-muted-foreground shrink-0"
                />
              </button>
              <button
                v-if="!item.isLeaf"
                type="button"
                title="Select this field"
                class="shrink-0 p-0.5 rounded hover:bg-accent text-muted-foreground hover:text-foreground transition-colors opacity-0 group-hover:opacity-100"
                @click.stop="selectItem(item)"
              >
                <Plus class="h-3.5 w-3.5" />
              </button>
            </div>
          </div>

          <!-- Footer hint -->
          <div
            v-if="!modelValue"
            class="px-3 py-2 border-t text-[10px] text-muted-foreground"
          >
            Click to select or browse • + to select an array/object
          </div>
        </Popover>

        <button
          type="button"
          title="Switch to manual input"
          class="shrink-0 p-1.5 rounded hover:bg-muted text-muted-foreground hover:text-foreground transition-colors"
          @click="switchToText"
        >
          <PenLine class="h-4 w-4" />
        </button>
      </template>
    </div>

    <!-- Validation message (text mode only) -->
    <div
      v-if="(textMode || !examplePayload) && modelValue && !isValidPath && examplePayload"
      class="flex items-center gap-1 text-orange-500 text-xs"
    >
      <AlertTriangle class="h-3 w-3 shrink-0" />
      <span>Path not found in payload</span>
    </div>
  </div>
</template>
