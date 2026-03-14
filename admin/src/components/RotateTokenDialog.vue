<script setup>
import { ref } from 'vue'
import { Button, Dialog } from '@/components/ui'

const props = defineProps({
  open: Boolean,
  token: Object, // { id, name }
})

const emit = defineEmits(['close', 'rotate'])

const rotating = ref(false)

const handleRotate = () => {
  rotating.value = true
  emit('rotate', props.token)
}

const handleClose = () => {
  rotating.value = false
  emit('close')
}
</script>

<template>
  <Dialog
    :open="open"
    :title="`Rotate token: ${token?.name}`"
    @close="handleClose"
  >
    <div class="space-y-3">
      <p class="text-sm text-foreground">
        A new token will be generated. The current token will be <strong>immediately invalidated</strong> and all existing
        integrations using it will stop working.
      </p>
      <p class="text-sm text-muted-foreground">
        Make sure to update all systems that use this token before rotating.
      </p>
    </div>

    <template #footer>
      <Button variant="outline" @click="handleClose" :disabled="rotating">Cancel</Button>
      <Button variant="destructive" @click="handleRotate" :disabled="rotating">
        {{ rotating ? 'Rotating…' : 'Rotate token' }}
      </Button>
    </template>
  </Dialog>
</template>
