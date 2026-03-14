<script setup>
import { ref, onMounted } from 'vue'
import { KeyRound, Plus, RefreshCw, Trash2, CalendarClock } from 'lucide-vue-next'
import { Button, Badge, Dialog } from '@/components/ui'
import CreateTokenDialog from '@/components/CreateTokenDialog.vue'
import TokenCreatedDialog from '@/components/TokenCreatedDialog.vue'
import RotateTokenDialog from '@/components/RotateTokenDialog.vue'
import ChangeExpiryDialog from '@/components/ChangeExpiryDialog.vue'
import api from '@/lib/api'
import { isUtcExpired, formatUtcDate } from '@/lib/dates'

const tokens = ref([])
const loading = ref(true)
const error = ref(null)

// Dialog state
const showCreateDialog = ref(false)
const showCreatedDialog = ref(false)
const plaintextToken = ref(null)

const showRotateDialog = ref(false)
const tokenToRotate = ref(null)

const showChangeExpiryDialog = ref(false)
const tokenToChangeExpiry = ref(null)

const showDeleteDialog = ref(false)
const tokenToDelete = ref(null)
const deleting = ref(false)

const scopeBadgeVariant = (scope) => {
  if (scope === 'full') return 'destructive'
  if (scope === 'operational') return 'warning'
  return 'secondary'
}

const formatDate = formatUtcDate
const isExpired = (token) => isUtcExpired(token.expires_at)

const loadTokens = async () => {
  loading.value = true
  error.value = null
  try {
    tokens.value = await api.tokens.list()
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

const handleCreate = async (data) => {
  try {
    const result = await api.tokens.create(data)
    plaintextToken.value = result.plaintext_token
    showCreateDialog.value = false
    showCreatedDialog.value = true
    await loadTokens()
  } catch (e) {
    error.value = e.message
  }
}

const handleCreatedClose = () => {
  showCreatedDialog.value = false
  plaintextToken.value = null
}

const openRotate = (token) => {
  tokenToRotate.value = token
  showRotateDialog.value = true
}

const handleRotate = async (token, payload = {}) => {
  try {
    const result = await api.tokens.rotate(token.id, payload)
    plaintextToken.value = result.plaintext_token
    showRotateDialog.value = false
    tokenToRotate.value = null
    showCreatedDialog.value = true
    await loadTokens()
  } catch (e) {
    error.value = e.message
    showRotateDialog.value = false
    tokenToRotate.value = null
  }
}

const openChangeExpiry = (token) => {
  tokenToChangeExpiry.value = token
  showChangeExpiryDialog.value = true
}

const handleChangeExpiry = async ({ token, expiresAt }) => {
  try {
    await api.tokens.updateExpiry(token.id, expiresAt)
    showChangeExpiryDialog.value = false
    tokenToChangeExpiry.value = null
    await loadTokens()
  } catch (e) {
    error.value = e.message
    showChangeExpiryDialog.value = false
    tokenToChangeExpiry.value = null
  }
}

const openDelete = (token) => {
  tokenToDelete.value = token
  showDeleteDialog.value = true
}

const handleDelete = async () => {
  if (!tokenToDelete.value) return
  deleting.value = true
  try {
    await api.tokens.delete(tokenToDelete.value.id)
    showDeleteDialog.value = false
    tokenToDelete.value = null
    await loadTokens()
  } catch (e) {
    error.value = e.message
  } finally {
    deleting.value = false
  }
}

onMounted(loadTokens)
</script>

<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div>
        <h2 class="text-xl font-semibold text-foreground flex items-center gap-2">
          <KeyRound class="h-5 w-5" />
          API Tokens
        </h2>
        <p class="text-sm text-muted-foreground mt-1">
          Tokens allow external systems to access the REST API without a browser session.
          Token management is always admin-only regardless of scope.
        </p>
      </div>
      <Button @click="showCreateDialog = true">
        <Plus class="mr-2 h-4 w-4" />
        New token
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
      v-else-if="tokens.length === 0"
      class="rounded-lg border border-dashed border-border p-12 text-center"
    >
      <KeyRound class="mx-auto mb-3 h-10 w-10 text-muted-foreground/50" />
      <h3 class="text-sm font-medium text-foreground">No API tokens</h3>
      <p class="mt-1 text-sm text-muted-foreground">Create a token to enable programmatic API access.</p>
      <Button class="mt-4" @click="showCreateDialog = true">
        <Plus class="mr-2 h-4 w-4" />
        Create first token
      </Button>
    </div>

    <!-- Token table -->
    <div v-else class="rounded-md border border-border overflow-hidden">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-border bg-muted/50">
            <th class="px-4 py-2.5 text-left font-medium text-muted-foreground">Name</th>
            <th class="px-4 py-2.5 text-left font-medium text-muted-foreground">Scope</th>
            <th class="px-4 py-2.5 text-left font-medium text-muted-foreground">Hint</th>
            <th class="px-4 py-2.5 text-left font-medium text-muted-foreground">Created</th>
            <th class="px-4 py-2.5 text-left font-medium text-muted-foreground">Expires</th>
            <th class="px-4 py-2.5 text-left font-medium text-muted-foreground">Last used</th>
            <th class="px-4 py-2.5 text-right font-medium text-muted-foreground">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="token in tokens"
            :key="token.id"
            class="border-b border-border last:border-0 hover:bg-muted/30 transition-colors"
            :class="{ 'opacity-60': isExpired(token) }"
          >
            <td class="px-4 py-3 font-medium text-foreground">{{ token.name }}</td>
            <td class="px-4 py-3">
              <Badge :variant="scopeBadgeVariant(token.scope)" class="capitalize">
                {{ token.scope }}
              </Badge>
            </td>
            <td class="px-4 py-3 font-mono text-muted-foreground">{{ token.token_hint }}…</td>
            <td class="px-4 py-3 text-muted-foreground">{{ formatDate(token.created_at) }}</td>
            <td class="px-4 py-3 text-muted-foreground">
              <span v-if="isExpired(token)" class="flex items-center gap-1.5">
                <Badge variant="destructive">Expired</Badge>
                <span class="text-xs">{{ formatDate(token.expires_at) }}</span>
              </span>
              <span v-else>{{ formatDate(token.expires_at) }}</span>
            </td>
            <td class="px-4 py-3 text-muted-foreground">{{ formatDate(token.last_used_at) }}</td>
            <td class="px-4 py-3">
              <div class="flex items-center justify-end gap-2">
                <Button
                  variant="ghost"
                  size="sm"
                  @click="openRotate(token)"
                  title="Rotate token"
                >
                  <RefreshCw class="h-4 w-4" />
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  @click="openChangeExpiry(token)"
                  title="Change expiry"
                >
                  <CalendarClock class="h-4 w-4" />
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  @click="openDelete(token)"
                  title="Delete token"
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

    <!-- Scope reference -->
    <div class="rounded-md border border-border bg-muted/30 p-4 text-sm space-y-2">
      <p class="font-medium text-foreground">Scope reference</p>
      <ul class="space-y-1 text-muted-foreground">
        <li><Badge variant="secondary" class="mr-2">read</Badge>GET webhooks, logs, queue, health, triggers, schemas</li>
        <li><Badge variant="warning" class="mr-2">operational</Badge>Read + toggle webhooks, retry/replay logs, execute/retry queue jobs</li>
        <li><Badge variant="destructive" class="mr-2">full</Badge>Operational + create/update/delete webhooks, schemas, and queue jobs</li>
      </ul>
    </div>

    <!-- Dialogs -->
    <CreateTokenDialog
      :open="showCreateDialog"
      @close="showCreateDialog = false"
      @created="handleCreate"
    />

    <TokenCreatedDialog
      :open="showCreatedDialog"
      :token="plaintextToken"
      @close="handleCreatedClose"
    />

    <RotateTokenDialog
      :open="showRotateDialog"
      :token="tokenToRotate"
      @close="showRotateDialog = false; tokenToRotate = null"
      @rotate="handleRotate"
    />

    <ChangeExpiryDialog
      :open="showChangeExpiryDialog"
      :token="tokenToChangeExpiry"
      @close="showChangeExpiryDialog = false; tokenToChangeExpiry = null"
      @updated="handleChangeExpiry"
    />

    <!-- Delete confirm dialog -->
    <Dialog
      :open="showDeleteDialog"
      :title="`Delete token: ${tokenToDelete?.name}`"
      description="This will permanently revoke the token. Any integrations using it will stop working immediately."
      @close="showDeleteDialog = false; tokenToDelete = null"
    >
      <template #footer>
        <Button variant="outline" @click="showDeleteDialog = false; tokenToDelete = null" :disabled="deleting">
          Cancel
        </Button>
        <Button variant="destructive" @click="handleDelete" :disabled="deleting">
          {{ deleting ? 'Deleting…' : 'Delete token' }}
        </Button>
      </template>
    </Dialog>
  </div>
</template>
