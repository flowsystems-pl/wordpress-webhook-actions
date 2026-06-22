<script setup>
import { ref, computed, watch } from 'vue'
import { Button, Dialog, Label, DateTimePicker, Switch } from '@/components/ui'
import { isUtcExpired, utcDbToPickerLocal, pickerLocalToUtcDb } from '@/lib/dates'
import { __, sprintf } from '@/i18n'

const props = defineProps({
  open: Boolean,
  token: Object, // { id, name, expires_at }
})

const emit = defineEmits(['close', 'rotate'])

const rotating = ref(false)
const changeExpiry = ref(false)
const hasExpiry = ref(false)
const expiresAt = ref(null)

const isExpired = computed(() => isUtcExpired(props.token?.expires_at))

const defaultExtendedExpiry = () => {
  const d = new Date()
  d.setDate(d.getDate() + 30)
  d.setSeconds(0, 0)
  // Return local picker format "YYYY-MM-DDTHH:mm"
  const pad = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

watch(() => props.token, (token) => {
  if (!token) return
  if (isExpired.value) {
    changeExpiry.value = true
    hasExpiry.value = true
    expiresAt.value = defaultExtendedExpiry()
  } else {
    changeExpiry.value = false
    hasExpiry.value = !!token.expires_at
    expiresAt.value = utcDbToPickerLocal(token.expires_at)
  }
}, { immediate: true })

const handleRotate = () => {
  rotating.value = true
  const payload = {}
  if (changeExpiry.value) {
    payload.expires_at = hasExpiry.value && expiresAt.value ? pickerLocalToUtcDb(expiresAt.value) : null
  }
  emit('rotate', props.token, payload)
}

const handleClose = () => {
  rotating.value = false
  emit('close')
}
</script>

<template>
  <Dialog
    :open="open"
    :title="isExpired ? sprintf(__('Revive token: %s'), token?.name) : sprintf(__('Rotate token: %s'), token?.name)"
    @close="handleClose"
  >
    <div class="space-y-4">
      <template v-if="isExpired">
        <p class="text-sm text-foreground" v-html="sprintf(__('This token is %1$sexpired%2$s. Rotating will issue a new secret — but you must also extend the expiry, otherwise the token will remain invalid immediately after rotation.'), '<strong>', '</strong>')">
        </p>
      </template>
      <template v-else>
        <p class="text-sm text-foreground" v-html="sprintf(__('A new token will be generated. The current token will be %1$simmediately invalidated%2$s and all existing integrations using it will stop working.'), '<strong>', '</strong>')">
        </p>
        <p class="text-sm text-muted-foreground">
          {{ __('Make sure to update all systems that use this token before rotating.') }}
        </p>
      </template>

      <div class="border-t border-border pt-4 space-y-3">
        <!-- Routine rotation: outer opt-in toggle -->
        <div v-if="!isExpired" class="flex items-center gap-2">
          <Switch id="rotate-change-expiry" v-model="changeExpiry" />
          <Label for="rotate-change-expiry" class="cursor-pointer">{{ __('Also update expiry') }}</Label>
        </div>

        <template v-if="changeExpiry">
          <div class="flex items-center gap-2">
            <Switch id="rotate-has-expiry" v-model="hasExpiry" />
            <Label for="rotate-has-expiry" class="cursor-pointer">{{ __('Set expiration date') }}</Label>
          </div>
          <div v-if="hasExpiry" class="space-y-1.5">
            <Label>{{ __('Expires at') }}</Label>
            <DateTimePicker v-model="expiresAt" />
          </div>
          <p v-else class="text-sm text-muted-foreground">{{ __('Token will never expire.') }}</p>
        </template>
      </div>
    </div>

    <template #footer>
      <Button variant="outline" @click="handleClose" :disabled="rotating">{{ __('Cancel') }}</Button>
      <Button variant="destructive" @click="handleRotate" :disabled="rotating">
        {{ rotating ? (isExpired ? __('Reviving…') : __('Rotating…')) : (isExpired ? __('Revive token') : __('Rotate token')) }}
      </Button>
    </template>
  </Dialog>
</template>
