<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ShieldCheck, KeyRound, Plus, Pencil, Trash2, Eye, EyeOff } from 'lucide-vue-next'
import { Button, Badge, Dialog, Input, Label, Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/components/ui'
import api from '@/lib/api'
import { formatUtcDate } from '@/lib/dates'

const credentials = ref([])
const loading = ref(true)
const error = ref(null)

const typeOptions = [
  { value: 'bearer', label: 'Bearer token (PAT)', description: 'Sent as Authorization: Bearer <token>' },
  { value: 'basic', label: 'Basic auth (user + password)', description: 'Authorization: Basic <base64(user:pass)> — incl. WP Application Passwords' },
  { value: 'api_key', label: 'API key (custom header)', description: 'A named header such as X-API-Key' },
  { value: 'custom', label: 'Custom Authorization value', description: 'Raw value for any header — escape hatch' },
]

const typeLabel = (type) => typeOptions.find((t) => t.value === type)?.label ?? type

// --- Create / edit dialog state ---
const showFormDialog = ref(false)
const editingId = ref(null)
const submitting = ref(false)
const formError = ref(null)
const form = reactive({
  name: '',
  type: 'bearer',
  header_name: 'X-API-Key',
  secret: '',
  username: '',
  password: '',
})

const isEditing = computed(() => editingId.value !== null)
const needsHeaderName = computed(() => form.type === 'api_key' || form.type === 'custom')
const isBasic = computed(() => form.type === 'basic')

// Reveal toggles so users can double-check a secret before saving.
const revealSecret = ref(false)
const revealPassword = ref(false)

const resetForm = () => {
  form.name = ''
  form.type = 'bearer'
  form.header_name = 'X-API-Key'
  form.secret = ''
  form.username = ''
  form.password = ''
  formError.value = null
  submitting.value = false
  revealSecret.value = false
  revealPassword.value = false
}

const openCreate = () => {
  resetForm()
  editingId.value = null
  showFormDialog.value = true
}

const openEdit = (credential) => {
  resetForm()
  editingId.value = credential.id
  form.name = credential.name
  form.type = credential.type
  form.header_name = credential.header_name || 'X-API-Key'
  showFormDialog.value = true
}

const closeForm = () => {
  showFormDialog.value = false
  editingId.value = null
}

const handleSubmit = async () => {
  if (!form.name.trim()) {
    formError.value = 'Name is required.'
    return
  }

  const payload = {
    name: form.name.trim(),
    type: form.type,
  }
  if (needsHeaderName.value) {
    payload.header_name = form.header_name.trim() || 'Authorization'
  }

  // Secret material — on edit, only send if the user entered new values.
  if (isBasic.value) {
    if (form.username || form.password || !isEditing.value) {
      payload.username = form.username
      payload.password = form.password
    }
  } else if (form.secret || !isEditing.value) {
    payload.secret = form.secret
  }

  submitting.value = true
  formError.value = null
  try {
    if (isEditing.value) {
      await api.credentials.update(editingId.value, payload)
    } else {
      await api.credentials.create(payload)
    }
    closeForm()
    await refreshAll()
  } catch (e) {
    formError.value = e.message
  } finally {
    submitting.value = false
  }
}

// --- Delete dialog state ---
const showDeleteDialog = ref(false)
const credentialToDelete = ref(null)
const deleteInUse = ref(0)
const deleting = ref(false)
const deleteError = ref(null)

const openDelete = (credential) => {
  credentialToDelete.value = credential
  deleteInUse.value = 0
  deleteError.value = null
  showDeleteDialog.value = true
}

const handleDelete = async (force = false) => {
  if (!credentialToDelete.value) return
  deleting.value = true
  deleteError.value = null
  try {
    await api.credentials.delete(credentialToDelete.value.id, force)
    showDeleteDialog.value = false
    credentialToDelete.value = null
    deleteInUse.value = 0
    await refreshAll()
  } catch (e) {
    if (e.code === 'rest_credential_in_use') {
      deleteInUse.value = e.data?.data?.in_use ?? e.data?.in_use ?? 1
      deleteError.value = e.message
    } else {
      deleteError.value = e.message
    }
  } finally {
    deleting.value = false
  }
}

const loadCredentials = async () => {
  loading.value = true
  error.value = null
  try {
    credentials.value = await api.credentials.list()
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

// --- Encryption key status / FSWA_SECRET_KEY migration ---
const keyStatus = ref(null)
const migrating = ref(false)
const migrateResult = ref(null)

const loadKeyStatus = async () => {
  try {
    keyStatus.value = await api.credentials.keyStatus()
  } catch (e) {
    keyStatus.value = null
  }
}

const handleReencrypt = async () => {
  migrating.value = true
  migrateResult.value = null
  try {
    migrateResult.value = await api.credentials.reencrypt()
    await loadKeyStatus()
    await loadCredentials()
  } catch (e) {
    error.value = e.message
  } finally {
    migrating.value = false
  }
}

const refreshAll = async () => {
  await Promise.all([loadCredentials(), loadKeyStatus()])
}

onMounted(refreshAll)
</script>

<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div>
        <h2 class="text-xl font-semibold text-foreground flex items-center gap-2">
          <ShieldCheck class="h-5 w-5" />
          Credentials Vault
        </h2>
        <p class="text-sm text-muted-foreground mt-1">
          Store reusable authentication secrets once, then reference them from your webhooks.
          Secrets are encrypted at rest and are never shown again after saving — not even to you.
        </p>
      </div>
      <Button @click="openCreate">
        <Plus class="mr-2 h-4 w-4" />
        New credential
      </Button>
    </div>

    <!-- Error -->
    <div v-if="error" class="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
      {{ error }}
    </div>

    <!-- Loading -->
    <div v-if="loading" class="text-sm text-muted-foreground">Loading…</div>

    <!-- Empty state -->
    <div
      v-else-if="credentials.length === 0"
      class="rounded-lg border border-dashed border-border p-12 text-center"
    >
      <ShieldCheck class="mx-auto mb-3 h-10 w-10 text-muted-foreground/50" />
      <h3 class="text-sm font-medium text-foreground">No saved credentials</h3>
      <p class="mt-1 text-sm text-muted-foreground">Add a credential to securely reuse it across webhooks.</p>
      <Button class="mt-4" @click="openCreate">
        <Plus class="mr-2 h-4 w-4" />
        Add first credential
      </Button>
    </div>

    <!-- Credentials table -->
    <div v-else class="rounded-md border border-border overflow-hidden">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-border bg-muted/50">
            <th class="px-4 py-2.5 text-left font-medium text-muted-foreground">Name</th>
            <th class="px-4 py-2.5 text-left font-medium text-muted-foreground">Type</th>
            <th class="px-4 py-2.5 text-left font-medium text-muted-foreground">Header</th>
            <th class="px-4 py-2.5 text-left font-medium text-muted-foreground">Hint</th>
            <th class="px-4 py-2.5 text-left font-medium text-muted-foreground">Created</th>
            <th class="px-4 py-2.5 text-right font-medium text-muted-foreground">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="credential in credentials"
            :key="credential.id"
            class="border-b border-border last:border-0 hover:bg-muted/30 transition-colors"
          >
            <td class="px-4 py-3 font-medium text-foreground">{{ credential.name }}</td>
            <td class="px-4 py-3">
              <Badge variant="secondary">{{ typeLabel(credential.type) }}</Badge>
            </td>
            <td class="px-4 py-3 font-mono text-muted-foreground">{{ credential.header_name }}</td>
            <td class="px-4 py-3 font-mono text-muted-foreground">{{ credential.hint }}</td>
            <td class="px-4 py-3 text-muted-foreground">{{ formatUtcDate(credential.created_at) }}</td>
            <td class="px-4 py-3">
              <div class="flex items-center justify-end gap-2">
                <Button variant="ghost" size="sm" @click="openEdit(credential)" title="Edit credential">
                  <Pencil class="h-4 w-4" />
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  @click="openDelete(credential)"
                  title="Delete credential"
                  class="text-destructive hover:text-destructive"
                >
                  <Trash2 class="h-4 w-4" />
                </Button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Encryption key status -->
    <div v-if="keyStatus" class="space-y-3">
      <!-- Undecryptable warning (independent of key source) -->
      <div v-if="keyStatus.undecryptable > 0" class="rounded-md border border-destructive/50 bg-destructive/10 p-4 text-sm text-destructive">
        <p class="font-medium">{{ keyStatus.undecryptable }} credential(s) cannot be decrypted with the current key.</p>
        <p class="mt-1">This usually means the encryption key changed (e.g. <code class="font-mono text-xs">FSWA_SECRET_KEY</code> or WordPress salts). Edit each affected credential and re-enter its secret to fix it.</p>
      </div>

      <!-- Transitional: constant set but DB key still present -->
      <div v-if="keyStatus.needs_migration" class="rounded-md border border-amber-500/50 bg-amber-500/10 p-4 text-sm space-y-3">
        <div class="flex items-start gap-2">
          <KeyRound class="h-5 w-5 mt-0.5 shrink-0 text-amber-600" />
          <div>
            <p class="font-medium text-foreground">Finish moving the key into wp-config.php</p>
            <p class="text-muted-foreground mt-1">
              <code class="font-mono text-xs">FSWA_SECRET_KEY</code> is defined and your credentials still work, but a copy of the old key remains in the database — so you don't yet get the full benefit. Re-encrypt now to seal every secret with the wp-config key and delete the database key.
            </p>
          </div>
        </div>
        <div v-if="migrateResult" class="text-xs text-muted-foreground">
          Migrated {{ migrateResult.migrated }} · failed {{ migrateResult.failed }}<span v-if="migrateResult.db_key_removed"> · database key removed</span>
        </div>
        <Button size="sm" :disabled="migrating" @click="handleReencrypt">
          {{ migrating ? 'Re-encrypting…' : 'Re-encrypt & remove database key' }}
        </Button>
      </div>

      <!-- Fully protected -->
      <div v-else-if="keyStatus.fully_protected" class="rounded-md border border-emerald-500/40 bg-emerald-500/10 p-4 text-sm">
        <p class="font-medium text-foreground flex items-center gap-2">
          <ShieldCheck class="h-4 w-4 text-emerald-600" />
          Maximum protection
        </p>
        <p class="text-muted-foreground mt-1">
          The encryption key lives in <code class="font-mono text-xs">wp-config.php</code> (<code class="font-mono text-xs">FSWA_SECRET_KEY</code>) and is not stored in the database. A database-only dump cannot decrypt your secrets.
        </p>
      </div>

      <!-- Default: DB key, suggest hardening with full guidance -->
      <div v-else class="rounded-md border border-border bg-muted/30 p-4 text-sm space-y-2">
        <p class="font-medium text-foreground flex items-center gap-2">
          <ShieldCheck class="h-4 w-4" />
          How secrets are protected
        </p>
        <ul class="space-y-1 text-muted-foreground list-disc pl-5">
          <li>Secrets are encrypted at rest (AES-256-GCM) and never returned by the API — only a masked hint is shown.</li>
          <li>An <Badge variant="default" class="mx-1">agent</Badge> API token can assign credentials to webhooks but can never read their values.</li>
        </ul>
        <div class="rounded-md border border-border bg-background p-3 mt-2 space-y-2">
          <p class="font-medium text-foreground">Optional: harden with <code class="font-mono text-xs">FSWA_SECRET_KEY</code></p>
          <p class="text-muted-foreground">
            By default the encryption key is generated and stored in the database. For stronger protection — so a database-only dump can't decrypt secrets — move the key into <code class="font-mono text-xs">wp-config.php</code>:
          </p>
          <pre class="bg-muted rounded p-2 text-xs overflow-x-auto"><code>define( 'FSWA_SECRET_KEY', 'a-long-random-string-64-chars-or-more' );</code></pre>
          <ul class="text-muted-foreground list-disc pl-5 space-y-1">
            <li><span class="font-medium text-foreground">Format:</span> any long, random, secret string (64+ characters recommended). Generate one from the <a href="https://api.wordpress.org/secret-key/1.1/salt/" target="_blank" rel="noopener" class="underline">WordPress salt generator</a> or with <code class="font-mono text-xs">wp_generate_password(64, true, true)</code>.</li>
            <li><span class="font-medium text-foreground">Safe to add anytime:</span> existing credentials keep working immediately. After adding it, return here and click the <em>Re-encrypt</em> button that appears to finish the move and remove the database key.</li>
            <li><span class="font-medium text-foreground">Keep it stable &amp; backed up:</span> don't change or lose this value once credentials are sealed with it, or they become unrecoverable.</li>
          </ul>
        </div>
      </div>
    </div>

    <!-- Create / edit dialog -->
    <Dialog
      :open="showFormDialog"
      :title="isEditing ? 'Edit credential' : 'New credential'"
      :description="isEditing ? 'Update this credential. Leave secret fields blank to keep the existing value.' : 'Secrets are encrypted on save and never shown again.'"
      @close="closeForm"
    >
      <div class="space-y-4">
        <div v-if="formError" class="text-sm text-destructive">{{ formError }}</div>

        <div class="space-y-1.5">
          <Label for="cred-name">Name</Label>
          <Input id="cred-name" v-model="form.name" placeholder="e.g. HubSpot PAT, Stripe key" />
        </div>

        <div class="space-y-1.5">
          <Label>Type</Label>
          <Select v-model="form.type">
            <SelectTrigger>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem v-for="opt in typeOptions" :key="opt.value" :value="opt.value">
                <div>
                  <div class="font-medium">{{ opt.label }}</div>
                  <div class="text-xs text-muted-foreground">{{ opt.description }}</div>
                </div>
              </SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div v-if="needsHeaderName" class="space-y-1.5">
          <Label for="cred-header">Header name</Label>
          <Input id="cred-header" v-model="form.header_name" placeholder="X-API-Key" />
        </div>

        <!-- Basic auth -->
        <template v-if="isBasic">
          <div class="space-y-1.5">
            <Label for="cred-username">Username</Label>
            <Input id="cred-username" v-model="form.username" autocomplete="off" placeholder="WordPress username or API user" />
          </div>
          <div class="space-y-1.5">
            <Label for="cred-password">Password</Label>
            <div class="relative">
              <Input id="cred-password" v-model="form.password" :type="revealPassword ? 'text' : 'password'" autocomplete="new-password" class="pr-10" :placeholder="isEditing ? 'Leave blank to keep current' : 'Password or application password'" />
              <button
                type="button"
                class="absolute inset-y-0 right-0 flex items-center px-3 text-muted-foreground hover:text-foreground"
                :aria-label="revealPassword ? 'Hide password' : 'Show password'"
                :title="revealPassword ? 'Hide' : 'Show'"
                @click="revealPassword = !revealPassword"
              >
                <EyeOff v-if="revealPassword" class="h-4 w-4" />
                <Eye v-else class="h-4 w-4" />
              </button>
            </div>
          </div>
        </template>

        <!-- Single secret -->
        <div v-else class="space-y-1.5">
          <Label for="cred-secret">Secret value</Label>
          <div class="relative">
            <Input id="cred-secret" v-model="form.secret" :type="revealSecret ? 'text' : 'password'" autocomplete="new-password" class="pr-10" :placeholder="isEditing ? 'Leave blank to keep current' : 'Paste the token / key'" />
            <button
              type="button"
              class="absolute inset-y-0 right-0 flex items-center px-3 text-muted-foreground hover:text-foreground"
              :aria-label="revealSecret ? 'Hide secret' : 'Show secret'"
              :title="revealSecret ? 'Hide' : 'Show'"
              @click="revealSecret = !revealSecret"
            >
              <EyeOff v-if="revealSecret" class="h-4 w-4" />
              <Eye v-else class="h-4 w-4" />
            </button>
          </div>
        </div>
      </div>

      <template #footer>
        <Button variant="outline" @click="closeForm" :disabled="submitting">Cancel</Button>
        <Button @click="handleSubmit" :disabled="submitting">
          {{ submitting ? 'Saving…' : (isEditing ? 'Save changes' : 'Create credential') }}
        </Button>
      </template>
    </Dialog>

    <!-- Delete dialog -->
    <Dialog
      :open="showDeleteDialog"
      :title="`Delete credential: ${credentialToDelete?.name}`"
      description="This permanently removes the stored secret."
      @close="showDeleteDialog = false; credentialToDelete = null; deleteInUse = 0; deleteError = null"
    >
      <div v-if="deleteError" class="text-sm text-destructive">
        {{ deleteError }}
      </div>
      <p v-if="deleteInUse > 0" class="text-sm text-muted-foreground mt-2">
        Deleting with force will detach it from {{ deleteInUse }} webhook(s); those webhooks will send no Authorization header until reconfigured.
      </p>

      <template #footer>
        <Button variant="outline" @click="showDeleteDialog = false; credentialToDelete = null; deleteInUse = 0; deleteError = null" :disabled="deleting">
          Cancel
        </Button>
        <Button v-if="deleteInUse > 0" variant="destructive" @click="handleDelete(true)" :disabled="deleting">
          {{ deleting ? 'Deleting…' : 'Force delete & detach' }}
        </Button>
        <Button v-else variant="destructive" @click="handleDelete(false)" :disabled="deleting">
          {{ deleting ? 'Deleting…' : 'Delete credential' }}
        </Button>
      </template>
    </Dialog>
  </div>
</template>
