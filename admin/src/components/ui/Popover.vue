<script setup>
import {
  PopoverRoot,
  PopoverTrigger,
  PopoverPortal,
  PopoverContent,
} from 'radix-vue'
import { cn } from '@/lib/utils'

const props = defineProps({
  open: {
    type: Boolean,
    default: undefined,
  },
  defaultOpen: Boolean,
  align: {
    type: String,
    default: 'start',
  },
  sideOffset: {
    type: Number,
    default: 4,
  },
  contentClass: String,
})

const emit = defineEmits(['update:open'])
</script>

<template>
  <PopoverRoot
    :open="open"
    :default-open="defaultOpen"
    @update:open="emit('update:open', $event)"
  >
    <PopoverTrigger as-child>
      <slot name="trigger" />
    </PopoverTrigger>
    <PopoverPortal to="#fswa-app">
      <PopoverContent
        :align="align"
        :side-offset="sideOffset"
        :class="cn(
          'z-50 w-auto rounded-md border bg-popover text-popover-foreground shadow-md outline-none',
          'data-[state=open]:animate-in data-[state=closed]:animate-out',
          'data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0',
          'data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95',
          'data-[side=bottom]:slide-in-from-top-2 data-[side=top]:slide-in-from-bottom-2',
          contentClass
        )"
      >
        <slot />
      </PopoverContent>
    </PopoverPortal>
  </PopoverRoot>
</template>
