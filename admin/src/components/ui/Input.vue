<script setup>
import { cn } from '@/lib/utils'

defineProps({
  modelValue: [String, Number],
  type: {
    type: String,
    default: 'text',
  },
  placeholder: String,
  disabled: Boolean,
  class: String,
})

const emit = defineEmits(['update:modelValue'])

const handleInput = (e) => {
  emit('update:modelValue', e.target.value)
}
</script>

<template>
  <input
    :type="type"
    :value="modelValue"
    :placeholder="placeholder"
    :disabled="disabled"
    :class="cn(
      'flex !h-10 !min-h-0 w-full rounded-md !border !border-input !bg-background !px-3 !py-2 !text-sm !leading-normal ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:!outline-none focus-visible:!ring-2 focus-visible:!ring-ring focus-visible:!ring-offset-2 focus:!outline-none focus:!shadow-none focus:!border-input disabled:cursor-not-allowed disabled:opacity-50',
      $props.class
    )"
    @input="handleInput"
  />
</template>
