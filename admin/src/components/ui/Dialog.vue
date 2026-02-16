<script setup>
import { X } from 'lucide-vue-next'
import { cn } from '@/lib/utils'

defineProps({
  open: Boolean,
  title: String,
  description: String,
})

const emit = defineEmits(['close'])

const close = () => {
  emit('close')
}

const handleOverlayClick = (e) => {
  if (e.target === e.currentTarget) {
    close()
  }
}
</script>

<template>
  <Teleport to="#fswa-app">
    <div
      v-if="open"
      class="fixed inset-0 z-[100000] flex items-center justify-center"
    >
      <!-- Overlay -->
      <div
        class="fixed inset-0 bg-black/80"
        @click="handleOverlayClick"
      />

      <!-- Content -->
      <div
        class="fixed z-[100001] grid w-full max-w-lg gap-4 border border-border bg-background p-6 shadow-lg rounded-lg"
      >
        <!-- Header -->
        <div class="flex flex-col space-y-1.5 text-center sm:text-left">
          <h2 v-if="title" class="text-lg font-semibold leading-none tracking-tight">
            {{ title }}
          </h2>
          <p v-if="description" class="text-sm text-muted-foreground">
            {{ description }}
          </p>
        </div>

        <!-- Body -->
        <slot />

        <!-- Footer -->
        <div v-if="$slots.footer" class="flex flex-col-reverse sm:flex-row sm:justify-end sm:space-x-2">
          <slot name="footer" />
        </div>

        <!-- Close button -->
        <button
          class="absolute right-4 top-4 rounded-sm opacity-70 ring-offset-background transition-opacity hover:opacity-100 focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
          @click="close"
        >
          <X class="h-4 w-4" />
          <span class="sr-only">Close</span>
        </button>
      </div>
    </div>
  </Teleport>
</template>
