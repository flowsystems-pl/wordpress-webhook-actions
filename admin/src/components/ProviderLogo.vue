<script setup>
/**
 * Brand mark for an AI provider, keyed by the provider id returned from the API
 * ('anthropic' | 'openai' | 'google'). Falls back to a generic chip when the id
 * is unknown. Uses `currentColor` so it inherits text colour where sensible.
 */
defineProps({
  provider: { type: String, default: '' },
  size: { type: [Number, String], default: 20 },
});
</script>

<template>
  <span class="inline-flex shrink-0 items-center justify-center" :style="{ width: size + 'px', height: size + 'px' }">
    <!-- Anthropic -->
    <svg v-if="provider === 'anthropic'" :width="size" :height="size" viewBox="0 0 46 32" fill="none" aria-hidden="true">
      <path
        d="M32.73 0h-6.07l11.06 32h6.07L32.73 0ZM13.27 0 0 32h6.19l2.72-7.05h13.74L25.37 32h6.19L18.29 0h-5.02Zm-2.31 19.7 4.5-11.65 4.5 11.65h-9Z"
        fill="#D97757" />
    </svg>

    <!-- OpenAI -->
    <svg v-else-if="provider === 'openai'" :width="size" :height="size" viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <path
        d="M22.28 9.82a5.99 5.99 0 0 0-.52-4.91 6.05 6.05 0 0 0-6.51-2.9A6 6 0 0 0 4.98 4.18a5.99 5.99 0 0 0-4 2.9 6.05 6.05 0 0 0 .74 7.1 5.98 5.98 0 0 0 .51 4.91 6.05 6.05 0 0 0 6.52 2.9A6 6 0 0 0 19.02 19.8a5.99 5.99 0 0 0 4-2.9 6.06 6.06 0 0 0-.74-7.09Zm-9.02 12.6a4.48 4.48 0 0 1-2.88-1.04l.14-.08 4.78-2.76a.78.78 0 0 0 .39-.68v-6.74l2.02 1.17a.07.07 0 0 1 .04.05v5.58a4.5 4.5 0 0 1-4.5 4.5ZM3.6 18.1a4.47 4.47 0 0 1-.54-3.01l.14.08 4.78 2.76c.24.14.53.14.77 0l5.84-3.37v2.33a.08.08 0 0 1-.03.06l-4.83 2.79a4.5 4.5 0 0 1-6.14-1.64ZM2.34 7.9a4.49 4.49 0 0 1 2.34-1.97V11.6c0 .28.15.54.39.68l5.83 3.37-2.02 1.16a.08.08 0 0 1-.07.01L3.99 14.04A4.5 4.5 0 0 1 2.34 7.9Zm16.6 3.86-5.84-3.37 2.02-1.16a.08.08 0 0 1 .07-.01l4.83 2.79a4.5 4.5 0 0 1-.69 8.11v-5.67a.79.79 0 0 0-.39-.68Zm2.01-3.02-.14-.09-4.77-2.78a.78.78 0 0 0-.78 0L9.43 9.24V6.9a.07.07 0 0 1 .03-.06l4.83-2.79a4.5 4.5 0 0 1 6.68 4.66ZM8.33 12.9l-2.02-1.17a.08.08 0 0 1-.04-.05V6.1a4.5 4.5 0 0 1 7.38-3.45l-.14.08L8.72 5.5a.78.78 0 0 0-.39.68v6.73Zm1.1-2.36L12 9.06l2.6 1.5v3l-2.6 1.5-2.6-1.5v-3Z"
        fill="currentColor" />
    </svg>

    <!-- Google -->
    <svg v-else-if="provider === 'google'" :width="size" :height="size" viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1Z" fill="#4285F4" />
      <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84A11 11 0 0 0 12 23Z" fill="#34A853" />
      <path d="M5.84 14.1a6.6 6.6 0 0 1 0-4.2V7.06H2.18a11 11 0 0 0 0 9.88l3.66-2.84Z" fill="#FBBC05" />
      <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15A10.6 10.6 0 0 0 12 1 11 11 0 0 0 2.18 7.06l3.66 2.84C6.71 7.3 9.14 5.38 12 5.38Z" fill="#EA4335" />
    </svg>

    <!-- Fallback -->
    <span v-else class="text-xs font-semibold text-muted-foreground uppercase">{{ (provider || '?').slice(0, 1) }}</span>
  </span>
</template>
