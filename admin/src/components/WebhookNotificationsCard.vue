<script setup>
import { ref, computed, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { BellRing, Info } from 'lucide-vue-next'
import { Label, RadioGroup, RadioGroupItem, Tooltip } from '@/components/ui'
import RulesPanel from '@/components/notifications/RulesPanel.vue'
import { useNotifications } from '@/composables/useNotifications'
import { __ } from '@/i18n'

// The "Notifications" section of the webhook form. Mode and muted ids are
// part of the webhook row (saved with the form); the webhook's own rules
// are saved live through the rules endpoint, which needs a webhook id — so
// the custom list only appears once the webhook exists.
const props = defineProps({
  webhookId: { type: Number, default: null },
  mode: { type: String, default: 'inherit' },
  mutedRuleIds: { type: Array, default: () => [] },
})
const emit = defineEmits(['update:mode', 'update:mutedRuleIds'])

const { channels, load } = useNotifications()
const globalCount = ref(null)
onMounted(() => { load().catch(() => {}) })

const modeProxy = computed({
  get: () => props.mode,
  set: (v) => emit('update:mode', v),
})
const hasChannels = computed(() => channels.value.length > 0)
</script>

<template>
  <div class="space-y-3 border-t pt-5">
    <div class="flex items-center gap-2">
      <BellRing class="h-4 w-4 text-muted-foreground" />
      <Label>{{ __('Notifications') }}</Label>
      <Tooltip :content="__('Who gets told when this webhook fails, retries, gives up or recovers. Site-wide rules live under Notifications; here you can inherit them, mute some, or define rules just for this webhook.')" side="right" max-width="300px">
        <Info class="h-3.5 w-3.5 text-muted-foreground cursor-help shrink-0" />
      </Tooltip>
    </div>

    <RadioGroup v-model="modeProxy" class="grid gap-2">
      <label class="flex items-start gap-2 rounded-md border border-border p-3 cursor-pointer hover:bg-muted/40" :class="mode === 'inherit' ? 'border-primary bg-primary/5' : ''">
        <RadioGroupItem id="notif-inherit" value="inherit" class="mt-0.5" />
        <span>
          <span class="block text-sm font-medium">{{ __('Inherit site-wide rules') }}</span>
          <span class="block text-xs text-muted-foreground">{{ __('Plus any rules added below. Mute individual site-wide rules here.') }}</span>
        </span>
      </label>
      <label class="flex items-start gap-2 rounded-md border border-border p-3 cursor-pointer hover:bg-muted/40" :class="mode === 'custom' ? 'border-primary bg-primary/5' : ''">
        <RadioGroupItem id="notif-custom" value="custom" class="mt-0.5" />
        <span>
          <span class="block text-sm font-medium">{{ __('Only this webhook\'s rules') }}</span>
          <span class="block text-xs text-muted-foreground">{{ __('Ignore site-wide rules entirely.') }}</span>
        </span>
      </label>
      <label class="flex items-start gap-2 rounded-md border border-border p-3 cursor-pointer hover:bg-muted/40" :class="mode === 'off' ? 'border-primary bg-primary/5' : ''">
        <RadioGroupItem id="notif-off" value="off" class="mt-0.5" />
        <span>
          <span class="block text-sm font-medium">{{ __('Off') }}</span>
          <span class="block text-xs text-muted-foreground">{{ __('Never notify for this webhook.') }}</span>
        </span>
      </label>
    </RadioGroup>

    <template v-if="mode !== 'off'">
      <div v-if="!hasChannels" class="rounded-md border border-dashed border-border p-3 text-xs text-muted-foreground">
        {{ __('No channels exist yet.') }}
        <RouterLink to="/notifications?tab=channels" class="underline">{{ __('Add an email, Slack, Telegram or SMS channel') }}</RouterLink>
        {{ __('before creating rules.') }}
      </div>

      <div v-if="mode === 'inherit'" class="rounded-md border border-border p-3">
        <RulesPanel
          mute-mode
          :muted-ids="mutedRuleIds"
          @update:muted-ids="emit('update:mutedRuleIds', $event)"
          @count="globalCount = $event"
        />
      </div>

      <div v-if="webhookId" class="rounded-md border border-border p-3">
        <RulesPanel :webhook-id="webhookId" />
      </div>
      <div v-else class="rounded-md border border-dashed border-border p-3 text-xs text-muted-foreground">
        {{ __('Save the webhook first to add rules that apply to it alone.') }}
      </div>
    </template>
  </div>
</template>
