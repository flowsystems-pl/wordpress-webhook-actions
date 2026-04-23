<script setup>
import { ref, computed, onMounted } from 'vue'
import { CheckCircle2, Check, XCircle, Sparkles, ExternalLink, Loader2 } from 'lucide-vue-next'
import { Button, Card, Input, Label, Badge, Alert, Dialog } from '@/components/ui'
import api from '@/lib/api'
import { usePro } from '@/composables/usePro'

const { refresh: refreshPro } = usePro()

const loading = ref(true)
const error = ref(null)
const state = ref(null)
const license = ref(null)

const licenseKey = ref('')
const activating = ref(false)
const activateError = ref(null)
const activatedSites = ref([])

const activatingPlugin = ref(false)
const activatePluginError = ref(null)

const activatePlugin = async () => {
  activatingPlugin.value = true
  activatePluginError.value = null
  try {
    await api.pro.activatePlugin()
    await loadStatus()
  } catch (e) {
    activatePluginError.value = e.message
  } finally {
    activatingPlugin.value = false
  }
}

const deactivating = ref(false)
const showDeactivateDialog = ref(false)

const planLabel = computed(() => {
  const map = { starter: 'Starter', business: 'Business', agency: 'Agency' }
  return map[license.value?.plan] ?? license.value?.plan ?? '—'
})

const expiresLabel = computed(() => {
  if (!license.value?.expires_at) return null
  return new Date(license.value.expires_at).toLocaleDateString(undefined, {
    year: 'numeric', month: 'long', day: 'numeric',
  })
})

const loadStatus = async () => {
  loading.value = true
  error.value = null
  try {
    const data = await api.pro.status()
    state.value = data.state
    license.value = data.license
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

const activate = async () => {
  if (!licenseKey.value.trim()) return
  activating.value = true
  activateError.value = null
  activatedSites.value = []
  try {
    const data = await api.pro.activate(licenseKey.value.trim())
    state.value = 'active'
    license.value = data.data
    licenseKey.value = ''
    refreshPro()
  } catch (e) {
    activateError.value = e.message
    activatedSites.value = e.data?.activated_sites ?? []
  } finally {
    activating.value = false
  }
}

const deactivate = async () => {
  showDeactivateDialog.value = false
  deactivating.value = true
  try {
    await api.pro.deactivate()
    state.value = 'activate'
    license.value = null
    refreshPro()
  } catch (e) {
    error.value = e.message
  } finally {
    deactivating.value = false
  }
}

onMounted(loadStatus)
</script>

<template>
  <div class="max-w-2xl">

    <!-- Loading -->
    <div v-if="loading" class="flex items-center gap-2 text-muted-foreground py-8">
      <Loader2 class="w-4 h-4 animate-spin" />
      <span class="text-sm">Loading...</span>
    </div>

    <!-- Top-level error -->
    <Alert v-else-if="error" variant="destructive" class="mb-4">{{ error }}</Alert>

    <!-- STATE 1: Upsell -->
    <template v-else-if="state === 'upsell'">
      <div class="mb-6">
        <h2 class="text-xl font-semibold text-foreground">Upgrade to Pro</h2>
        <p class="text-sm text-muted-foreground mt-1">
          More sites, advanced conditions, and priority support.
        </p>
      </div>

      <Card class="p-6 mb-6">
        <ul class="space-y-3">
          <li v-for="feature in [
            { text: 'Reliable webhooks with queue & retries', pro: false },
            { text: 'Delivery logs & replay', pro: false },
            { text: '1 site included', pro: false },
            { text: 'Up to 10 sites (Business) or 75 sites (Agency)', pro: true },
            { text: 'Unlimited conditions per trigger', pro: true },
            { text: 'Condition groups with AND / OR logic', pro: true },
            { text: 'Priority support', pro: true },
          ]" :key="feature.text" class="flex items-start gap-3 text-sm">
            <CheckCircle2 v-if="feature.pro" class="w-4 h-4 mt-0.5 shrink-0 text-primary" />
            <Check v-else class="w-4 h-4 mt-0.5 shrink-0 text-muted-foreground/50" />
            <span :class="feature.pro ? 'text-foreground font-medium' : 'text-muted-foreground'">
              {{ feature.text }}
              <Badge v-if="feature.pro" class="ml-2 text-[10px] py-0 px-1.5 align-middle">Pro</Badge>
            </span>
          </li>
        </ul>
      </Card>

      <a href="https://wpwebhooks.org/#pricing" target="_blank" rel="noopener noreferrer">
        <Button class="gap-2">
          <Sparkles class="w-4 h-4" />
          Get Pro
          <ExternalLink class="w-3.5 h-3.5 opacity-70" />
        </Button>
      </a>
    </template>

    <!-- STATE 1b: Installed but WP-deactivated -->
    <template v-else-if="state === 'inactive'">
      <div class="mb-6">
        <h2 class="text-xl font-semibold text-foreground">Pro Plugin Installed</h2>
        <p class="text-sm text-muted-foreground mt-1">
          The Pro plugin is installed but not activated in WordPress.
        </p>
      </div>

      <Card class="p-6">
        <Alert v-if="activatePluginError" variant="destructive" class="mb-4">{{ activatePluginError }}</Alert>
        <p class="text-sm text-muted-foreground mb-4">
          Activate <strong class="text-foreground">Flow Systems Webhook Actions Pro</strong> to continue.
        </p>
        <Button :loading="activatingPlugin" :disabled="activatingPlugin" @click="activatePlugin" class="gap-2">
          <Sparkles class="w-4 h-4" />
          {{ activatingPlugin ? 'Activating…' : 'Activate Plugin' }}
        </Button>
      </Card>
    </template>

    <!-- STATE 2: Activate -->
    <template v-else-if="state === 'activate'">
      <div class="mb-6">
        <h2 class="text-xl font-semibold text-foreground">Activate License</h2>
        <p class="text-sm text-muted-foreground mt-1">
          Enter your license key to unlock Pro features on this site.
        </p>
      </div>

      <Card class="p-6">
        <Alert v-if="activateError" variant="destructive" class="mb-4">
          {{ activateError }}
          <ul v-if="activatedSites.length" class="mt-2 space-y-1 text-xs opacity-80">
            <li v-for="site in activatedSites" :key="site" class="font-mono">{{ site }}</li>
          </ul>
        </Alert>

        <div class="space-y-4">
          <div class="space-y-1.5">
            <Label for="license-key">License Key</Label>
            <Input
              id="license-key"
              v-model="licenseKey"
              placeholder="XXXXX-XXXXX-XXXXX-XXXXX"
              class="font-mono"
              @keyup.enter="activate"
            />
          </div>

          <Button @click="activate" :loading="activating" :disabled="!licenseKey.trim() || activating">
            {{ activating ? 'Activating…' : 'Activate License' }}
          </Button>
        </div>

        <div class="mt-4 space-y-1.5">
          <p class="text-xs text-muted-foreground">
            Don't have a license yet?
            <a href="https://wpwebhooks.org/#pricing" target="_blank" rel="noopener noreferrer"
               class="underline underline-offset-2 hover:text-foreground transition-colors">
              Get Pro
            </a>
          </p>
          <p class="text-xs text-muted-foreground">
            💡 Local installs (<span class="font-mono">localhost</span>, <span class="font-mono">*.local</span>, <span class="font-mono">*.test</span>) don't count toward your site limit.
          </p>
        </div>
      </Card>
    </template>

    <!-- STATE 3: Active -->
    <template v-else-if="state === 'active'">
      <div class="mb-6">
        <h2 class="text-xl font-semibold text-foreground">Pro License</h2>
        <p class="text-sm text-muted-foreground mt-1">
          Your license is active on this site.
        </p>
      </div>

      <Card class="p-6 mb-4">
        <div class="space-y-4">
          <div class="flex items-center gap-2">
            <CheckCircle2 class="w-5 h-5 text-primary shrink-0" />
            <span class="font-medium text-foreground">Active</span>
            <Badge class="ml-1">{{ planLabel }}</Badge>
          </div>

          <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
            <div>
              <dt class="text-muted-foreground">Sites used</dt>
              <dd class="font-medium text-foreground mt-0.5">
                {{ license?.activations_used ?? '—' }} / {{ license?.sites_allowed ?? '—' }}
                <span v-if="license?.is_local" class="ml-1 text-xs text-muted-foreground">(local, not counted)</span>
              </dd>
            </div>
            <div>
              <dt class="text-muted-foreground">Sites allowed</dt>
              <dd class="font-medium text-foreground mt-0.5">{{ license?.sites_allowed ?? '—' }}</dd>
            </div>
            <div v-if="expiresLabel">
              <dt class="text-muted-foreground">Renews / expires</dt>
              <dd class="font-medium text-foreground mt-0.5">{{ expiresLabel }}</dd>
            </div>
          </dl>
          <p class="text-xs text-muted-foreground pt-1">
            💡 Local installs (<span class="font-mono">localhost</span>, <span class="font-mono">*.local</span>, <span class="font-mono">*.test</span>) don't count toward your site limit.
          </p>
        </div>
      </Card>

      <div class="flex flex-wrap gap-3">
        <a href="https://wpwebhooks.org/account" target="_blank" rel="noopener noreferrer">
          <Button variant="outline" class="gap-2">
            Manage Subscription
            <ExternalLink class="w-3.5 h-3.5 opacity-70" />
          </Button>
        </a>

        <Button
          variant="destructive"
          :loading="deactivating"
          :disabled="deactivating"
          @click="showDeactivateDialog = true"
        >
          {{ deactivating ? 'Deactivating…' : 'Deactivate License' }}
        </Button>
      </div>

      <Dialog
        :open="showDeactivateDialog"
        title="Deactivate license?"
        description="This will remove the license from this site and free up a slot. You can reactivate at any time."
        @close="showDeactivateDialog = false"
      >
        <template #footer>
          <Button variant="outline" @click="showDeactivateDialog = false">Cancel</Button>
          <Button variant="destructive" :loading="deactivating" @click="deactivate">Deactivate</Button>
        </template>
      </Dialog>
    </template>

  </div>
</template>
