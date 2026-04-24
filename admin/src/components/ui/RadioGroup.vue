<script setup>
import { RadioGroupRoot } from 'radix-vue'

const props = defineProps({
  modelValue: String,
  disabled: Boolean,
  orientation: { type: String, default: 'horizontal' },
})

const emit = defineEmits(['update:modelValue'])
</script>

<template>
  <RadioGroupRoot
    :model-value="modelValue"
    :disabled="disabled"
    :orientation="orientation"
    class="grid grid-cols-1 sm:grid-cols-2 gap-3 space-y-2 sm:space-y-0"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <slot />
  </RadioGroupRoot>
</template>
