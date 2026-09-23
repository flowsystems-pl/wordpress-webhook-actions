<script setup>
import { ref, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { BellRing } from 'lucide-vue-next'
import ChannelsPanel from '@/components/notifications/ChannelsPanel.vue'
import RulesPanel from '@/components/notifications/RulesPanel.vue'
import NotificationLogTable from '@/components/notifications/NotificationLogTable.vue'
import api from '@/lib/api'
import { __ } from '@/i18n'

const route = useRoute()
const router = useRouter()

const tabs = [
  { key: 'rules', label: __('Rules') },
  { key: 'channels', label: __('Channels') },
  { key: 'sent', label: __('Sent') },
]
const TABS = ['rules', 'channels', 'sent']
const tab = ref(TABS.includes(route.query.tab) ? route.query.tab : 'rules')
const setTab = (key) => {
  tab.value = key
  router.replace({ query: { ...route.query, tab: key } })
}
// Links elsewhere in the app point at ?tab=channels / ?tab=sent; when the
// view is already mounted only the query changes, so follow it.
watch(() => route.query.tab, (next) => {
  if (TABS.includes(next) && next !== tab.value) tab.value = next
})

// "Draft with AI" is only offered when some provider is reachable.
const aiAvailable = ref(true)
onMounted(async () => {
  try {
    const status = await api.agent.status()
    aiAvailable.value = status?.configured !== false
  } catch (e) {
    aiAvailable.value = true
  }
})
</script>

<template>
  <div class="space-y-6">
    <div>
      <h2 class="text-xl font-semibold text-foreground flex items-center gap-2">
        <BellRing class="h-5 w-5" />
        {{ __('Notifications') }}
      </h2>
      <p class="text-sm text-muted-foreground mt-1 max-w-3xl">
        {{ __('Get told when a delivery fails, retries, gives up or comes back — by email, Slack, Discord, Telegram, Teams, SMS, PagerDuty and more. Rules decide when and where; channels are the destinations; every message is a template you can edit or let the AI draft.') }}
      </p>
    </div>

    <div class="border-b border-border">
      <nav class="-mb-px flex gap-4" aria-label="Tabs">
        <button
          v-for="t in tabs"
          :key="t.key"
          type="button"
          class="border-b-2 px-1 pb-2 text-sm font-medium transition-colors"
          :class="tab === t.key ? 'border-primary text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground'"
          @click="setTab(t.key)"
        >{{ t.label }}</button>
      </nav>
    </div>

    <RulesPanel v-if="tab === 'rules'" :ai-available="aiAvailable" />
    <ChannelsPanel v-else-if="tab === 'channels'" />
    <NotificationLogTable v-else />
  </div>
</template>
