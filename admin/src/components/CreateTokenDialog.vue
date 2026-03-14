<script setup>
import { ref } from 'vue'
import { Button, Dialog, Input, Label, Select, SelectTrigger, SelectValue, SelectContent, SelectItem, DateTimePicker, Switch } from '@/components/ui'

defineProps({
  open: Boolean,
})

const emit = defineEmits(['close', 'created'])

const name = ref('')
const scope = ref('read')
const hasExpiry = ref(false)
const expiresAt = ref('')
const submitting = ref(false)
const error = ref(null)

const scopeOptions = [
  { value: 'read', label: 'Read', description: 'View webhooks, logs, queue, health, triggers, schemas' },
  { value: 'operational', label: 'Operational', description: 'Read + toggle webhooks, retry/replay logs, execute queue jobs' },
  { value: 'full', label: 'Full', description: 'Operational + create/update/delete webhooks, schemas, queue jobs' },
]

const reset = () => {
  name.value = ''
  scope.value = 'read'
  hasExpiry.value = false
  expiresAt.value = null
  submitting.value = false
  error.value = null
}

const handleClose = () => {
  reset()
  emit('close')
}

const handleSubmit = async () => {
  if (!name.value.trim()) {
    error.value = 'Token name is required.'
    return
  }

  submitting.value = true
  error.value = null

  const data = {
    name: name.value.trim(),
    scope: scope.value,
    expires_at: hasExpiry.value && expiresAt.value ? expiresAt.value : undefined,
  }

  try {
    emit('created', data)
    reset()
  } catch (e) {
    error.value = e.message
    submitting.value = false
  }
}
</script>

<template>
  <Dialog
    :open="open"
    title="Create API Token"
    description="Generate a new API token for programmatic access."
    @close="handleClose"
  >
    <div class="space-y-4">
      <div v-if="error" class="text-sm text-destructive">{{ error }}</div>

      <div class="space-y-1.5">
        <Label for="token-name">Token name</Label>
        <Input
          id="token-name"
          v-model="name"
          placeholder="e.g. CI pipeline, n8n integration"
          @keyup.enter="handleSubmit"
        />
      </div>

      <div class="space-y-1.5">
        <Label>Scope</Label>
        <Select v-model="scope">
          <SelectTrigger>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem
              v-for="opt in scopeOptions"
              :key="opt.value"
              :value="opt.value"
            >
              <div>
                <div class="font-medium">{{ opt.label }}</div>
                <div class="text-xs text-muted-foreground">{{ opt.description }}</div>
              </div>
            </SelectItem>
          </SelectContent>
        </Select>
      </div>

      <div class="flex items-center gap-2">
        <Switch id="token-expiry-toggle" v-model="hasExpiry" />
        <Label for="token-expiry-toggle" class="cursor-pointer">Set expiration date</Label>
      </div>

      <div v-if="hasExpiry" class="space-y-1.5">
        <Label>Expires at</Label>
        <DateTimePicker v-model="expiresAt" />
      </div>
    </div>

    <template #footer>
      <Button variant="outline" @click="handleClose">Cancel</Button>
      <Button @click="handleSubmit" :disabled="submitting">
        {{ submitting ? 'Creating…' : 'Create token' }}
      </Button>
    </template>
  </Dialog>
</template>
