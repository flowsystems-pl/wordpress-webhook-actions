<script setup>
import { cn } from '@/lib/utils'

defineProps({
  modelValue: [String, Number],
  options: {
    type: Array,
    required: true,
  },
  placeholder: String,
  disabled: Boolean,
  class: String,
})

const emit = defineEmits(['update:modelValue'])

const handleChange = (e) => {
  emit('update:modelValue', e.target.value)
}
</script>

<template>
  <select
    :value="modelValue"
    :disabled="disabled"
    :class="cn(
      'flex !h-10 !min-h-0 w-full items-center justify-between rounded-md !border !border-input !bg-background !px-3 !py-2 !text-sm !leading-normal ring-offset-background placeholder:text-muted-foreground focus:!outline-none focus:!ring-2 focus:!ring-ring focus:!ring-offset-2 focus:!shadow-none focus:!border-input disabled:cursor-not-allowed disabled:opacity-50',
      $props.class
    )"
    @change="handleChange"
  >
    <option v-if="placeholder" value="" disabled>
      {{ placeholder }}
    </option>
    <option
      v-for="option in options"
      :key="option.value"
      :value="option.value"
    >
      {{ option.label }}
    </option>
  </select>
</template>
