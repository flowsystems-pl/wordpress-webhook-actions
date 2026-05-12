<script setup>
import { ref, computed, watch } from 'vue'
import { X, FlaskConical, CheckCircle2, XCircle, ExternalLink, AlertTriangle, Clock, Code2 } from 'lucide-vue-next'
import { Button, Label, Alert, Badge, UpgradeBadge, Select, SelectTrigger, SelectValue, SelectContent, SelectItem, RadioGroup, RadioGroupItem } from '@/components/ui'
import JsonEditor from '@/components/JsonEditor.vue'
import api from '@/lib/api'
import { usePro } from '@/composables/usePro'
import { useRouter } from 'vue-router'
import { formatUtcDate } from '@/lib/dates'

const props = defineProps({
  open: Boolean,
  webhook: { type: Object, default: null },
  hasUnsavedChanges: { type: Boolean, default: false },
})

const emit = defineEmits(['close'])

const router = useRouter()
const { proActive } = usePro()

const source = ref('captured')
const selectedTrigger = ref('')
const customPayload = ref('{\n  \n}')
const sendingNow = ref(false)
const sendingQueue = ref(false)
const result = ref(null)
const sendError = ref(null)

// Glue assignment for selected trigger
const glueAssignment = ref(null)
const glueAssignmentSources = ['pre_glue', 'full_glue']

const hasPreGlue = computed(() =>
  proActive.value && !!(glueAssignment.value?.pre_enabled && glueAssignment.value?.pre_snippet_id)
)
const hasPostGlue = computed(() =>
  proActive.value && !!(glueAssignment.value?.post_enabled && glueAssignment.value?.post_snippet_id)
)

const fetchGlueAssignment = async () => {
  if (!proActive.value || !props.webhook?.id || !selectedTrigger.value) {
    glueAssignment.value = null
    return
  }
  try {
    glueAssignment.value = await api.snippets.getTriggerSnippet(props.webhook.id, selectedTrigger.value)
  } catch {
    glueAssignment.value = null
  }
}

const triggers = computed(() => props.webhook?.triggers ?? [])
const singleTrigger = computed(() => triggers.value.length === 1)

watch(() => props.open, (open) => {
  if (open) {
    source.value = 'mapped'
    selectedTrigger.value = triggers.value[0] ?? ''
    customPayload.value = '{\n  \n}'
    result.value = null
    sendError.value = null
    fetchGlueAssignment()
  }
})

watch(selectedTrigger, () => {
  // Reset glue sources if trigger changes
  if (glueAssignmentSources.includes(source.value)) source.value = 'mapped'
  fetchGlueAssignment()
})

watch(triggers, (list) => {
  if (list.length && !selectedTrigger.value) selectedTrigger.value = list[0]
}, { immediate: true })

const isValidJson = computed(() => {
  if (source.value !== 'custom') return true
  try { JSON.parse(customPayload.value); return true } catch { return false }
})

const canSend = computed(() => {
  if (!selectedTrigger.value) return false
  if (source.value === 'custom' && !isValidJson.value) return false
  return true
})

const buildBody = () => {
  const body = { payload_source: source.value, trigger: selectedTrigger.value }
  if (source.value === 'custom') body.payload = JSON.parse(customPayload.value)
  return body
}

const send = async (mode) => {
  if (!canSend.value) return
  if (mode === 'now') sendingNow.value = true
  else sendingQueue.value = true
  result.value = null
  sendError.value = null

  try {
    const data = await api.webhooks.test(props.webhook.id, { ...buildBody(), mode })
    result.value = data
  } catch (e) {
    sendError.value = e.message ?? 'Test failed.'
  } finally {
    sendingNow.value = false
    sendingQueue.value = false
  }
}

const viewLog = () => {
  emit('close')
  router.push(`/webhooks/${props.webhook.id}/logs`)
}

const queued = computed(() => result.value?.mode === 'queue')

const nowStatus = computed(() => {
  if (result.value?.mode !== 'now') return null
  const code = result.value?.log?.http_code
  if (!code)                        return { label: 'Failed',       icon: XCircle,       bar: 'bg-red-50    dark:bg-red-950    text-red-800    dark:text-red-300    border-b border-red-200    dark:border-red-800'    }
  if (code >= 200 && code < 300)    return { label: 'Success',      icon: CheckCircle2,  bar: 'bg-green-50  dark:bg-green-950  text-green-800  dark:text-green-300  border-b border-green-200  dark:border-green-800'  }
  if (code >= 300 && code < 400)    return { label: 'Redirect',     icon: AlertTriangle, bar: 'bg-yellow-50 dark:bg-yellow-950 text-yellow-800 dark:text-yellow-300 border-b border-yellow-200 dark:border-yellow-800' }
  if (code >= 400 && code < 500)    return { label: 'Client Error', icon: AlertTriangle, bar: 'bg-orange-50 dark:bg-orange-950 text-orange-800 dark:text-orange-300 border-b border-orange-200 dark:border-orange-800' }
  return                                   { label: 'Server Error', icon: XCircle,       bar: 'bg-red-50    dark:bg-red-950    text-red-800    dark:text-red-300    border-b border-red-200    dark:border-red-800'    }
})

const formatJson = (data) => {
  if (!data) return null
  try { return JSON.stringify(typeof data === 'string' ? JSON.parse(data) : data, null, 2) } catch { return String(data) }
}
</script>

<template>
  <Teleport to="#fswa-app">
    <div v-if="open" class="fixed inset-0 z-[100000] flex justify-end">
      <!-- Overlay -->
      <div class="fixed inset-0 bg-black/60" @click="emit('close')" />

      <!-- Panel -->
      <div class="relative z-[100001] flex flex-col w-full max-w-lg bg-background border-l border-border shadow-xl overflow-y-auto">

        <!-- Header -->
        <div class="flex items-center justify-between px-6 py-4 border-b border-border shrink-0">
          <div class="flex items-center gap-2">
            <FlaskConical class="h-4 w-4 text-muted-foreground" />
            <span class="font-semibold text-sm">Test Webhook</span>
            <span v-if="webhook" class="text-muted-foreground text-xs truncate max-w-[180px]">— {{ webhook.name }}</span>
          </div>
          <button class="rounded-sm opacity-70 hover:opacity-100 transition-opacity" @click="emit('close')">
            <X class="h-4 w-4" />
          </button>
        </div>

        <!-- Body -->
        <div class="flex-1 px-6 py-5 space-y-5">

          <!-- Unsaved changes warning -->
          <div
            v-if="hasUnsavedChanges"
            class="flex items-start gap-2 rounded-md border border-yellow-300 bg-yellow-50 dark:border-yellow-800 dark:bg-yellow-950 px-3 py-2.5 text-sm text-yellow-800 dark:text-yellow-300"
          >
            <AlertTriangle class="h-4 w-4 shrink-0 mt-0.5" />
            <span>You have unsaved changes. The test will run against the <strong>saved</strong> version of this webhook.</span>
          </div>

          <!-- Trigger -->
          <div v-if="!singleTrigger" class="space-y-1.5">
            <Label>Trigger</Label>
            <Select v-model="selectedTrigger">
              <SelectTrigger class="w-full">
                <SelectValue placeholder="Select trigger" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem v-for="t in triggers" :key="t" :value="t">{{ t }}</SelectItem>
              </SelectContent>
            </Select>
            <div v-if="hasPreGlue || hasPostGlue" class="flex items-center gap-2 flex-wrap pt-0.5">
              <Badge v-if="hasPreGlue" variant="default" class="text-xs">
                <Code2 class="h-3 w-3 mr-1" />
                Pre Glue
              </Badge>
              <Badge v-if="hasPostGlue" variant="default" class="text-xs">
                <Code2 class="h-3 w-3 mr-1" />
                Post Glue
              </Badge>
            </div>
          </div>
          <div v-else class="flex items-center flex-wrap gap-2 text-sm text-muted-foreground">
            <span>Trigger: <span class="font-mono text-foreground">{{ triggers[0] }}</span></span>
            <Badge v-if="hasPreGlue" variant="default" class="text-xs">
              <Code2 class="h-3 w-3 mr-1" />
              Pre Glue
            </Badge>
            <Badge v-if="hasPostGlue" variant="default" class="text-xs">
              <Code2 class="h-3 w-3 mr-1" />
              Post Glue
            </Badge>
          </div>

          <!-- Payload source -->
          <div class="space-y-2">
            <Label>Payload</Label>
            <RadioGroup v-model="source" class="space-y-2">
              <div class="flex items-start gap-2">
                <RadioGroupItem id="src-captured" value="captured" class="mt-0.5" />
                <label for="src-captured" class="cursor-pointer">
                  <div class="text-sm font-medium">Captured</div>
                  <div class="text-xs text-muted-foreground">Last captured payload for this trigger</div>
                </label>
              </div>
              <div class="flex items-start gap-2">
                <RadioGroupItem id="src-mapped" value="mapped" class="mt-0.5" />
                <label for="src-mapped" class="cursor-pointer">
                  <div class="text-sm font-medium">Captured + Mapping</div>
                  <div class="text-xs text-muted-foreground">Captured payload with field mapping applied</div>
                </label>
              </div>
              <div v-if="hasPreGlue || !proActive" class="flex items-start gap-2">
                <RadioGroupItem id="src-pre-glue" value="pre_glue" class="mt-0.5" :disabled="!proActive" />
                <label for="src-pre-glue" class="cursor-pointer select-none flex flex-col items-start gap-0.5">
                  <div class="text-sm font-medium">Captured + Pre-dispatch Glue + Mapping</div>
                  <div class="text-xs text-muted-foreground">Pre-dispatch Code Glue applied before field mapping</div>
                  <UpgradeBadge v-if="!proActive" />
                </label>
              </div>
              <div v-if="(hasPreGlue && hasPostGlue) || !proActive" class="flex items-start gap-2">
                <RadioGroupItem id="src-full-glue" value="full_glue" class="mt-0.5" :disabled="!proActive" />
                <label for="src-full-glue" class="cursor-pointer select-none flex flex-col items-start gap-0.5">
                  <div class="text-sm font-medium">Captured + Pre-dispatch Glue + Mapping + Post-dispatch Glue</div>
                  <div class="text-xs text-muted-foreground">Full pipeline — post-dispatch Code Glue fires after delivery</div>
                  <UpgradeBadge v-if="!proActive" />
                </label>
              </div>
              <div class="flex items-start gap-2">
                <RadioGroupItem id="src-custom" value="custom" class="mt-0.5" />
                <label for="src-custom" class="cursor-pointer">
                  <div class="text-sm font-medium">Custom</div>
                  <div class="text-xs text-muted-foreground">Write your own JSON payload</div>
                </label>
              </div>
            </RadioGroup>
          </div>

          <!-- Custom JSON editor -->
          <div v-if="source === 'custom'" class="space-y-1.5">
            <Label>Custom Payload</Label>
            <JsonEditor v-model="customPayload" />
            <p v-if="!isValidJson" class="text-xs text-destructive">Invalid JSON</p>
          </div>

          <!-- Inline result (Run Now) -->
          <div v-if="result?.mode === 'now'" class="rounded-md border overflow-hidden">
            <!-- Status bar -->
            <div :class="['flex items-center gap-2 px-3 py-2 text-sm font-medium', nowStatus.bar]">
              <component :is="nowStatus.icon" class="h-4 w-4 shrink-0" />
              <span>{{ nowStatus.label }}</span>
              <span v-if="result.log?.http_code" class="font-mono text-xs opacity-70">HTTP {{ result.log.http_code }}</span>
              <span v-if="result.log?.duration_ms" class="text-xs opacity-70 ml-auto">{{ result.log.duration_ms }}ms</span>
            </div>
            <!-- Request details -->
            <div class="p-3 border-b border-border">
              <div class="text-xs text-muted-foreground mb-1.5">Request</div>
              <div class="flex items-start gap-1.5 mb-2">
                <span class="text-xs font-mono font-semibold bg-muted px-1.5 py-0.5 rounded shrink-0">{{ result.log?.http_method ?? 'POST' }}</span>
                <span class="text-xs font-mono text-muted-foreground break-all">{{ result.log?.request_url ?? result.log?.target_url }}</span>
              </div>
              <div v-if="result.log?.request_headers && Object.keys(result.log.request_headers).length" class="mb-2">
                <div class="text-xs text-muted-foreground mb-1">Headers</div>
                <div class="bg-muted rounded p-2 space-y-0.5">
                  <div v-for="(val, key) in result.log.request_headers" :key="key" class="flex gap-2 text-xs font-mono">
                    <span class="text-muted-foreground shrink-0">{{ key }}:</span>
                    <span class="break-all">{{ val }}</span>
                  </div>
                </div>
              </div>
              <pre v-if="result.log?.request_payload" class="text-xs font-mono bg-muted rounded p-2 overflow-x-auto max-h-48 whitespace-pre-wrap break-all">{{ formatJson(result.log.request_payload) }}</pre>
              <span v-else class="text-xs text-muted-foreground italic">No body sent</span>
            </div>
            <!-- Response body -->
            <div v-if="result.log?.response_body" class="p-3 border-b border-border">
              <div class="text-xs text-muted-foreground mb-1">Response</div>
              <pre class="text-xs font-mono bg-muted rounded p-2 overflow-x-auto max-h-40 whitespace-pre-wrap break-all">{{ formatJson(result.log.response_body) }}</pre>
            </div>
            <!-- Error -->
            <div v-if="result.log?.error_message && nowStatus.label !== 'Success'" class="p-3 border-b border-border">
              <div class="text-xs text-muted-foreground mb-1">Error</div>
              <div class="text-xs font-mono text-destructive break-all">{{ result.log.error_message }}</div>
            </div>
            <!-- View in logs -->
            <div class="px-3 py-2">
              <button class="text-xs text-muted-foreground underline underline-offset-2 hover:text-foreground transition-colors flex items-center gap-1" @click="viewLog">
                View full log
                <ExternalLink class="h-3 w-3" />
              </button>
            </div>
          </div>

          <!-- Queued result -->
          <div
            v-if="queued"
            class="flex items-start gap-2 rounded-md border border-green-300 bg-green-50 dark:border-green-800 dark:bg-green-950 px-3 py-2.5 text-sm text-green-800 dark:text-green-300"
          >
            <Clock class="h-4 w-4 shrink-0 mt-0.5" />
            <div>
              Test queued — it will run on the next cron cycle.
              <button class="underline underline-offset-2 ml-1 hover:opacity-80 transition-opacity inline-flex items-center gap-0.5" @click="viewLog">
                View in Logs <ExternalLink class="h-3 w-3" />
              </button>
            </div>
          </div>

          <Alert v-if="sendError" variant="destructive">{{ sendError }}</Alert>
        </div>

        <!-- Footer -->
        <div class="px-6 py-4 border-t border-border shrink-0 flex flex-wrap gap-2">
          <Button
            :loading="sendingNow"
            :disabled="!canSend || sendingNow || sendingQueue"
            class="gap-2"
            @click="send('now')"
          >
            <FlaskConical class="h-4 w-4" />
            {{ sendingNow ? 'Sending…' : 'Run Test Now' }}
          </Button>
          <Button
            variant="outline"
            :loading="sendingQueue"
            :disabled="!canSend || sendingNow || sendingQueue"
            class="gap-2"
            @click="send('queue')"
          >
            <Clock class="h-4 w-4" />
            {{ sendingQueue ? 'Queuing…' : 'Queue Test' }}
          </Button>
          <Button variant="ghost" class="ml-auto" @click="emit('close')">Close</Button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
