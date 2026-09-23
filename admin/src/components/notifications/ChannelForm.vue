<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { Eye, EyeOff } from 'lucide-vue-next'
import { Button, Dialog, Input, Label, Switch, Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/components/ui'
import api from '@/lib/api'
import { useNotifications } from '@/composables/useNotifications'
import { __, sprintf } from '@/i18n'

// Create / edit dialog for one channel. The form is generated from the
// driver's field schema, so a new driver needs no UI work. Secret fields are
// write-only: on edit they start empty and are only sent when typed.
const props = defineProps({
  open: Boolean,
  channel: { type: Object, default: null },
})
const emit = defineEmits(['close', 'saved'])

const { channelTypes, typeByKey, loadCatalog, refreshChannels } = useNotifications()

const form = reactive({ name: '', type: 'email', is_enabled: true, config: {} })
const reveal = reactive({})
const credentials = ref([])
const submitting = ref(false)
const error = ref(null)
const fieldError = ref(null)

const isEditing = computed(() => !!props.channel)
const fields = computed(() => typeByKey.value[form.type]?.fields || [])
const description = computed(() => typeByKey.value[form.type]?.description || '')

const applyDefaults = (type, keep = {}) => {
  const next = {}
  for (const f of typeByKey.value[type]?.fields || []) {
    if (f.type === 'secret') {
      next[f.key] = ''
      continue
    }
    next[f.key] = keep[f.key] !== undefined ? keep[f.key] : (f.default !== undefined ? f.default : (f.type === 'toggle' ? false : ''))
  }
  form.config = next
}

const reset = async () => {
  await loadCatalog()
  error.value = null
  fieldError.value = null
  submitting.value = false
  Object.keys(reveal).forEach((k) => delete reveal[k])
  if (props.channel) {
    form.name = props.channel.name
    form.type = props.channel.type
    form.is_enabled = props.channel.is_enabled
    applyDefaults(form.type, props.channel.config || {})
  } else {
    form.name = ''
    form.type = channelTypes.value[0]?.type || 'email'
    form.is_enabled = true
    applyDefaults(form.type)
  }
  if (fields.value.some((f) => f.type === 'credential') && credentials.value.length === 0) {
    try { credentials.value = await api.credentials.list() } catch (e) { credentials.value = [] }
  }
}

watch(() => props.open, (open) => { if (open) reset() })
watch(() => form.type, async (type, prev) => {
  if (!props.open || type === prev) return
  applyDefaults(type, isEditing.value && props.channel?.type === type ? props.channel.config : {})
  if (fields.value.some((f) => f.type === 'credential') && credentials.value.length === 0) {
    try { credentials.value = await api.credentials.list() } catch (e) { credentials.value = [] }
  }
})

// radix Select needs string values.
const selectProxy = (key) => computed({
  get: () => (form.config[key] === null || form.config[key] === undefined ? '' : String(form.config[key])),
  set: (v) => { form.config[key] = v },
})
const proxies = {}
const proxyFor = (key) => {
  if (!proxies[key]) proxies[key] = selectProxy(key)
  return proxies[key]
}
const credentialProxy = computed({
  get: () => (form.config.auth_credential_id ? String(form.config.auth_credential_id) : '0'),
  set: (v) => { form.config.auth_credential_id = v && v !== '0' ? Number(v) : null },
})

const submit = async () => {
  error.value = null
  fieldError.value = null
  if (!form.name.trim()) {
    error.value = __('Name is required.')
    return
  }
  const config = {}
  for (const f of fields.value) {
    const v = form.config[f.key]
    if (f.type === 'secret' && (v === '' || v === undefined)) continue // keep stored secret
    config[f.key] = v
  }
  submitting.value = true
  try {
    const payload = { name: form.name.trim(), type: form.type, is_enabled: form.is_enabled, config }
    const saved = isEditing.value
      ? await api.notifications.channels.update(props.channel.id, payload)
      : await api.notifications.channels.create(payload)
    await refreshChannels()
    emit('saved', saved)
    emit('close')
  } catch (e) {
    error.value = e.message
    fieldError.value = e.data?.data?.field || null
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <Dialog
    :open="open"
    :title="isEditing ? __('Edit channel') : __('New channel')"
    :description="__('A channel is a destination rules send to. Secrets are encrypted and never shown again after saving.')"
    @close="emit('close')"
  >
    <div class="space-y-4">
      <div v-if="error" class="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">{{ error }}</div>

      <div class="space-y-2">
        <Label for="channel-name">{{ __('Name') }}</Label>
        <Input id="channel-name" v-model="form.name" :placeholder="__('Ops Slack, On-call SMS…')" />
      </div>

      <div class="space-y-2">
        <Label>{{ __('Type') }}</Label>
        <Select v-model="form.type">
          <SelectTrigger><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem v-for="t in channelTypes" :key="t.type" :value="t.type">{{ t.label }}</SelectItem>
          </SelectContent>
        </Select>
        <p v-if="description" class="text-xs text-muted-foreground">{{ description }}</p>
      </div>

      <div v-for="f in fields" :key="f.key" class="space-y-1.5" :class="fieldError === f.key ? 'rounded-md ring-1 ring-destructive p-2 -m-2' : ''">
        <template v-if="f.type === 'toggle'">
          <div class="flex items-center gap-2">
            <Switch v-model="form.config[f.key]" />
            <Label>{{ f.label }}</Label>
          </div>
        </template>

        <template v-else>
          <Label :for="`cf-${f.key}`">
            {{ f.label }}<span v-if="f.required" class="text-destructive"> *</span>
          </Label>

          <div v-if="f.type === 'secret'" class="relative">
            <Input
              :id="`cf-${f.key}`"
              v-model="form.config[f.key]"
              :type="reveal[f.key] ? 'text' : 'password'"
              autocomplete="new-password"
              spellcheck="false"
              class="pr-10 font-mono"
              :placeholder="isEditing && channel?.has_secret ? __('Leave empty to keep the saved value') : (f.placeholder || '')"
            />
            <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground" @click="reveal[f.key] = !reveal[f.key]">
              <EyeOff v-if="reveal[f.key]" class="h-4 w-4" /><Eye v-else class="h-4 w-4" />
            </button>
          </div>

          <textarea
            v-else-if="f.type === 'textarea'"
            :id="`cf-${f.key}`"
            v-model="form.config[f.key]"
            rows="3"
            spellcheck="false"
            class="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-mono ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            :placeholder="f.placeholder || ''"
          />

          <Select v-else-if="f.type === 'select'" v-model="proxyFor(f.key).value">
            <SelectTrigger><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem v-for="o in f.options" :key="o.value" :value="String(o.value)">{{ o.label }}</SelectItem>
            </SelectContent>
          </Select>

          <Select v-else-if="f.type === 'credential'" v-model="credentialProxy">
            <SelectTrigger><SelectValue :placeholder="__('None')" /></SelectTrigger>
            <SelectContent>
              <SelectItem value="0">{{ __('None') }}</SelectItem>
              <SelectItem v-for="c in credentials" :key="c.id" :value="String(c.id)">{{ c.name }} ({{ c.type }})</SelectItem>
            </SelectContent>
          </Select>

          <Input
            v-else
            :id="`cf-${f.key}`"
            v-model="form.config[f.key]"
            :type="f.type === 'number' ? 'number' : 'text'"
            :placeholder="f.placeholder || ''"
            spellcheck="false"
          />
          <p v-if="f.help" class="text-xs text-muted-foreground">{{ f.help }}</p>
        </template>
      </div>

      <div class="flex items-center gap-2 border-t pt-4">
        <Switch v-model="form.is_enabled" />
        <Label>{{ __('Enabled') }}</Label>
      </div>
    </div>

    <template #footer>
      <Button variant="outline" @click="emit('close')">{{ __('Cancel') }}</Button>
      <Button :disabled="submitting" @click="submit">{{ submitting ? __('Saving…') : (isEditing ? __('Save changes') : __('Create channel')) }}</Button>
    </template>
  </Dialog>
</template>
