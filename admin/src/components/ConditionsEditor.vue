<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import { Button, Label, Switch, Badge, Select, SelectTrigger, SelectContent, SelectItem, Input, RadioGroup, RadioGroupItem, Tooltip, UpgradeBadge } from '@/components/ui'
import {
  Plus, X, Info,
  Equal, EqualNot, CircleCheckBig,
  ChevronRight, ChevronLeft,
  Square, CheckSquare,
  ToggleRight, ToggleLeft,
  CheckCircle2, XCircle, CircleDashed,
  FolderPlus, ListChecks,
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
  { value: 'equals',       icon: Equal,          label: 'equals',           short: 'equals' },
  { value: 'not_equals',   icon: EqualNot,       label: 'does not equal',   short: 'not equals' },
  { value: 'contains',     icon: CircleCheckBig, label: 'contains',         short: 'contains' },
  { value: 'not_contains', icon: CircleDashed,   label: 'does not contain', short: 'excludes' },
  { value: 'greater_than', icon: ChevronRight,   label: 'greater than',     short: 'greater than' },
  { value: 'less_than',    icon: ChevronLeft,    label: 'less than',        short: 'less than' },
  { value: 'is_empty',     icon: Square,         label: 'is empty',         short: 'empty' },
  { value: 'is_not_empty', icon: CheckSquare,    label: 'is not empty',     short: 'not empty' },
  { value: 'is_true',          icon: ToggleRight,  label: 'is true',          short: 'is true' },
  { value: 'is_false',         icon: ToggleLeft,   label: 'is false',         short: 'is false' },
  { value: 'array_contains',  icon: ListChecks, label: 'array contains',  short: 'has item' },
  { value: 'object_contains', icon: ListChecks, label: 'object contains', short: 'has value' },
]

const getOperator = (value) => OPERATORS.find((op) => op.value === value)

const OPERATORS_BY_TYPE = {
  string:  new Set(['equals', 'not_equals', 'contains', 'not_contains', 'is_empty', 'is_not_empty']),
  number:  new Set(['equals', 'not_equals', 'greater_than', 'less_than', 'is_empty', 'is_not_empty']),
  boolean: new Set(['equals', 'not_equals', 'is_true', 'is_false']),
  array:   new Set(['is_empty', 'is_not_empty', 'array_contains']),
  object:  new Set(['is_empty', 'is_not_empty', 'object_contains']),
  null:    new Set(['is_empty', 'is_not_empty']),
}

const CAST_OPTIONS = [
  { value: 'auto',      label: 'auto',      short: '—' },
  { value: 'number',    label: 'number',    short: '#' },
  { value: 'string',    label: 'string',    short: '"' },
  { value: 'boolean',   label: 'bool',      short: '?' },
  { value: 'stringify', label: 'stringify', short: '{}' },
]

const castToSelect = (cast) => cast || 'auto'
const castFromSelect = (val) => (val === 'auto' ? null : val)

const valueHidden = (operator) =>
  ['is_empty', 'is_not_empty', 'is_true', 'is_false'].includes(operator)

const keyShown = (operator) => operator === 'object_contains'

const isGroup = (item) => item?.type === 'group'

// Key for group rule: "g{gi}_{ri}" — top-level rules use their numeric index directly
const groupRuleKey = (gi, ri) => `g${gi}_${ri}`

// ── Text mode ─────────────────────────────────────────────────────────────

const globalTextMode = ref(false)
const ruleTextModes = ref({})

const getTextMode = (key) => ruleTextModes.value[key] ?? globalTextMode.value
const setTextMode = (key, val) => {
  ruleTextModes.value = { ...ruleTextModes.value, [key]: val }
}

watch(globalTextMode, (val) => {
  const modes = {}
  conditions.value.rules.forEach((item, i) => {
    if (isGroup(item)) {
      item.rules.forEach((_, ri) => { modes[groupRuleKey(i, ri)] = val })
    } else {
      modes[i] = val
    }
  })
  ruleTextModes.value = modes
})

// ── Field type resolution ─────────────────────────────────────────────────

const ruleFieldTypes = ref({})

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

watch(
  () => props.examplePayload,
  (payload) => {
    if (!payload) return
    const types = {}
    conditions.value.rules.forEach((item, i) => {
      if (isGroup(item)) {
        item.rules.forEach((rule, ri) => {
          if (rule.field) types[groupRuleKey(i, ri)] = resolveType(rule.field, payload)
        })
      } else {
        if (item.field) types[i] = resolveType(item.field, payload)
      }
    })
    ruleFieldTypes.value = types
  },
  { immediate: true }
)

const effectiveType = (key, cast) => {
  if (cast === 'stringify') return 'string'
  return cast || ruleFieldTypes.value[key] || null
}

const isArrayOrObject = (type) => type === 'array' || type === 'object'

// Disable casts that can't meaningfully operate on arrays/objects
const isCastOptionDisabled = (key, castValue) => {
  const type = ruleFieldTypes.value[key]
  if (!isArrayOrObject(type)) return false
  return castValue !== 'stringify' && castValue !== 'auto'
}

const isOperatorEnabled = (key, operator, cast = null) => {
  const type = effectiveType(key, cast)
  if (!type) return true
  return OPERATORS_BY_TYPE[type]?.has(operator) ?? true
}

// ── Top-level CRUD ────────────────────────────────────────────────────────

const toggleEnabled = (val) =>
  emit('update:modelValue', { ...conditions.value, enabled: val })

const addRule = () => {
  if (atFreeLimit.value) return
  const newIndex = conditions.value.rules.length
  if (globalTextMode.value) ruleTextModes.value = { ...ruleTextModes.value, [newIndex]: true }
  emit('update:modelValue', {
    ...conditions.value,
    rules: [...conditions.value.rules, { field: '', operator: 'equals', value: '' }],
  })
}

const removeItem = (index) => {
  const types = { ...ruleFieldTypes.value }
  delete types[index]
  Object.keys(types).forEach((k) => { if (k.startsWith(`g${index}_`)) delete types[k] })
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

const resetOperatorIfNeeded = (rule, key, updateFn) => {
  const type = effectiveType(key, rule?.cast || null)
  if (type && rule && !OPERATORS_BY_TYPE[type]?.has(rule.operator)) {
    const first = OPERATORS.find((op) => OPERATORS_BY_TYPE[type]?.has(op.value))
    if (first) updateFn(first.value)
  }
}

const handleFieldType = (index, type) => {
  ruleFieldTypes.value = { ...ruleFieldTypes.value, [index]: type }
  nextTick(() => {
    const rule = conditions.value.rules[index]
    if (!rule || isGroup(rule)) return
    if (type === 'array') {
      if (rule.operator !== 'array_contains') updateRule(index, 'operator', 'array_contains')
    } else if (type === 'object') {
      if (rule.operator !== 'object_contains') updateRule(index, 'operator', 'object_contains')
    } else {
      resetOperatorIfNeeded(rule, index, (op) => updateRule(index, 'operator', op))
    }
  })
}

const handleCastChange = (index, cast) => {
  const castVal = cast || null
  updateRule(index, 'cast', castVal)
  const rule = { ...conditions.value.rules[index], cast: castVal }
  resetOperatorIfNeeded(rule, index, (op) => updateRule(index, 'operator', op))
}

const handleGroupRuleCastChange = (gi, ri, cast) => {
  const castVal = cast || null
  updateGroupRule(gi, ri, 'cast', castVal)
  const rule = { ...conditions.value.rules[gi]?.rules[ri], cast: castVal }
  resetOperatorIfNeeded(rule, groupRuleKey(gi, ri), (op) => updateGroupRule(gi, ri, 'operator', op))
}

const setType = (type) => {
  if (!props.isPro) return
  emit('update:modelValue', { ...conditions.value, type })
}

// ── Group CRUD ────────────────────────────────────────────────────────────

const addGroup = () => {
  if (atFreeLimit.value || !props.isPro) return
  emit('update:modelValue', {
    ...conditions.value,
    rules: [
      ...conditions.value.rules,
      { type: 'group', match: 'and', rules: [{ field: '', operator: 'equals', value: '' }] },
    ],
  })
}

const updateGroupMatch = (gi, match) =>
  emit('update:modelValue', {
    ...conditions.value,
    rules: conditions.value.rules.map((item, i) => (i === gi ? { ...item, match } : item)),
  })

const addRuleToGroup = (gi) =>
  emit('update:modelValue', {
    ...conditions.value,
    rules: conditions.value.rules.map((item, i) =>
      i === gi
        ? { ...item, rules: [...item.rules, { field: '', operator: 'equals', value: '' }] }
        : item
    ),
  })

const removeRuleFromGroup = (gi, ri) => {
  const types = { ...ruleFieldTypes.value }
  delete types[groupRuleKey(gi, ri)]
  ruleFieldTypes.value = types
  const newRules = conditions.value.rules[gi].rules.filter((_, i) => i !== ri)
  if (newRules.length === 0) { removeItem(gi); return }
  emit('update:modelValue', {
    ...conditions.value,
    rules: conditions.value.rules.map((item, i) => (i === gi ? { ...item, rules: newRules } : item)),
  })
}

const updateGroupRule = (gi, ri, key, value) =>
  emit('update:modelValue', {
    ...conditions.value,
    rules: conditions.value.rules.map((item, i) =>
      i === gi
        ? { ...item, rules: item.rules.map((r, j) => (j === ri ? { ...r, [key]: value } : r)) }
        : item
    ),
  })

const handleGroupRuleFieldType = (gi, ri, type) => {
  const key = groupRuleKey(gi, ri)
  ruleFieldTypes.value = { ...ruleFieldTypes.value, [key]: type }
  nextTick(() => {
    const rule = conditions.value.rules[gi]?.rules[ri]
    if (!rule) return
    if (type === 'array') {
      if (rule.operator !== 'array_contains') updateGroupRule(gi, ri, 'operator', 'array_contains')
    } else if (type === 'object') {
      if (rule.operator !== 'object_contains') updateGroupRule(gi, ri, 'operator', 'object_contains')
    } else {
      resetOperatorIfNeeded(rule, key, (op) => updateGroupRule(gi, ri, 'operator', op))
    }
  })
}

// ── Labels ────────────────────────────────────────────────────────────────

const ruleLabel = (index) => {
  if (index === 0) return 'IF'
  return conditions.value.type === 'or' ? 'OR' : 'AND'
}

const groupRuleLabel = (ri, group) => {
  if (ri === 0) return 'IF'
  return group.match === 'or' ? 'OR' : 'AND'
}

// ── Condition preview ─────────────────────────────────────────────────────

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

const applyRuleCast = (value, cast) => {
  if (!cast) return value
  if (cast === 'number') return Number(value)
  if (cast === 'string') return String(value ?? '')
  if (cast === 'boolean') {
    if (typeof value === 'boolean') return value
    const s = String(value).toLowerCase()
    return s === 'true' || s === '1' || s === 'yes'
  }
  if (cast === 'stringify') {
    if (typeof value === 'object' && value !== null) return JSON.stringify(value)
    return String(value ?? '')
  }
  return value
}

const evaluateRule = (rule, payload) => {
  if (!rule.field || !payload) return null
  let raw = resolveFieldValue(rule.field, payload)
  if (raw === undefined) return null
  if (rule.cast) raw = applyRuleCast(raw, rule.cast)
  const { operator, value } = rule
  const strVal = String(raw ?? '').toLowerCase()
  const compareStr = String(value ?? '').toLowerCase()
  switch (operator) {
    case 'equals':      return raw == value // eslint-disable-line eqeqeq
    case 'not_equals':  return raw != value // eslint-disable-line eqeqeq
    case 'contains':
      return Array.isArray(raw)
        ? raw.some((item) => String(item).toLowerCase() === compareStr)
        : strVal.includes(compareStr)
    case 'not_contains':
      return Array.isArray(raw)
        ? !raw.some((item) => String(item).toLowerCase() === compareStr)
        : !strVal.includes(compareStr)
    case 'greater_than':  return Number(raw) > Number(value)
    case 'less_than':     return Number(raw) < Number(value)
    case 'is_empty':      return isEmpty(raw)
    case 'is_not_empty':  return !isEmpty(raw)
    case 'array_contains':
      return Array.isArray(raw) && raw.map(String).includes(String(value))
    case 'object_contains': {
      if (typeof raw !== 'object' || raw === null) return false
      const key = rule.key ?? ''
      if (key) {
        const deepContainsEntry = (obj, k, v) =>
          Object.entries(obj).some(([, ev]) => {
            if (typeof ev === 'object' && ev !== null) {
              if (Object.prototype.hasOwnProperty.call(ev, k) && String(ev[k]) === v) return true
              return deepContainsEntry(ev, k, v)
            }
            return false
          })
        return deepContainsEntry(raw, key, String(value))
      }
      const deepContains = (obj, search) =>
        Object.values(obj).some((v) =>
          typeof v === 'object' && v !== null
            ? deepContains(v, search)
            : String(v) === search
        )
      return deepContains(raw, String(value))
    }
    case 'is_true':   return raw === true  || raw === 'true'  || raw === 1 || raw === '1'
    case 'is_false':  return raw === false || raw === 'false' || raw === 0 || raw === '0'
    default: return null
  }
}

// Per-item results — groups carry nested ruleResults[]
const itemResults = computed(() => {
  if (!props.examplePayload) return []
  return conditions.value.rules.map((item) => {
    if (isGroup(item)) {
      const results = item.rules.map((rule) => evaluateRule(rule, props.examplePayload))
      const allEvaluable = results.length > 0 && results.every((r) => r !== null)
      const groupResult = !allEvaluable ? null
        : item.match === 'or' ? results.some(Boolean) : results.every(Boolean)
      return { isGroup: true, result: groupResult, ruleResults: results }
    }
    return { isGroup: false, result: evaluateRule(item, props.examplePayload) }
  })
})

const overallResult = computed(() => {
  if (!props.examplePayload || !conditions.value.enabled) return null
  const results = itemResults.value.map((r) => r.result)
  if (results.length === 0 || results.some((r) => r === null)) return null
  return conditions.value.type === 'or' ? results.some(Boolean) : results.every(Boolean)
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
      <Tooltip content="Conditions are evaluated against the original payload, before any field mapping is applied." side="right">
        <Info class="h-3.5 w-3.5 text-muted-foreground cursor-help shrink-0" />
      </Tooltip>
    </div>

    <template v-if="conditions.enabled">
      <!-- Global text mode toggle -->
      <div v-if="examplePayload" class="flex items-center gap-2">
        <Switch
          :model-value="globalTextMode"
          @update:model-value="globalTextMode = $event"
        />
        <Label class="cursor-pointer select-none text-sm text-muted-foreground">
          Manual dot-notation for all fields
        </Label>
      </div>

      <!-- Items (rules + groups) -->
      <div
        v-for="(item, index) in conditions.rules"
        :key="index"
        class="flex gap-2 items-start"
      >
        <span class="text-xs text-muted-foreground w-6 shrink-0 font-mono pt-2.5 text-right">
          {{ ruleLabel(index) }}
        </span>

        <!-- ── Group ── -->
        <template v-if="isGroup(item)">
          <div class="flex-1 border rounded-md p-3 space-y-3 bg-muted/20">
            <!-- Group header: match type + result icon -->
            <div class="flex items-center justify-between">
              <RadioGroup
                :model-value="item.match"
                @update:model-value="updateGroupMatch(index, $event)"
              >
                <label class="flex items-center gap-1.5 cursor-pointer text-xs">
                  <RadioGroupItem value="and" />
                  ALL (AND)
                </label>
                <label class="flex items-center gap-1.5 cursor-pointer text-xs">
                  <RadioGroupItem value="or" />
                  ANY (OR)
                </label>
              </RadioGroup>
              <template v-if="examplePayload">
                <div class="flex items-center gap-2">

                <CheckCircle2
                  v-if="itemResults[index]?.result === true"
                  class="h-4 w-4 text-green-500 shrink-0"
                  title="Group matches example payload"
                />
                <XCircle
                  v-else-if="itemResults[index]?.result === false"
                  class="h-4 w-4 text-destructive shrink-0"
                  title="Group does not match example payload"
                />
                <CircleDashed
                  v-else
                  class="h-4 w-4 text-muted-foreground/40 shrink-0"
                  title="Cannot evaluate"
                />
                <Tooltip
                  :content="`Group matches example payload: ${itemResults[index]?.result === true ? 'yes' : itemResults[index]?.result === false ? 'no' : 'unknown'} (based on ${item.rules.length} rule${item.rules.length > 1 ? 's' : ''})`"
                  :variant="itemResults[index]?.result === false ? 'destructive' : 'default'"
                  side="top"
                >
                  <Info class="h-3.5 w-3.5 text-muted-foreground cursor-help shrink-0" />
                </Tooltip>
              </div>
              </template>
            </div>

            <!-- Rules inside group -->
            <div
              v-for="(rule, ri) in item.rules"
              :key="ri"
              class="flex gap-2 items-start"
            >
              <span class="text-xs text-muted-foreground w-6 shrink-0 font-mono pt-2.5 text-right">
                {{ groupRuleLabel(ri, item) }}
              </span>

              <div class="flex-1 min-w-0 space-y-1.5">
                <!-- Field + result + delete -->
                <div class="flex items-center gap-2">
                  <div class="flex-1 min-w-0">
                    <FieldSelector
                      :model-value="rule.field"
                      :example-payload="examplePayload"
                      :text-mode="getTextMode(groupRuleKey(index, ri))"
                      @update:model-value="updateGroupRule(index, ri, 'field', $event)"
                      @update:field-type="handleGroupRuleFieldType(index, ri, $event)"
                      @update:text-mode="setTextMode(groupRuleKey(index, ri), $event)"
                    />
                  </div>
                  <div class="flex items-center gap-1 shrink-0">
                    <template v-if="examplePayload && rule.field">
                      <CheckCircle2
                        v-if="itemResults[index]?.ruleResults[ri] === true"
                        class="size-4 text-green-500"
                        title="Matches example payload"
                      />
                      <XCircle
                        v-else-if="itemResults[index]?.ruleResults[ri] === false"
                        class="size-4 text-destructive"
                        title="Does not match example payload"
                      />
                      <CircleDashed
                        v-else
                        class="size-4 text-muted-foreground/40"
                        title="Cannot evaluate"
                      />
                    </template>
                    <Button
                      type="button"
                      size="icon"
                      variant="ghost"
                      class="h-7 w-7"
                      @click="removeRuleFromGroup(index, ri)"
                    >
                      <X class="h-3.5 w-3.5" />
                    </Button>
                  </div>
                </div>

                <!-- Cast + operator -->
                <div class="flex items-center gap-2">
                  <Select
                    :model-value="castToSelect(rule.cast)"
                    @update:model-value="handleGroupRuleCastChange(index, ri, castFromSelect($event))"
                  >
                    <SelectTrigger class="w-24 shrink-0">
                      <span class="truncate text-xs" :class="!rule.cast ? 'text-muted-foreground' : ''">
                        {{ CAST_OPTIONS.find(c => c.value === castToSelect(rule.cast))?.label ?? 'auto' }}
                      </span>
                    </SelectTrigger>
                    <SelectContent to="#fswa-app">
                      <SelectItem v-for="c in CAST_OPTIONS" :key="c.value" :value="c.value" :disabled="isCastOptionDisabled(groupRuleKey(index, ri), c.value)">
                        <span :class="c.value === 'auto' ? 'text-muted-foreground' : ''">{{ c.label }}</span>
                      </SelectItem>
                    </SelectContent>
                  </Select>

                  <Select
                    :model-value="rule.operator"
                    @update:model-value="updateGroupRule(index, ri, 'operator', $event)"
                  >
                    <SelectTrigger class="flex-1">
                      <div class="flex items-center gap-1.5 min-w-0">
                        <component :is="getOperator(rule.operator)?.icon" class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                        <span class="truncate text-xs">{{ getOperator(rule.operator)?.short }}</span>
                      </div>
                    </SelectTrigger>
                    <SelectContent to="#fswa-app">
                      <SelectItem
                        v-for="op in OPERATORS"
                        :key="op.value"
                        :value="op.value"
                        :disabled="!isOperatorEnabled(groupRuleKey(index, ri), op.value, rule.cast)"
                      >
                        <div class="flex items-center gap-2">
                          <component :is="op.icon" class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                          {{ op.label }}
                        </div>
                      </SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                <!-- Property key (object_contains only) -->
                <Input
                  v-if="keyShown(rule.operator)"
                  :model-value="rule.key ?? ''"
                  placeholder="property name (optional)"
                  class="w-full text-sm"
                  @update:model-value="updateGroupRule(index, ri, 'key', $event)"
                />

                <!-- Value -->
                <Input
                  v-if="!valueHidden(rule.operator)"
                  :model-value="rule.value"
                  placeholder="value"
                  class="w-full text-sm"
                  @update:model-value="updateGroupRule(index, ri, 'value', $event)"
                />
              </div>
            </div>

            <!-- Add rule to group -->
            <Button
              type="button"
              variant="ghost"
              size="sm"
              class="text-muted-foreground"
              @click="addRuleToGroup(index)"
            >
              <Plus class="h-3.5 w-3.5 mr-1" />
              Add rule
            </Button>
          </div>

          <Button
            type="button"
            size="icon"
            variant="ghost"
            class="shrink-0 mt-1"
            @click="removeItem(index)"
          >
            <X class="h-4 w-4" />
          </Button>
        </template>

        <!-- ── Leaf rule ── -->
        <template v-else>
          <div class="flex-1 min-w-0 space-y-1.5">
            <!-- Field + result + delete -->
            <div class="flex items-center gap-2">
              <div class="flex-1 min-w-0">
                <FieldSelector
                  :model-value="item.field"
                  :example-payload="examplePayload"
                  :text-mode="getTextMode(index)"
                  @update:model-value="updateRule(index, 'field', $event)"
                  @update:field-type="handleFieldType(index, $event)"
                  @update:text-mode="setTextMode(index, $event)"
                />
              </div>
              <div class="flex items-center gap-1 shrink-0">
                <template v-if="examplePayload && item.field">
                  <CheckCircle2
                    v-if="itemResults[index]?.result === true"
                    class="size-4 text-green-500"
                    title="Matches example payload"
                  />
                  <XCircle
                    v-else-if="itemResults[index]?.result === false"
                    class="size-4 text-destructive"
                    title="Does not match example payload"
                  />
                  <CircleDashed
                    v-else
                    class="size-4 text-muted-foreground/40"
                    title="Cannot evaluate"
                  />
                </template>
                <Button
                  type="button"
                  size="icon"
                  variant="ghost"
                  class="h-7 w-7"
                  @click="removeItem(index)"
                >
                  <X class="h-3.5 w-3.5" />
                </Button>
              </div>
            </div>

            <!-- Cast + operator -->
            <div class="flex items-center gap-2">
              <Select
                :model-value="castToSelect(item.cast)"
                @update:model-value="handleCastChange(index, castFromSelect($event))"
              >
                <SelectTrigger class="w-24 shrink-0">
                  <span class="truncate text-xs" :class="!item.cast ? 'text-muted-foreground' : ''">
                    {{ CAST_OPTIONS.find(c => c.value === castToSelect(item.cast))?.label ?? 'auto' }}
                  </span>
                </SelectTrigger>
                <SelectContent to="#fswa-app">
                  <SelectItem v-for="c in CAST_OPTIONS" :key="c.value" :value="c.value" :disabled="isCastOptionDisabled(index, c.value)">
                    <span :class="c.value === 'auto' ? 'text-muted-foreground' : ''">{{ c.label }}</span>
                  </SelectItem>
                </SelectContent>
              </Select>

              <Select
                :model-value="item.operator"
                @update:model-value="updateRule(index, 'operator', $event)"
              >
                <SelectTrigger class="flex-1">
                  <div class="flex items-center gap-1.5 min-w-0">
                    <component :is="getOperator(item.operator)?.icon" class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                    <span class="truncate text-xs">{{ getOperator(item.operator)?.short }}</span>
                  </div>
                </SelectTrigger>
                <SelectContent to="#fswa-app">
                  <SelectItem
                    v-for="op in OPERATORS"
                    :key="op.value"
                    :value="op.value"
                    :disabled="!isOperatorEnabled(index, op.value, item.cast)"
                  >
                    <div class="flex items-center gap-2">
                      <component :is="op.icon" class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                      {{ op.label }}
                    </div>
                  </SelectItem>
                </SelectContent>
              </Select>
            </div>

            <!-- Property key (object_contains only) -->
            <Input
              v-if="keyShown(item.operator)"
              :model-value="item.key ?? ''"
              placeholder="property name (optional)"
              class="w-full text-sm"
              @update:model-value="updateRule(index, 'key', $event)"
            />

            <!-- Value -->
            <Input
              v-if="!valueHidden(item.operator)"
              :model-value="item.value"
              placeholder="value"
              class="w-full text-sm"
              @update:model-value="updateRule(index, 'value', $event)"
            />
          </div>
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

      <!-- Add buttons -->
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
        <Button
          v-if="isPro"
          type="button"
          variant="outline"
          size="sm"
          :disabled="atFreeLimit"
          @click="addGroup"
        >
          <FolderPlus class="h-4 w-4 mr-1" />
          Add group
        </Button>
        <UpgradeBadge v-if="atFreeLimit && !isPro" />
      </div>

      <!-- Match type -->
      <div class="flex items-center gap-3 text-sm">
        <span class="text-muted-foreground">Match:</span>
        <RadioGroup
          :model-value="conditions.type"
          :disabled="!isPro"
          @update:model-value="setType($event)"
        >
          <label
            class="flex items-center gap-1.5"
            :class="isPro ? 'cursor-pointer' : 'opacity-50 cursor-not-allowed'"
          >
            <RadioGroupItem value="and" :disabled="!isPro" />
            ALL (AND)
          </label>
          <label
            class="flex items-center gap-1.5"
            :class="isPro ? 'cursor-pointer' : 'opacity-50 cursor-not-allowed'"
          >
            <RadioGroupItem value="or" :disabled="!isPro" />
            ANY (OR)
          </label>
        </RadioGroup>
        <UpgradeBadge v-if="!isPro" />
      </div>
    </template>

    <p v-else class="text-sm text-muted-foreground">
      This webhook will run on every trigger.
    </p>
  </div>
</template>
