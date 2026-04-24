<script setup>
defineProps({
  modelValue: { type: String, default: '{}' },
  readonly: { type: Boolean, default: false },
})

defineEmits(['update:modelValue'])
</script>

<template>
  <textarea
    :value="modelValue"
    :readonly="readonly"
    class="w-full min-h-[160px] max-h-[320px] rounded-md border border-border bg-muted/30 px-3 py-2 text-xs font-mono resize-y focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
    spellcheck="false"
    autocomplete="off"
    @input="$emit('update:modelValue', $event.target.value)"
  />
</template>
