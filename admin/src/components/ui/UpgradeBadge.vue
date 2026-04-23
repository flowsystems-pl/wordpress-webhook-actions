<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { Sparkles } from 'lucide-vue-next'
import Dialog from './Dialog.vue'
import Button from './Button.vue'

const router = useRouter()
const open = ref(false)

const navigate = () => {
  open.value = false
  router.push('/pro')
}
</script>

<template>
  <button
    type="button"
    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-primary/15 text-primary border border-primary/30 hover:bg-primary/25 transition-colors cursor-pointer"
    @click="open = true"
  >
    <Sparkles class="h-3 w-3" />
    Upgrade
  </button>

  <Dialog
    :open="open"
    title="Head to the Pro tab?"
    description="Any unsaved changes on this form will be lost."
    @close="open = false"
  >
    <template #footer>
      <Button variant="outline" @click="open = false">Cancel</Button>
      <Button @click="navigate">Go to Pro</Button>
    </template>
  </Dialog>
</template>
