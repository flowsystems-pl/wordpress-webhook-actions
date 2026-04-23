<script setup>
import {
  TooltipProvider,
  TooltipRoot,
  TooltipTrigger,
  TooltipContent,
  TooltipPortal,
} from 'radix-vue'

const props = defineProps({
  content: { type: String, required: true },
  side: { type: String, default: 'top' },
  delayDuration: { type: Number, default: 300 },
  sideOffset: { type: Number, default: 4 },
  variant: { type: String, default: 'default' }, // 'default' | 'destructive'
})
</script>

<template>
  <TooltipProvider :delay-duration="delayDuration">
    <TooltipRoot>
      <TooltipTrigger as-child>
        <slot />
      </TooltipTrigger>
      <TooltipPortal to="#fswa-app">
        <TooltipContent
          :side="side"
          :side-offset="sideOffset"
          :class="[
            'z-50 overflow-hidden rounded-md px-3 py-1.5 text-xs animate-in fade-in-0 zoom-in-95 data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=closed]:zoom-out-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2',
            props.variant === 'destructive'
              ? 'bg-destructive text-destructive-foreground'
              : 'bg-primary text-primary-foreground',
          ]"
        >
          {{ content }}
        </TooltipContent>
      </TooltipPortal>
    </TooltipRoot>
  </TooltipProvider>
</template>
