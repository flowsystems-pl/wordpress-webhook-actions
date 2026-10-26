<script setup>
import { ref, computed, watch, nextTick } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { ArrowLeft, FlaskConical } from 'lucide-vue-next';
import { Button, Card, Alert } from '@/components/ui';
import WebhookForm from '@/components/WebhookForm.vue';
import TriggerSchemaPanel from '@/components/TriggerSchemaPanel.vue';
import TestWebhookDrawer from '@/components/TestWebhookDrawer.vue';
import api from '@/lib/api';
import { useHealthStats } from '@/composables/useHealthStats';
import { useSchemas } from '@/composables/useSchemas';
import { useChains } from '@/composables/useChains';
import { applyMappingTransform } from '@/utils/payloadTransform';
import { __ } from '@/i18n';

const { createChain, syncTargetSources, clearTargetLinks, refresh: refreshChains } = useChains();

const { fetchStats: refreshHealthStats } = useHealthStats();

const router = useRouter();
const route = useRoute();

const { schemas } = useSchemas(route.params.id);

// The first schema that has a captured example — the basis for the top-level
// form's URL / param / header template previews.
const firstSchemaWithExample = computed(() => {
  for (const schema of schemas.value) {
    if (!schema.example_payload) continue;
    const parsed = typeof schema.example_payload === 'string'
      ? JSON.parse(schema.example_payload)
      : schema.example_payload;
    if (parsed) return { schema, parsed };
  }
  return null;
});

const firstExamplePayload = computed(() => firstSchemaWithExample.value?.parsed ?? null);

// The post-mapping payload for that schema — mirrors PayloadTransformer::transform.
// URL templates resolve against the mapped (then glued) payload at dispatch, so
// this is the correct fallback when no pre-glue preview is active.
const firstMappedPayload = computed(() => {
  const entry = firstSchemaWithExample.value;
  if (!entry) return null;
  const mapping = entry.schema.field_mapping;
  if (!mapping) return entry.parsed;
  return applyMappingTransform(entry.parsed, {
    mappings: mapping.mappings || [],
    excluded: mapping.excluded || [],
    includeUnmapped: mapping.includeUnmapped !== false,
  });
});

const gluePreviewPayloads = ref({});
// The final on-the-wire payload: pre-glue preview result if present, else the
// mapped payload. Matches the payload URL templates resolve against at dispatch,
// with the raw example kept as the last-resort fallback (as expandUrlTemplates does).
const firstGluePayload = computed(() => {
  const vals = Object.values(gluePreviewPayloads.value);
  return vals.find(v => v != null) ?? firstMappedPayload.value ?? null;
});

const webhook = ref(null);
const loading = ref(false);
const saving = ref(false);
const error = ref(null);
const errorEl = ref(null);
const showTest = ref(false);
const hasUnsavedChanges = ref(false);

// Set to true right before we navigate from /webhooks/new → /webhooks/{id}
// after a Save & continue. The route watcher checks this so it doesn't
// re-fetch the webhook we already have in hand from the create response.
const skipNextRouteReload = ref(false);

const isEdit = computed(() => !!route.params.id);
const pageTitle = computed(() =>
  isEdit.value ? __('Edit Webhook') : __('Create Webhook'),
);

const loadWebhook = async (silent = false) => {
  if (!isEdit.value) return;

  if (!silent) {
    loading.value = true;
  }
  error.value = null;

  try {
    webhook.value = await api.webhooks.get(route.params.id);
  } catch (e) {
    error.value = e.message;
    console.error('Failed to load webhook:', e);
  } finally {
    if (!silent) {
      loading.value = false;
    }
  }
};

const resolveChainId = async (chainCfg) => {
  if (chainCfg.chain_id != null) return Number(chainCfg.chain_id);
  if (chainCfg.new_chain_name) {
    const created = await createChain({
      name: chainCfg.new_chain_name,
      description: chainCfg.new_chain_description || '',
    });
    return Number(created.id);
  }
  return null;
};

const handleSubmit = async (data) => {
  saving.value = true;
  error.value = null;

  const chainCfg = data.__chain_config || { enabled: false };
  const continueToChain = !!data.__continue_to_chain;
  delete data.__chain_config;
  delete data.__continue_to_chain;

  try {
    let webhookId;

    if (isEdit.value) {
      webhookId = Number(route.params.id);
      // Sync chain links BEFORE webhook update so the backend validation
      // never sees a triggerless state for a chain-mode webhook.
      if (chainCfg.enabled) {
        const chainId = await resolveChainId(chainCfg);
        if (chainId) {
          await syncTargetSources(chainId, webhookId, chainCfg.source_webhook_ids || []);
        }
      } else {
        await clearTargetLinks(webhookId);
      }

      await api.webhooks.update(webhookId, data);
      hasUnsavedChanges.value = false;
      // Silently reload webhook to refresh triggers for TriggerSchemaPanel
      await loadWebhook(true);
      await refreshChains();
    } else {
      // Create flow: save webhook first to obtain its ID. Chain mode cannot
      // be configured in the same call — the user is sent to the edit page
      // for the freshly created webhook with chain mode pre-enabled when
      // they clicked "Save & continue to chain setup".
      const created = await api.webhooks.create(data);
      webhookId = Number(created.id);
      hasUnsavedChanges.value = false;
      if (continueToChain) {
        // In-place transition: silently swap URL to /webhooks/{id}?chain=1
        // and hand the freshly-created record to the form. The route
        // watcher skips its reload since we already have the data.
        skipNextRouteReload.value = true;
        await router.replace({ path: `/webhooks/${webhookId}`, query: { chain: '1' } });
        webhook.value = created;
      } else {
        router.push('/webhooks');
      }
    }
    refreshHealthStats();
  } catch (e) {
    error.value = e.message;
    console.error('Failed to save webhook:', e);
    // The server's reason shows above the form, far from the Save button.
    nextTick(() => errorEl.value?.scrollIntoView({ behavior: 'smooth', block: 'center' }));
  } finally {
    saving.value = false;
  }
};

const handleCancel = () => {
  router.push('/webhooks');
};

// Re-load when the route ID changes. Vue Router reuses the same component
// instance across same-component routes, so onMounted alone won't fire on
// the second navigation. Save & continue sets skipNextRouteReload so the
// freshly-created webhook (already in `webhook.value`) is preserved across
// the silent URL swap and the user doesn't see a loading flicker.
watch(() => route.params.id, (newId) => {
  if (skipNextRouteReload.value) {
    skipNextRouteReload.value = false;
    return;
  }
  if (newId) {
    webhook.value = null;
    loadWebhook();
  } else {
    webhook.value = null;
  }
}, { immediate: true });
</script>

<template>
  <div>
    <!-- Test Drawer -->
    <TestWebhookDrawer
      v-if="isEdit && webhook"
      :open="showTest"
      :webhook="webhook"
      :has-unsaved-changes="hasUnsavedChanges"
      @close="showTest = false"
    />

    <!-- Header -->
    <div class="mb-6">
      <Button
        variant="ghost"
        size="sm"
        class="mb-2"
        @click="router.push('/webhooks')"
      >
        <ArrowLeft class="mr-2 h-4 w-4" />
        {{ __('Back to webhooks') }}
      </Button>
      <h2 class="text-xl font-semibold">{{ pageTitle }}</h2>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="text-center py-8 text-muted-foreground">
      {{ __('Loading…') }}
    </div>

    <!-- Error -->
    <Alert v-else-if="error && !webhook" variant="destructive" class="mb-4">
      {{ error }}
    </Alert>

    <!-- Form -->
    <div v-else class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <Card class="p-6">
        <div v-if="isEdit" class="flex justify-end mb-4">
          <Button variant="outline" size="sm" class="gap-1.5" @click="showTest = true">
            <FlaskConical class="h-3.5 w-3.5" />
            {{ __('Test') }}
          </Button>
        </div>

        <div v-if="error" ref="errorEl">
          <Alert variant="destructive" class="mb-4">
            {{ error }}
          </Alert>
        </div>

        <WebhookForm
          :webhook="webhook"
          :loading="saving"
          :examplePayload="firstExamplePayload"
          :gluePayload="firstGluePayload"
          :initialChainMode="route.query.chain === '1'"
          @submit="handleSubmit"
          @cancel="handleCancel"
          @change="hasUnsavedChanges = true"
        />
      </Card>

      <!-- Trigger Schema Panel (only in edit mode) -->
      <Card
        v-if="isEdit && webhook && webhook.triggers?.length > 0"
        class="p-6"
      >
        <TriggerSchemaPanel
          :webhookId="route.params.id"
          :triggers="webhook.triggers"
          @glue-preview-change="gluePreviewPayloads = $event"
        />
      </Card>
    </div>
  </div>
</template>
