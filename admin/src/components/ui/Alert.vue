<script setup>
import { computed } from 'vue'
import { cn } from '@/lib/utils'
import { AlertCircle, CheckCircle2, Info, AlertTriangle } from 'lucide-vue-next'

const props = defineProps({
  variant: {
    type: String,
    default: 'default',
    validator: (v) => ['default', 'destructive', 'success', 'warning'].includes(v),
  },
  title: String,
  class: String,
})

const variantClasses = {
  default: 'bg-background text-foreground',
  destructive: 'border-destructive/50 text-destructive dark:border-destructive [&>svg]:text-destructive',
  success: 'border-green-500/50 text-green-600 [&>svg]:text-green-600',
  warning: 'border-yellow-500/50 text-yellow-600 [&>svg]:text-yellow-600',
}

const icons = {
  default: Info,
  destructive: AlertCircle,
  success: CheckCircle2,
  warning: AlertTriangle,
}

const Icon = computed(() => icons[props.variant])

const classes = computed(() => cn(
  'relative w-full rounded-lg border p-4 [&>svg~*]:pl-7 [&>svg+div]:translate-y-[-3px] [&>svg]:absolute [&>svg]:left-4 [&>svg]:top-4 [&>svg]:text-foreground',
  variantClasses[props.variant],
  props.class
))
</script>

<template>
  <div :class="classes" role="alert">
    <component :is="Icon" class="h-4 w-4" />
    <h5 v-if="title" class="mb-1 font-medium leading-none tracking-tight">
      {{ title }}
    </h5>
    <div class="text-sm [&_p]:leading-relaxed">
      <slot />
    </div>
  </div>
</template>
