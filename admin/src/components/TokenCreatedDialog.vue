<script setup>
import { ref } from 'vue'
import { Copy, Check } from 'lucide-vue-next'
import { Button, Dialog } from '@/components/ui'
import { useCopyToClipboard } from '@/composables/useCopyToClipboard'
import { __, sprintf } from '@/i18n'

const props = defineProps({
  open: Boolean,
  token: String,
})

const emit = defineEmits(['close'])

const { copiedKey, copy } = useCopyToClipboard()
const hasCopied = ref(false)

const copyToken = () => {
  copy(props.token, 'token')
  hasCopied.value = true
}

const handleClose = () => {
  copiedKey.value = null
  hasCopied.value = false
  emit('close')
}
</script>

<template>
  <Dialog
    :open="open"
    :title="__('Token created')"
    @close="handleClose"
  >
    <div class="space-y-4">
      <div class="rounded-md border border-yellow-500/30 bg-yellow-500/10 p-3 text-sm text-yellow-700 dark:text-yellow-400">
        {{ __('This token will only be shown once. Copy it now — you won\'t be able to see it again.') }}
      </div>

      <div class="space-y-1.5">
        <div class="flex items-center gap-2 rounded-md border border-border bg-muted p-3 font-mono text-sm break-all">
          <span class="flex-1 select-all">{{ token }}</span>
          <button
            @click="copyToken"
            class="shrink-0 rounded p-1 hover:bg-background transition-colors"
            :title="__('Copy to clipboard')"
          >
            <Check v-if="copiedKey === 'token'" class="h-4 w-4 text-green-500" />
            <Copy v-else class="h-4 w-4 text-muted-foreground" />
          </button>
        </div>
      </div>

      <p class="text-xs text-muted-foreground" v-html="sprintf(__('Use %1$sX-FSWA-Token: %2$s%3$s in your requests.'), '<code class=&quot;font-mono bg-muted px-1 rounded&quot;>', token, '</code>')">
      </p>

      <p class="text-xs text-muted-foreground" v-html="sprintf(__('Alternatively, %1$sAuthorization: Bearer &lt;token&gt;%2$s works on most servers (requires %1$sCGIPassAuth On%2$s on Apache).'), '<code class=&quot;font-mono bg-muted px-1 rounded&quot;>', '</code>')">
      </p>
    </div>

    <template #footer>
      <div class="flex flex-col gap-2 w-full">
        <div class="flex justify-between gap-4">
          <Button
            v-if="!hasCopied"
            variant="outline"
            @click="copyToken"
          >
            <Copy class="mr-2 h-4 w-4" />
            {{ __('Copy to clipboard') }}
          </Button>
          <Button @click="handleClose" :disabled="!hasCopied">
            {{ __('Done') }}
          </Button>
        </div>
        <div v-if="!hasCopied" class="flex justify-center mt-4">
          <button
            class="text-xs text-muted-foreground hover:text-foreground underline underline-offset-2 transition-colors"
            @click="handleClose"
          >
            {{ __('I\'ve saved it elsewhere') }}
          </button>
        </div>
      </div>
    </template>
  </Dialog>
</template>
