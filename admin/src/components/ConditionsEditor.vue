<script setup>
import { computed } from 'vue'
import { Button, Input, Label, Switch, Badge, Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/components/ui'
import { Plus, X, Lock } from 'lucide-vue-next'

const props = defineProps({
  modelValue: {
    type: Object,
    default: () => ({ enabled: false, type: 'and', rules: [] }),
  },
  isPro: {
    type: Boolean,
    default: false,
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
  { value: 'equals',        label: 'equals' },
  { value: 'not_equals',    label: 'does not equal' },
  { value: 'contains',      label: 'contains' },
  { value: 'not_contains',  label: 'does not contain' },
  { value: 'greater_than',  label: 'greater than' },
  { value: 'less_than',     label: 'less than' },
  { value: 'is_empty',      label: 'is empty' },
  { value: 'is_not_empty',  label: 'is not empty' },
  { value: 'is_true',       label: 'is true' },
  { value: 'is_false',      label: 'is false' },
]

const valueHidden = (operator) =>
  ['is_empty', 'is_not_empty', 'is_true', 'is_false'].includes(operator)

const toggleEnabled = (val) =>
  emit('update:modelValue', { ...conditions.value, enabled: val })

const addRule = () => {
  if (atFreeLimit.value) return
  emit('update:modelValue', {
    ...conditions.value,
    rules: [...conditions.value.rules, { field: '', operator: 'equals', value: '' }],
  })
}

const removeRule = (index) =>
  emit('update:modelValue', {
    ...conditions.value,
    rules: conditions.value.rules.filter((_, i) => i !== index),
  })

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
</script>

<template>
  <div class="space-y-3">
    <Label class="text-sm font-semibold">Conditions</Label>

    <div class="rounded-md border p-4 space-y-4">
      <!-- Enable toggle -->
      <div class="flex items-center gap-2">
        <Switch
          :model-value="conditions.enabled"
          @update:model-value="toggleEnabled"
        />
        <Label class="cursor-pointer select-none">Enable conditional dispatch</Label>
      </div>

      <template v-if="conditions.enabled">
        <!-- Rule rows -->
        <div
          v-for="(rule, index) in conditions.rules"
          :key="index"
          class="flex items-center gap-2"
        >
          <span class="text-xs text-muted-foreground w-6 shrink-0 font-mono">
            {{ ruleLabel(index) }}
          </span>

          <Input
            :model-value="rule.field"
            placeholder="data.field_name"
            class="flex-1 font-mono text-sm"
            @update:model-value="updateRule(index, 'field', $event)"
          />

          <Select
            :model-value="rule.operator"
            @update:model-value="updateRule(index, 'operator', $event)"
          >
            <SelectTrigger class="w-40 shrink-0">
              <SelectValue />
            </SelectTrigger>
            <SelectContent to="#fswa-app">
              <SelectItem
                v-for="op in OPERATORS"
                :key="op.value"
                :value="op.value"
              >
                {{ op.label }}
              </SelectItem>
            </SelectContent>
          </Select>

          <Input
            v-if="!valueHidden(rule.operator)"
            :model-value="rule.value"
            placeholder="value"
            class="flex-1 text-sm"
            @update:model-value="updateRule(index, 'value', $event)"
          />
          <div v-else class="flex-1" />

          <Button
            type="button"
            size="icon"
            variant="ghost"
            class="shrink-0"
            @click="removeRule(index)"
          >
            <X class="h-4 w-4" />
          </Button>
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
  </div>
</template>
