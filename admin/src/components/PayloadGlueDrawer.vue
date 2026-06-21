<script setup>
import { ref, computed, watch } from 'vue'
import {
  X, Code2, Play, Save, Library, CheckCircle2, AlertCircle,
  Loader2, ChevronDown, ChevronUp, Search, Trash2, Copy, Check,
} from 'lucide-vue-next'
import { Button, Input, Label, Alert, Badge } from '@/components/ui'
import SnippetEditor from '@/components/SnippetEditor.vue'
import { useSnippets, useTriggerSnippet } from '@/composables/useSnippets'
import { useCopyToClipboard } from '@/composables/useCopyToClipboard'
import api from '@/lib/api'
import { __, sprintf } from '@/i18n'

const props = defineProps({
  open: Boolean,
  webhookId: { type: [Number, String], required: true },
  trigger: { type: String, required: true },
  examplePayload: { type: Object, default: null },
  initialTab: { type: String, default: 'pre' },
})

const emit = defineEmits(['close', 'glue-preview', 'glue-post-preview', 'glue-saved'])

// ── Tabs ────────────────────────────────────────────────────────────────────
const activeTab = ref('pre')

// ── Trigger snippet assignment ───────────────────────────────────────────────
const {
  assignment,
  loading: assignmentLoading,
  saving: assignmentSaving,
  fetch: fetchAssignment,
  save: saveAssignment,
} = useTriggerSnippet(computed(() => props.webhookId), computed(() => props.trigger))

// ── Per-tab state ────────────────────────────────────────────────────────────
const preCode = ref('')
const preSnippetName = ref('')
const preSnippetTags = ref('')
const preRunning = ref(false)
const preResult = ref(null)
const preError = ref(null)

const postCode = ref('')
const postSnippetName = ref('')
const postSnippetTags = ref('')
const postRunning = ref(false)
const postResult = ref(null)
const postError = ref(null)
const lastSuccessLog = ref(null)
const loadingLastLog = ref(false)

// ── Library ─────────────────────────────────────────────────────────────────
const {
  snippets: librarySnippets,
  loading: libraryLoading,
  fetchSnippets,
  previewSnippet,
  createSnippet,
  updateSnippet,
} = useSnippets()

const showLibrary = ref(false)
const librarySearch = ref('')

const filteredLibrary = computed(() => {
  const q = librarySearch.value.toLowerCase()
  return q
    ? librarySnippets.value.filter((s) => s.name.toLowerCase().includes(q) || (s.tags || []).join(' ').toLowerCase().includes(q))
    : librarySnippets.value
})

const toggleLibrary = () => {
  showLibrary.value = !showLibrary.value
  if (showLibrary.value) fetchSnippets()
}

const loadFromLibrary = (snippet) => {
  if (activeTab.value === 'pre') {
    preCode.value = snippet.code
    preSnippetName.value = snippet.name
    preSnippetTags.value = (snippet.tags || []).join(', ')
  } else {
    postCode.value = snippet.code
    postSnippetName.value = snippet.name
    postSnippetTags.value = (snippet.tags || []).join(', ')
  }
  showLibrary.value = false
}

// ── Load last successful log (for post-dispatch context) ─────────────────────
const fetchLastSuccessLog = async () => {
  loadingLastLog.value = true
  try {
    const result = await api.logs.list({
      webhook_id: props.webhookId,
      status: 'success',
      per_page: 1,
    })
    const items = result?.items ?? result ?? []
    lastSuccessLog.value = Array.isArray(items) && items.length ? items[0] : null
  } catch {
    lastSuccessLog.value = null
  } finally {
    loadingLastLog.value = false
  }
}

// ── Run preview ──────────────────────────────────────────────────────────────
const runPreview = async () => {
  const payload = props.examplePayload
  if (!payload) return

  if (activeTab.value === 'pre') {
    preRunning.value = true
    preResult.value = null
    preError.value = null
    try {
      const res = await previewSnippet(preCode.value, payload, 'pre')
      preResult.value = res.result
      preError.value = res.error || null
      if (res.result && !res.error) {
        emit('glue-preview', { trigger: props.trigger, result: res.result })
      }
    } catch (e) {
      preError.value = e.message || __('Preview failed')
    } finally {
      preRunning.value = false
    }
  } else {
    const postContext = lastSuccessLog.value
      ? {
          originalPayload: lastSuccessLog.value.original_payload ?? null,
          responseCode: lastSuccessLog.value.http_code ?? 0,
          responseBody: typeof lastSuccessLog.value.response_body === 'string'
            ? lastSuccessLog.value.response_body
            : JSON.stringify(lastSuccessLog.value.response_body ?? ''),
        }
      : {}
    postRunning.value = true
    postResult.value = null
    postError.value = null
    try {
      const res = await previewSnippet(postCode.value, payload, 'post', postContext)
      postResult.value = res.output || null
      postError.value = res.error || null
      if (!res.error) emit('glue-post-preview', { trigger: props.trigger })
    } catch (e) {
      postError.value = e.message || __('Preview failed')
    } finally {
      postRunning.value = false
    }
  }
}

// ── Save & assign ────────────────────────────────────────────────────────────
const savingSnippet = ref(false)
const saveSuccess = ref(false)
const saveErrorMsg = ref(null)

const saveAndAssign = async () => {
  savingSnippet.value = true
  saveSuccess.value = false
  saveErrorMsg.value = null

  try {
    const isPre = activeTab.value === 'pre'
    const code = isPre ? preCode.value : postCode.value
    const name = isPre ? preSnippetName.value : postSnippetName.value
    const tagsRaw = isPre ? preSnippetTags.value : postSnippetTags.value
    const tags = tagsRaw
      .split(',')
      .map((t) => t.trim())
      .filter(Boolean)

    const existingId = isPre ? assignment.value?.pre_snippet_id : assignment.value?.post_snippet_id

    let snippetId
    if (existingId) {
      await updateSnippet(existingId, { name: name || 'Untitled', tags, code })
      snippetId = existingId
    } else {
      const created = await createSnippet({ name: name || `${props.trigger} ${isPre ? 'pre' : 'post'} glue`, tags, code })
      snippetId = created.id
    }

    await saveAssignment({
      [isPre ? 'pre_snippet_id' : 'post_snippet_id']: snippetId,
      [isPre ? 'pre_enabled' : 'post_enabled']: true,
    })

    saveSuccess.value = true
    setTimeout(() => { saveSuccess.value = false }, 3000)
    emit('glue-saved', { trigger: props.trigger, mode: isPre ? 'pre' : 'post' })
  } catch (e) {
    saveErrorMsg.value = e.message || __('Save failed')
  } finally {
    savingSnippet.value = false
  }
}

const clearAssignment = async () => {
  const isPre = activeTab.value === 'pre'
  await saveAssignment({
    [isPre ? 'pre_snippet_id' : 'post_snippet_id']: null,
    [isPre ? 'pre_enabled' : 'post_enabled']: false,
  })
  if (isPre) {
    preCode.value = ''
    preSnippetName.value = ''
    preSnippetTags.value = ''
    preResult.value = null
  } else {
    postCode.value = ''
    postSnippetName.value = ''
    postSnippetTags.value = ''
    postResult.value = null
  }
}

// ── Lifecycle ────────────────────────────────────────────────────────────────
const populateFromAssignment = (a) => {
  if (!a) return
  if (a.pre_snippet) {
    preCode.value = a.pre_snippet.code ?? ''
    preSnippetName.value = a.pre_snippet.name ?? ''
    preSnippetTags.value = (a.pre_snippet.tags || []).join(', ')
  }
  if (a.post_snippet) {
    postCode.value = a.post_snippet.code ?? ''
    postSnippetName.value = a.post_snippet.name ?? ''
    postSnippetTags.value = (a.post_snippet.tags || []).join(', ')
  }
}

watch(() => props.open, async (open) => {
  if (!open) return
  activeTab.value = props.initialTab ?? 'pre'
  preResult.value = null
  postResult.value = null
  preError.value = null
  postError.value = null
  showLibrary.value = false
  saveSuccess.value = false
  saveErrorMsg.value = null

  await fetchAssignment()
  populateFromAssignment(assignment.value)
  ensureDefaultCode(activeTab.value)
  fetchLastSuccessLog()
})

watch(assignment, (a) => { if (a) populateFromAssignment(a) })

const formatJson = (data) => {
  if (data === null || data === undefined) return ''
  try {
    return JSON.stringify(typeof data === 'string' ? JSON.parse(data) : data, null, 2)
  } catch {
    return String(data)
  }
}

const currentSnippetName = computed(() => {
  if (activeTab.value === 'pre') return assignment.value?.pre_snippet?.name ?? null
  return assignment.value?.post_snippet?.name ?? null
})

const hasAssignment = computed(() => {
  if (activeTab.value === 'pre') return !!assignment.value?.pre_snippet_id
  return !!assignment.value?.post_snippet_id
})

const defaultCode = {
  pre: `// $payload — the mapped payload\n// $args — $payload['args'] shorthand (WP hook args)\n// {{ $args.0.field }} — template shorthand for $args[0]['field']\n\n$payload['extra_field'] = 'value';\nreturn $payload;\n`,
  post: `// $payload         — the sent (mapped) payload\n// $originalPayload — pre-mapping payload\n// $responseCode    — HTTP status code\n// $responseBody    — raw response string\n\nerror_log('Response: ' . $responseCode);\n`,
}

const ensureDefaultCode = (tab) => {
  if (tab === 'pre' && !preCode.value) preCode.value = defaultCode.pre
  if (tab === 'post' && !postCode.value) postCode.value = defaultCode.post
}

watch(activeTab, ensureDefaultCode)

// ── Copy to clipboard ────────────────────────────────────────────────────────
const { copy, copiedKey } = useCopyToClipboard()
const copyCode = () => {
  const code = activeTab.value === 'pre' ? preCode.value : postCode.value
  copy(code, 'glue-code')
}
</script>

<template>
  <Teleport to="#fswa-app">
    <div v-if="open" class="fixed inset-0 z-[100000] flex justify-end">
      <!-- Overlay -->
      <div class="fixed inset-0 bg-black/60" @click="emit('close')" />

      <!-- Panel — wider than TestWebhookDrawer (max-w-3xl vs max-w-lg) -->
      <div class="relative z-[100001] flex flex-col w-full max-w-3xl bg-background border-l border-border shadow-xl overflow-hidden">

        <!-- Header -->
        <div class="flex items-center justify-between px-6 py-4 border-b border-border shrink-0">
          <div class="flex items-center gap-2">
            <Code2 class="h-4 w-4 text-muted-foreground" />
            <span class="font-semibold text-sm">{{ __('Code Glue') }}</span>
            <span class="text-muted-foreground text-xs truncate max-w-[260px] font-mono">— {{ trigger }}</span>
          </div>
          <button class="rounded-sm opacity-70 hover:opacity-100 transition-opacity" @click="emit('close')">
            <X class="h-4 w-4" />
          </button>
        </div>

        <!-- Tabs -->
        <div class="flex border-b border-border shrink-0">
          <button
            :class="['flex-1 py-2.5 text-sm font-medium transition-colors border-b-2', activeTab === 'pre' ? 'border-primary text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground']"
            @click="activeTab = 'pre'; ensureDefaultCode('pre')"
          >
            {{ __('Pre-dispatch') }}
          </button>
          <button
            :class="['flex-1 py-2.5 text-sm font-medium transition-colors border-b-2', activeTab === 'post' ? 'border-primary text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground']"
            @click="activeTab = 'post'; ensureDefaultCode('post')"
          >
            {{ __('Post-dispatch') }}
          </button>
        </div>

        <!-- Body — scrollable -->
        <div class="flex-1 overflow-y-auto px-6 py-5 space-y-4">

          <!-- Loading state -->
          <div v-if="assignmentLoading" class="flex items-center gap-2 text-sm text-muted-foreground">
            <Loader2 class="h-4 w-4 animate-spin" />
            {{ __('Loading…') }}
          </div>

          <template v-else>
            <!-- Current assignment chip + library button -->
            <div class="flex items-center gap-2 flex-wrap">
              <Badge v-if="currentSnippetName" variant="secondary" class="gap-1 text-xs">
                <CheckCircle2 class="h-3 w-3 text-green-500" />
                {{ currentSnippetName }}
              </Badge>
              <span v-else class="text-xs text-muted-foreground">{{ __('No snippet assigned') }}</span>

              <Button size="sm" variant="outline" class="ml-auto gap-1.5" @click="toggleLibrary">
                <Library class="h-3.5 w-3.5" />
                {{ __('Browse Library') }}
                <component :is="showLibrary ? ChevronUp : ChevronDown" class="h-3 w-3" />
              </Button>
            </div>

            <!-- Library panel -->
            <div v-if="showLibrary" class="border rounded-md overflow-hidden">
              <div class="flex items-center gap-2 px-3 py-2 border-b bg-muted/40">
                <Search class="h-3.5 w-3.5 text-muted-foreground shrink-0" />
                <input
                  v-model="librarySearch"
                  type="text"
                  :placeholder="__('Search snippets…')"
                  class="flex-1 !bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                />
                <Loader2 v-if="libraryLoading" class="h-3.5 w-3.5 animate-spin text-muted-foreground" />
              </div>
              <div class="max-h-48 overflow-y-auto">
                <div v-if="!filteredLibrary.length" class="px-3 py-4 text-sm text-muted-foreground text-center">
                  {{ libraryLoading ? __('Loading…') : __('No snippets found') }}
                </div>
                <button
                  v-for="s in filteredLibrary"
                  :key="s.id"
                  class="w-full flex items-start gap-2 px-3 py-2.5 text-left hover:bg-muted/50 transition-colors border-b last:border-0"
                  @click="loadFromLibrary(s)"
                >
                  <div class="flex-1 min-w-0">
                    <div class="text-sm font-medium truncate">{{ s.name || __('Untitled') }}</div>
                    <div v-if="s.tags?.length" class="flex gap-1 mt-0.5 flex-wrap">
                      <span v-for="tag in s.tags" :key="tag" class="text-xs bg-muted px-1.5 py-0.5 rounded">{{ tag }}</span>
                    </div>
                  </div>
                  <span class="text-xs text-muted-foreground shrink-0 mt-0.5">{{ __('Load') }}</span>
                </button>
              </div>
            </div>

            <!-- Context info for each tab -->
            <div v-if="activeTab === 'pre'" class="text-xs text-muted-foreground rounded-md border px-3 py-2 bg-muted/20 space-y-1">
              <p v-html="sprintf(__('%1$sAvailable variables:%2$s %3$s$payload%4$s (mapped array), %3$s$args%4$s (%5$s$payload[\'args\']%6$s)'), '<strong>', '</strong>', '<code class=&quot;font-mono&quot;>', '</code>', '<code>', '</code>')"></p>
              <p v-html="sprintf(__('%1$sShorthand:%2$s works for any variable, e.g. %3$s{{ $args.0.field }}%4$s → %3$s$args[0][\'field\']%4$s, %3$s{{ $payload.key }}%4$s → %3$s$payload[\'key\']%4$s'), '<strong>', '</strong>', '<code class=&quot;font-mono&quot;>', '</code>')"></p>
              <p v-html="sprintf(__('Must %1$sreturn $payload;%2$s — the returned array replaces the payload before dispatch.'), '<code class=&quot;font-mono&quot;>', '</code>')"></p>
            </div>
            <div v-else class="text-xs text-muted-foreground rounded-md border px-3 py-2 bg-muted/20 space-y-1">
              <p v-html="sprintf(__('%1$sAvailable variables:%2$s %3$s$payload%4$s (sent), %3$s$originalPayload%4$s (pre-mapping), %3$s$responseCode%4$s, %3$s$responseBody%4$s'), '<strong>', '</strong>', '<code class=&quot;font-mono&quot;>', '</code>')"></p>
              <p v-html="sprintf(__('%1$sShorthand:%2$s works for any variable, e.g. %3$s{{ $originalPayload.args.0.id }}%4$s → %3$s$originalPayload[\'args\'][0][\'id\']%4$s'), '<strong>', '</strong>', '<code class=&quot;font-mono&quot;>', '</code>')"></p>
              <p v-html="sprintf(__('Return value is ignored — use for side effects: %1$supdate_post_meta()%2$s, %1$swp_mail()%2$s, %1$serror_log()%2$s, follow-up API calls, etc.'), '<code class=&quot;font-mono&quot;>', '</code>')"></p>
            </div>

            <!-- Post-dispatch: last successful log context -->
            <div v-if="activeTab === 'post'" class="space-y-1.5">
              <Label class="text-xs text-muted-foreground">{{ __('Last successful response (used as preview context)') }}</Label>
              <div v-if="loadingLastLog" class="flex items-center gap-2 text-xs text-muted-foreground">
                <Loader2 class="h-3 w-3 animate-spin" /> {{ __('Loading last log…') }}
              </div>
              <div v-else-if="lastSuccessLog" class="rounded-md border overflow-hidden">
                <div class="flex items-center gap-2 px-3 py-1.5 bg-green-50 dark:bg-green-950 border-b border-green-200 dark:border-green-800 text-xs text-green-800 dark:text-green-300">
                  <CheckCircle2 class="h-3 w-3" />
                  {{ sprintf(__('HTTP %s'), lastSuccessLog.http_code) }} — {{ lastSuccessLog.event_timestamp ?? '' }}
                </div>
                <pre class="text-xs font-mono px-3 py-2 overflow-x-auto max-h-32 whitespace-pre-wrap break-all bg-muted/20">{{ formatJson(lastSuccessLog.response_body) }}</pre>
              </div>
              <p v-else class="text-xs text-muted-foreground italic">{{ __('No successful dispatches found for this webhook — run a test first.') }}</p>
            </div>

            <!-- Code editor -->
            <div class="space-y-1.5">
              <div class="flex items-center justify-between">
                <Label>{{ __('PHP Code') }}</Label>
                <button
                  class="shrink-0 rounded p-1 hover:bg-muted transition-colors"
                  :title="__('Copy code')"
                  @click="copyCode"
                >
                  <Check v-if="copiedKey === 'glue-code'" class="h-3.5 w-3.5 text-green-500" />
                  <Copy v-else class="h-3.5 w-3.5 text-muted-foreground" />
                </button>
              </div>
              <SnippetEditor
                v-if="activeTab === 'pre'"
                :modelValue="preCode"
                @update:modelValue="preCode = $event"
              />
              <SnippetEditor
                v-else
                :modelValue="postCode"
                @update:modelValue="postCode = $event"
              />
            </div>

            <!-- No payload warning -->
            <div v-if="!examplePayload" class="flex items-center gap-2 text-xs text-muted-foreground border rounded-md px-3 py-2 bg-muted/20">
              <AlertCircle class="h-3.5 w-3.5 shrink-0" />
              {{ __('Capture an example payload to enable live preview.') }}
            </div>

            <!-- Run preview button -->
            <Button
              size="sm"
              variant="outline"
              :disabled="!examplePayload || (activeTab === 'pre' ? preRunning : postRunning)"
              class="gap-1.5 w-full"
              @click="runPreview"
            >
              <Loader2 v-if="activeTab === 'pre' ? preRunning : postRunning" class="h-3.5 w-3.5 animate-spin" />
              <Play v-else class="h-3.5 w-3.5" />
              {{ (activeTab === 'pre' ? preRunning : postRunning) ? __('Running…') : __('Run Preview') }}
            </Button>

            <!-- Pre-dispatch result -->
            <template v-if="activeTab === 'pre'">
              <div v-if="preError" class="rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2.5">
                <div class="text-xs font-semibold text-destructive mb-1">{{ __('Error') }}</div>
                <pre class="text-xs font-mono text-destructive whitespace-pre-wrap break-all">{{ preError }}</pre>
              </div>
              <div v-else-if="preResult !== null" class="space-y-1.5">
                <div class="flex items-center gap-2">
                  <Label class="text-xs">{{ __('Result Preview') }}</Label>
                  <Badge variant="success" class="text-xs gap-1">
                    <CheckCircle2 class="h-3 w-3" />
                    {{ __('OK') }}
                  </Badge>
                </div>
                <pre class="text-xs font-mono bg-muted rounded-md p-3 overflow-x-auto max-h-64 whitespace-pre-wrap break-all border">{{ formatJson(preResult) }}</pre>
                <div class="flex items-center gap-1.5 text-xs text-green-700 dark:text-green-400">
                  <CheckCircle2 class="h-3 w-3 shrink-0" />
                  {{ __('Result preview is now used as the effective payload for Mapping & Conditions below.') }}
                </div>
              </div>
            </template>

            <!-- Post-dispatch result -->
            <template v-if="activeTab === 'post'">
              <div v-if="postError" class="rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2.5">
                <div class="text-xs font-semibold text-destructive mb-1">{{ __('Error') }}</div>
                <pre class="text-xs font-mono text-destructive whitespace-pre-wrap break-all">{{ postError }}</pre>
              </div>
              <div v-else-if="postResult !== null" class="space-y-1.5">
                <Label class="text-xs">{{ __('Output (stdout)') }}</Label>
                <pre class="text-xs font-mono bg-muted rounded-md p-3 overflow-x-auto max-h-40 whitespace-pre-wrap break-all border">{{ postResult || __('(no output)') }}</pre>
              </div>
            </template>

            <!-- Save section -->
            <div class="border-t pt-4 space-y-3">
              <div class="grid grid-cols-2 gap-3">
                <div class="space-y-1">
                  <Label class="text-xs">{{ __('Snippet Name') }}</Label>
                  <Input
                    :value="activeTab === 'pre' ? preSnippetName : postSnippetName"
                    :placeholder="`${trigger} ${activeTab} glue`"
                    @input="activeTab === 'pre' ? (preSnippetName = $event.target.value) : (postSnippetName = $event.target.value)"
                  />
                </div>
                <div class="space-y-1">
                  <Label class="text-xs">{{ __('Tags (comma-separated)') }}</Label>
                  <Input
                    :value="activeTab === 'pre' ? preSnippetTags : postSnippetTags"
                    placeholder="woocommerce, enrich"
                    @input="activeTab === 'pre' ? (preSnippetTags = $event.target.value) : (postSnippetTags = $event.target.value)"
                  />
                </div>
              </div>

              <Alert v-if="saveErrorMsg" variant="destructive" class="text-xs">{{ saveErrorMsg }}</Alert>
              <div v-if="saveSuccess" class="flex items-center gap-1.5 text-xs text-green-700 dark:text-green-400">
                <CheckCircle2 class="h-3.5 w-3.5" />
                {{ __('Snippet saved and assigned.') }}
              </div>

              <div class="flex gap-2">
                <Button
                  :disabled="savingSnippet || assignmentSaving"
                  class="gap-1.5 flex-1"
                  @click="saveAndAssign"
                >
                  <Loader2 v-if="savingSnippet || assignmentSaving" class="h-4 w-4 animate-spin" />
                  <Save v-else class="h-4 w-4" />
                  {{ (savingSnippet || assignmentSaving) ? __('Saving…') : (hasAssignment ? __('Update Snippet') : __('Save & Assign')) }}
                </Button>
                <Button
                  v-if="hasAssignment"
                  variant="outline"
                  size="icon"
                  :title="__('Clear assignment')"
                  :disabled="savingSnippet || assignmentSaving"
                  @click="clearAssignment"
                >
                  <Trash2 class="h-4 w-4 text-destructive" />
                </Button>
              </div>
            </div>
          </template>
        </div>

        <!-- Footer -->
        <div class="px-6 py-3 border-t border-border shrink-0 flex justify-end">
          <Button variant="ghost" @click="emit('close')">{{ __('Close') }}</Button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
