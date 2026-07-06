<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { Sparkles } from 'lucide-vue-next'
import Dialog from './Dialog.vue'
import Button from './Button.vue'
import { __ } from '@/i18n'

// Multi-root template (button + Dialog): attrs like `class` can't auto-inherit,
// so forward them to the button explicitly.
defineOptions({ inheritAttrs: false })

const router = useRouter()
const open = ref(false)

const navigate = () => {
  open.value = false
  router.push('/pro')
}
</script>

<template>
  <button
    v-bind="$attrs"
    type="button"
    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-primary/15 text-primary border border-primary/30 hover:bg-primary/25 transition-colors cursor-pointer"
    @click="open = true"
  >
    <Sparkles class="h-3 w-3" />
    {{ __('Upgrade') }}
  </button>

  <Dialog
    :open="open"
    :title="__('Head to the Pro tab?')"
    :description="__('Any unsaved changes on this form will be lost.')"
    @close="open = false"
  >
    <template #footer>
      <Button variant="outline" @click="open = false">{{ __('Cancel') }}</Button>
      <Button @click="navigate">{{ __('Go to Pro') }}</Button>
    </template>
  </Dialog>
</template>
