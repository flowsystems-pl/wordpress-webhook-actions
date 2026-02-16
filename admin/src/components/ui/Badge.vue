<script setup>
import { computed } from 'vue'
import { cn } from '@/lib/utils'

const props = defineProps({
  variant: {
    type: String,
    default: 'default',
    validator: (v) => ['default', 'secondary', 'destructive', 'outline', 'success', 'warning'].includes(v),
  },
  class: String,
})

const variantClasses = {
  default: 'border-transparent bg-[#00d4ff] text-white hover:bg-[#00d4ff]/80',
  secondary: 'border-transparent bg-secondary text-secondary-foreground hover:bg-secondary/80',
  destructive: 'border-transparent bg-destructive text-destructive-foreground hover:bg-destructive/80',
  outline: 'text-foreground',
  success: 'border-transparent bg-green-500 text-white',
  warning: 'border-transparent bg-yellow-500 text-white',
}

const classes = computed(() => cn(
  'inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold transition-colors focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2',
  variantClasses[props.variant],
  props.class
))
</script>

<template>
  <div :class="classes">
    <slot />
  </div>
</template>
