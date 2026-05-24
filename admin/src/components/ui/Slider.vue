<script setup>
import { SliderRoot, SliderTrack, SliderRange, SliderThumb } from 'radix-vue'

const props = defineProps({
  modelValue: { type: Number, required: true },
  min:      { type: Number, default: 0 },
  max:      { type: Number, default: 100 },
  step:     { type: Number, default: 1 },
  disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue'])
</script>

<template>
  <SliderRoot
    :model-value="[modelValue]"
    :min="min"
    :max="max"
    :step="step"
    :disabled="disabled"
    class="relative flex items-center select-none touch-none w-full h-5"
    @update:model-value="(v) => emit('update:modelValue', v[0])"
  >
    <SliderTrack class="relative grow rounded-full h-1.5 bg-muted overflow-hidden">
      <SliderRange class="absolute h-full bg-primary rounded-full" />
    </SliderTrack>
    <SliderThumb
      class="block w-4 h-4 rounded-full border-2 border-primary bg-background shadow-sm transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 cursor-grab active:cursor-grabbing"
    />
  </SliderRoot>
</template>
