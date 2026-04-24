<script setup>
import { ref, onMounted, computed } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { ArrowLeft, FlaskConical } from 'lucide-vue-next';
import { Button, Card, Alert } from '@/components/ui';
import WebhookForm from '@/components/WebhookForm.vue';
import TriggerSchemaPanel from '@/components/TriggerSchemaPanel.vue';
import TestWebhookDrawer from '@/components/TestWebhookDrawer.vue';
import api from '@/lib/api';
import { useHealthStats } from '@/composables/useHealthStats';

const { fetchStats: refreshHealthStats } = useHealthStats();

const router = useRouter();
const route = useRoute();

const webhook = ref(null);
const loading = ref(false);
const saving = ref(false);
const error = ref(null);
const showTest = ref(false);
const hasUnsavedChanges = ref(false);

const isEdit = computed(() => !!route.params.id);
const pageTitle = computed(() =>
  isEdit.value ? 'Edit Webhook' : 'Create Webhook',
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

const handleSubmit = async (data) => {
  saving.value = true;
  error.value = null;

  try {
    if (isEdit.value) {
      await api.webhooks.update(route.params.id, data);
      hasUnsavedChanges.value = false;
      // Silently reload webhook to refresh triggers for TriggerSchemaPanel
      await loadWebhook(true);
    } else {
      await api.webhooks.create(data);
      router.push('/webhooks');
    }
    refreshHealthStats();
  } catch (e) {
    error.value = e.message;
    console.error('Failed to save webhook:', e);
  } finally {
    saving.value = false;
  }
};

const handleCancel = () => {
  router.push('/webhooks');
};

onMounted(loadWebhook);
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
        Back to webhooks
      </Button>
      <h2 class="text-xl font-semibold">{{ pageTitle }}</h2>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="text-center py-8 text-muted-foreground">
      Loading...
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
            Test
          </Button>
        </div>

        <Alert v-if="error" variant="destructive" class="mb-4">
          {{ error }}
        </Alert>

        <WebhookForm
          :webhook="webhook"
          :loading="saving"
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
        />
      </Card>
    </div>
  </div>
</template>
