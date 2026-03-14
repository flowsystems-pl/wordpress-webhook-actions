<script setup>
import { ref } from 'vue'
import { Copy, Check } from 'lucide-vue-next'
import { Button, Dialog } from '@/components/ui'

const props = defineProps({
  open: Boolean,
  token: String,
})

const emit = defineEmits(['close'])

const copied = ref(false)

const copyToken = async () => {
  try {
    await navigator.clipboard.writeText(props.token)
    copied.value = true
  } catch {
    // Fallback for older browsers
    const el = document.createElement('textarea')
    el.value = props.token
    document.body.appendChild(el)
    el.select()
    document.execCommand('copy')
    document.body.removeChild(el)
    copied.value = true
  }
}

const handleClose = () => {
  copied.value = false
  emit('close')
}
</script>

<template>
  <Dialog
    :open="open"
    title="Token created"
    @close="handleClose"
  >
    <div class="space-y-4">
      <div class="rounded-md border border-yellow-500/30 bg-yellow-500/10 p-3 text-sm text-yellow-700 dark:text-yellow-400">
        This token will only be shown once. Copy it now — you won't be able to see it again.
      </div>

      <div class="space-y-1.5">
        <div class="flex items-center gap-2 rounded-md border border-border bg-muted p-3 font-mono text-sm break-all">
          <span class="flex-1 select-all">{{ token }}</span>
          <button
            @click="copyToken"
            class="shrink-0 rounded p-1 hover:bg-background transition-colors"
            title="Copy to clipboard"
          >
            <Check v-if="copied" class="h-4 w-4 text-green-500" />
            <Copy v-else class="h-4 w-4 text-muted-foreground" />
          </button>
        </div>
      </div>

      <p class="text-xs text-muted-foreground">
        Use <code class="font-mono bg-muted px-1 rounded">X-FSWA-Token: {{ token }}</code> in your requests.
      </p>

      <p class="text-xs text-muted-foreground">
        Alternatively, <code class="font-mono bg-muted px-1 rounded">Authorization: Bearer &lt;token&gt;</code>
        works on most servers (requires <code class="font-mono bg-muted px-1 rounded">CGIPassAuth On</code> on Apache).
      </p>
    </div>

    <template #footer>
      <div class="flex flex-col gap-2 w-full">
        <div class="flex justify-between gap-4">
          <Button
            v-if="!copied"
            variant="outline"
            @click="copyToken"
          >
            <Copy class="mr-2 h-4 w-4" />
            Copy to clipboard
          </Button>
          <Button @click="handleClose" :disabled="!copied">
            Done
          </Button>
        </div>
        <div v-if="!copied" class="flex justify-center mt-4">
          <button
            class="text-xs text-muted-foreground hover:text-foreground underline underline-offset-2 transition-colors"
            @click="handleClose"
          >
            I've saved it elsewhere
          </button>
        </div>
      </div>
    </template>
  </Dialog>
</template>
