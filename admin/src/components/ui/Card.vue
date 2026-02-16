<script setup>
import { cn } from '@/lib/utils'

defineProps({
  class: String,
})
</script>

<template>
  <div
    :class="cn(
      'rounded-lg border bg-card text-card-foreground shadow-sm',
      $props.class
    )"
  >
    <slot />
  </div>
</template>
