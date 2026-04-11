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
    class="flex gap-3"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <slot />
  </RadioGroupRoot>
</template>
