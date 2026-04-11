<script setup>
import { RadioGroupItem, RadioGroupIndicator } from 'radix-vue'

defineProps({
  value: { type: String, required: true },
  disabled: Boolean,
  id: String,
})
</script>

<template>
  <RadioGroupItem
    :value="value"
    :disabled="disabled"
    :id="id"
    class="aspect-square h-4 w-4 rounded-full border border-primary text-primary ring-offset-background focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
  >
    <RadioGroupIndicator class="flex items-center justify-center">
      <span class="h-2 w-2 rounded-full bg-current block" />
    </RadioGroupIndicator>
  </RadioGroupItem>
</template>
