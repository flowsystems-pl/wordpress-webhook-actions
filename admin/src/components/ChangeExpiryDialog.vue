<script setup>
import { ref, watch } from 'vue'
import { Button, Dialog, Label, DateTimePicker, Switch } from '@/components/ui'
import { utcDbToPickerLocal, pickerLocalToUtcDb } from '@/lib/dates'

const props = defineProps({
  open: Boolean,
  token: Object, // { id, name, expires_at }
})

const emit = defineEmits(['close', 'updated'])

const hasExpiry = ref(false)
const expiresAt = ref(null)
const submitting = ref(false)
const error = ref(null)

watch(() => props.token, (token) => {
  if (token) {
    hasExpiry.value = !!token.expires_at
    expiresAt.value = utcDbToPickerLocal(token.expires_at)
  }
}, { immediate: true })

const handleClose = () => {
  error.value = null
  emit('close')
}

const handleSubmit = () => {
  submitting.value = true
  error.value = null
  emit('updated', {
    token: props.token,
    expiresAt: hasExpiry.value && expiresAt.value ? pickerLocalToUtcDb(expiresAt.value) : null,
  })
}
</script>

<template>
  <Dialog
    :open="open"
    :title="`Change expiry: ${token?.name}`"
    description="Update when this token expires. Removing the expiry makes it valid indefinitely."
    @close="handleClose"
  >
    <div class="space-y-4">
      <div v-if="error" class="text-sm text-destructive">{{ error }}</div>

      <div class="flex items-center gap-2">
        <Switch id="change-expiry-toggle" v-model="hasExpiry" />
        <Label for="change-expiry-toggle" class="cursor-pointer">Set expiration date</Label>
      </div>

      <div v-if="hasExpiry" class="space-y-1.5">
        <Label>Expires at</Label>
        <DateTimePicker v-model="expiresAt" />
      </div>

      <p v-if="!hasExpiry" class="text-sm text-muted-foreground">
        Token will never expire.
      </p>
    </div>

    <template #footer>
      <Button variant="outline" @click="handleClose" :disabled="submitting">Cancel</Button>
      <Button @click="handleSubmit" :disabled="submitting">
        {{ submitting ? 'Saving…' : 'Save' }}
      </Button>
    </template>
  </Dialog>
</template>
