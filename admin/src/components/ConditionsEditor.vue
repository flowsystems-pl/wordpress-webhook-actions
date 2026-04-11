<script setup>
import { ref, computed, watch } from 'vue'
import { Button, Label, Switch, Badge, Select, SelectTrigger, SelectContent, SelectItem, Input } from '@/components/ui'
import {
  Plus, X, Lock,
  Equal, EqualNot, Search, SearchX,
  ChevronRight, ChevronLeft,
  Square, CheckSquare,
  ToggleRight, ToggleLeft,
  CheckCircle2, XCircle, CircleDashed,
} from 'lucide-vue-next'
import FieldSelector from '@/components/FieldSelector.vue'

const props = defineProps({
  modelValue: {
    type: Object,
    default: () => ({ enabled: false, type: 'and', rules: [] }),
  },
  isPro: {
    type: Boolean,
    default: false,
  },
  examplePayload: {
    type: Object,
    default: null,
  },
})

const emit = defineEmits(['update:modelValue'])

const FREE_RULE_LIMIT = 1

const conditions = computed({
  get: () => props.modelValue ?? { enabled: false, type: 'and', rules: [] },
  set: (val) => emit('update:modelValue', val),
})

const atFreeLimit = computed(
  () => !props.isPro && conditions.value.rules.length >= FREE_RULE_LIMIT
)

const OPERATORS = [
  { value: 'equals',       icon: Equal,        label: 'equals',          short: 'equals' },
  { value: 'not_equals',   icon: EqualNot,     label: 'does not equal',  short: '≠ equal' },
  { value: 'contains',     icon: Search,       label: 'contains',        short: 'contains' },
  { value: 'not_contains', icon: SearchX,      label: 'does not contain',short: 'excludes' },
  { value: 'greater_than', icon: ChevronRight, label: 'greater than',    short: '> than' },
  { value: 'less_than',    icon: ChevronLeft,  label: 'less than',       short: '< than' },
  { value: 'is_empty',     icon: Square,       label: 'is empty',        short: 'empty' },
  { value: 'is_not_empty', icon: CheckSquare,  label: 'is not empty',    short: 'not empty' },
  { value: 'is_true',      icon: ToggleRight,  label: 'is true',         short: 'is true' },
  { value: 'is_false',     icon: ToggleLeft,   label: 'is false',        short: 'is false' },
]

const getOperator = (value) => OPERATORS.find((op) => op.value === value)

const OPERATORS_BY_TYPE = {
  string:  new Set(['equals', 'not_equals', 'contains', 'not_contains', 'is_empty', 'is_not_empty']),
  number:  new Set(['equals', 'not_equals', 'greater_than', 'less_than', 'is_empty', 'is_not_empty']),
  boolean: new Set(['equals', 'not_equals', 'is_true', 'is_false']),
  array:   new Set(['is_empty', 'is_not_empty', 'contains', 'not_contains']),
  object:  new Set(['is_empty', 'is_not_empty']),
  null:    new Set(['is_empty', 'is_not_empty']),
}

const valueHidden = (operator) =>
  ['is_empty', 'is_not_empty', 'is_true', 'is_false'].includes(operator)

// Global text-mode toggle (applies to all rules; individual rules can still override)
const globalTextMode = ref(false)
const ruleTextModes = ref({})

const getTextMode = (index) => ruleTextModes.value[index] ?? globalTextMode.value

const setTextMode = (index, val) => {
  ruleTextModes.value = { ...ruleTextModes.value, [index]: val }
}

watch(globalTextMode, (val) => {
  const modes = {}
  conditions.value.rules.forEach((_, i) => { modes[i] = val })
  ruleTextModes.value = modes
})

// Per-rule resolved field types
const ruleFieldTypes = ref({})

// Path helpers for resolving field type from payload
const splitPath = (path) =>
  path.split(/(?<!\\)\./).map((p) => p.replace(/\\\./g, '.'))

const resolveType = (path, payload) => {
  if (!path || !payload) return null
  const keys = splitPath(path)
  let data = payload
  for (const key of keys) {
    if (data === null || typeof data !== 'object') return null
    const val = Object.prototype.hasOwnProperty.call(data, key)
      ? data[key]
      : data[parseInt(key)]
    if (val === undefined) return null
    data = val
  }
  if (data === null) return 'null'
  if (Array.isArray(data)) return 'array'
  return typeof data
}

// Resolve types for all existing rules when payload loads
watch(
  () => props.examplePayload,
  (payload) => {
    if (!payload) return
    const types = {}
    conditions.value.rules.forEach((rule, i) => {
      if (rule.field) types[i] = resolveType(rule.field, payload)
    })
    ruleFieldTypes.value = types
  },
  { immediate: true }
)

const isOperatorEnabled = (ruleIndex, operator) => {
  const type = ruleFieldTypes.value[ruleIndex]
  if (!type) return true
  return OPERATORS_BY_TYPE[type]?.has(operator) ?? true
}

const handleFieldType = (index, type) => {
  ruleFieldTypes.value = { ...ruleFieldTypes.value, [index]: type }
  // Auto-reset operator if it's no longer valid for the new type
  const rule = conditions.value.rules[index]
  if (type && rule && !OPERATORS_BY_TYPE[type]?.has(rule.operator)) {
    const firstValid = OPERATORS.find((op) => OPERATORS_BY_TYPE[type]?.has(op.value))
    if (firstValid) updateRule(index, 'operator', firstValid.value)
  }
}

const toggleEnabled = (val) =>
  emit('update:modelValue', { ...conditions.value, enabled: val })

const addRule = () => {
  if (atFreeLimit.value) return
  const newIndex = conditions.value.rules.length
  if (globalTextMode.value) {
    ruleTextModes.value = { ...ruleTextModes.value, [newIndex]: true }
  }
  emit('update:modelValue', {
    ...conditions.value,
    rules: [...conditions.value.rules, { field: '', operator: 'equals', value: '' }],
  })
}

const removeRule = (index) => {
  const types = { ...ruleFieldTypes.value }
  delete types[index]
  ruleFieldTypes.value = types
  emit('update:modelValue', {
    ...conditions.value,
    rules: conditions.value.rules.filter((_, i) => i !== index),
  })
}

const updateRule = (index, key, value) =>
  emit('update:modelValue', {
    ...conditions.value,
    rules: conditions.value.rules.map((r, i) => (i === index ? { ...r, [key]: value } : r)),
  })

const setType = (type) => {
  if (!props.isPro) return
  emit('update:modelValue', { ...conditions.value, type })
}

const ruleLabel = (index) => {
  if (index === 0) return 'IF'
  return conditions.value.type === 'or' ? 'OR' : 'AND'
}

// ── Condition preview against example_payload ─────────────────────────────

const resolveFieldValue = (path, payload) => {
  if (!path || !payload) return undefined
  const keys = splitPath(path)
  let data = payload
  for (const key of keys) {
    if (data === null || typeof data !== 'object') return undefined
    const val = Object.prototype.hasOwnProperty.call(data, key)
      ? data[key]
      : data[parseInt(key)]
    if (val === undefined) return undefined
    data = val
  }
  return data
}

const isEmpty = (val) => {
  if (val === null || val === undefined || val === '') return true
  if (Array.isArray(val)) return val.length === 0
  if (typeof val === 'object') return Object.keys(val).length === 0
  return false
}

const evaluateRule = (rule, payload) => {
  if (!rule.field || !payload) return null
  const raw = resolveFieldValue(rule.field, payload)
  if (raw === undefined) return null

  const { operator, value } = rule
  const strVal = String(raw ?? '').toLowerCase()
  const compareStr = String(value ?? '').toLowerCase()

  switch (operator) {
    case 'equals':
      // eslint-disable-next-line eqeqeq
      return raw == value
    case 'not_equals':
      // eslint-disable-next-line eqeqeq
      return raw != value
    case 'contains':
      if (Array.isArray(raw)) return raw.some((item) => String(item).toLowerCase() === compareStr)
      return strVal.includes(compareStr)
    case 'not_contains':
      if (Array.isArray(raw)) return !raw.some((item) => String(item).toLowerCase() === compareStr)
      return !strVal.includes(compareStr)
    case 'greater_than':
      return Number(raw) > Number(value)
    case 'less_than':
      return Number(raw) < Number(value)
    case 'is_empty':
      return isEmpty(raw)
    case 'is_not_empty':
      return !isEmpty(raw)
    case 'is_true':
      return raw === true || raw === 'true' || raw === 1 || raw === '1'
    case 'is_false':
      return raw === false || raw === 'false' || raw === 0 || raw === '0'
    default:
      return null
  }
}

// Per-rule results: true | false | null (null = can't evaluate)
const ruleResults = computed(() => {
  if (!props.examplePayload) return []
  return conditions.value.rules.map((rule) => evaluateRule(rule, props.examplePayload))
})

// Overall result: true | false | null
const overallResult = computed(() => {
  if (!props.examplePayload || !conditions.value.enabled) return null
  const results = ruleResults.value
  if (results.length === 0) return null
  if (results.some((r) => r === null)) return null
  return conditions.value.type === 'or'
    ? results.some(Boolean)
    : results.every(Boolean)
})
</script>

<template>
  <div class="space-y-4">
    <!-- Enable toggle -->
    <div class="flex items-center gap-2">
      <Switch
        :model-value="conditions.enabled"
        @update:model-value="toggleEnabled"
      />
      <Label class="cursor-pointer select-none">Enable conditional dispatch</Label>
    </div>

    <template v-if="conditions.enabled">
      <!-- Global text mode toggle (only shown when payload is available) -->
      <div v-if="examplePayload" class="flex items-center gap-2">
        <Switch
          :model-value="globalTextMode"
          @update:model-value="globalTextMode = $event"
        />
        <Label class="cursor-pointer select-none text-sm text-muted-foreground">
          Manual dot-notation for all fields
        </Label>
      </div>

      <!-- Rule rows -->
      <div
        v-for="(rule, index) in conditions.rules"
        :key="index"
        class="flex items-center gap-2"
      >
        <span class="text-xs text-muted-foreground w-6 shrink-0 font-mono h-10 flex items-center">
          {{ ruleLabel(index) }}
        </span>

        <FieldSelector
          :model-value="rule.field"
          :example-payload="examplePayload"
          :text-mode="getTextMode(index)"
          @update:model-value="updateRule(index, 'field', $event)"
          @update:field-type="handleFieldType(index, $event)"
          @update:text-mode="setTextMode(index, $event)"
        />

        <Select
          :model-value="rule.operator"
          @update:model-value="updateRule(index, 'operator', $event)"
        >
          <SelectTrigger class="w-32 shrink-0">
            <div class="flex items-center gap-1.5 min-w-0">
              <component
                :is="getOperator(rule.operator)?.icon"
                class="h-3.5 w-3.5 shrink-0 text-muted-foreground"
              />
              <span class="truncate text-xs">{{ getOperator(rule.operator)?.short }}</span>
            </div>
          </SelectTrigger>
          <SelectContent to="#fswa-app">
            <SelectItem
              v-for="op in OPERATORS"
              :key="op.value"
              :value="op.value"
              :disabled="!isOperatorEnabled(index, op.value)"
            >
              <div class="flex items-center gap-2">
                <component :is="op.icon" class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                {{ op.label }}
              </div>
            </SelectItem>
          </SelectContent>
        </Select>

        <Input
          v-if="!valueHidden(rule.operator)"
          :model-value="rule.value"
          placeholder="value"
          class="w-36 shrink-0 text-sm"
          @update:model-value="updateRule(index, 'value', $event)"
        />
        <div v-else class="w-36 shrink-0" />

        <Button
          type="button"
          size="icon"
          variant="ghost"
          class="shrink-0"
          @click="removeRule(index)"
        >
          <X class="h-4 w-4" />
        </Button>

        <!-- Per-rule preview result -->
        <template v-if="examplePayload && rule.field">
          <CheckCircle2
            v-if="ruleResults[index] === true"
            class="size-6 shrink-0 text-green-500"
            title="Matches example payload"
          />
          <XCircle
            v-else-if="ruleResults[index] === false"
            class="size-6 shrink-0 text-destructive"
            title="Does not match example payload"
          />
          <CircleDashed
            v-else
            class="h-4 w-4 shrink-0 text-muted-foreground/40"
            title="Cannot evaluate"
          />
        </template>
      </div>

      <!-- Payload preview summary -->
      <div v-if="examplePayload && conditions.rules.length > 0 && overallResult !== null" class="flex items-center gap-2 text-sm">
        <CheckCircle2 v-if="overallResult" class="size-6 text-green-500 shrink-0" />
        <XCircle v-else class="size-6 text-destructive shrink-0" />
        <span :class="overallResult ? 'text-green-600 dark:text-green-400' : 'text-destructive'">
          Example payload would <strong>{{ overallResult ? 'dispatch' : 'be skipped' }}</strong>
        </span>
      </div>

      <!-- Add condition row -->
      <div class="flex items-center gap-2">
        <Button
          type="button"
          variant="outline"
          size="sm"
          :disabled="atFreeLimit"
          @click="addRule"
        >
          <Plus class="h-4 w-4 mr-1" />
          Add condition
        </Button>
        <Badge
          v-if="atFreeLimit && !isPro"
          variant="outline"
          class="text-xs gap-1"
        >
          <Lock class="h-3 w-3" />
          Pro
        </Badge>
      </div>

      <!-- Match type -->
      <div class="flex items-center gap-3 text-sm">
        <span class="text-muted-foreground">Match:</span>
        <label
          class="flex items-center gap-1.5"
          :class="isPro ? 'cursor-pointer' : 'opacity-50 cursor-not-allowed'"
        >
          <input
            type="radio"
            :checked="conditions.type === 'and'"
            :disabled="!isPro"
            @change="setType('and')"
          />
          ALL (AND)
        </label>
        <label
          class="flex items-center gap-1.5"
          :class="isPro ? 'cursor-pointer' : 'opacity-50 cursor-not-allowed'"
        >
          <input
            type="radio"
            :checked="conditions.type === 'or'"
            :disabled="!isPro"
            @change="setType('or')"
          />
          ANY (OR)
        </label>
        <Badge v-if="!isPro" variant="outline" class="text-xs gap-1">
          <Lock class="h-3 w-3" />
          Pro
        </Badge>
      </div>
    </template>

    <p v-else class="text-sm text-muted-foreground">
      This webhook will run on every trigger.
    </p>
  </div>
</template>
