<script setup>
import { ref, computed, watch } from 'vue';
import { Button, Input, Label, Switch } from '@/components/ui';
import TriggerSelect from '@/components/TriggerSelect.vue';

const props = defineProps({
  webhook: {
    type: Object,
    default: null,
  },
  loading: Boolean,
});

const emit = defineEmits(['submit', 'cancel']);

const form = ref({
  name: '',
  endpoint_url: '',
  auth_header: '',
  is_enabled: true,
  triggers: [],
});

const errors = ref({});

// Initialize form with webhook data
watch(
  () => props.webhook,
  (webhook) => {
    if (webhook) {
      form.value = {
        name: webhook.name || '',
        endpoint_url: webhook.endpoint_url || '',
        auth_header: webhook.auth_header || '',
        is_enabled: webhook.is_enabled ?? true,
        triggers: webhook.triggers || [],
      };
    }
  },
  { immediate: true },
);

const validate = () => {
  errors.value = {};

  if (!form.value.name.trim()) {
    errors.value.name = 'Name is required';
  }

  if (!form.value.endpoint_url.trim()) {
    errors.value.endpoint_url = 'Endpoint URL is required';
  } else {
    try {
      const url = new URL(form.value.endpoint_url);
      if (!['http:', 'https:'].includes(url.protocol)) {
        errors.value.endpoint_url = 'URL must be HTTP or HTTPS';
      }
    } catch {
      errors.value.endpoint_url = 'Invalid URL format';
    }
  }

  if (form.value.triggers.length === 0) {
    errors.value.triggers = 'At least one trigger is required';
  }

  return Object.keys(errors.value).length === 0;
};

const handleSubmit = () => {
  if (validate()) {
    emit('submit', { ...form.value });
  }
};
</script>

<template>
  <form class="space-y-6" @submit.prevent="handleSubmit">
    <!-- Name -->
    <div class="space-y-2">
      <Label for="name">Name</Label>
      <Input
        id="name"
        v-model="form.name"
        placeholder="My Webhook"
        :class="{ 'border-destructive': errors.name }"
      />
      <p v-if="errors.name" class="text-sm text-destructive">
        {{ errors.name }}
      </p>
    </div>

    <!-- Endpoint URL -->
    <div class="space-y-2">
      <Label for="endpoint_url">Endpoint URL</Label>
      <Input
        id="endpoint_url"
        v-model="form.endpoint_url"
        type="url"
        placeholder="https://example.com/webhook"
        :class="{ 'border-destructive': errors.endpoint_url }"
      />
      <p v-if="errors.endpoint_url" class="text-sm text-destructive">
        {{ errors.endpoint_url }}
      </p>
      <p class="text-sm text-muted-foreground">
        The URL where webhook payloads will be sent
      </p>
    </div>

    <!-- Auth Header -->
    <div class="space-y-2">
      <Label for="auth_header">Authorization Header (optional)</Label>
      <Input
        id="auth_header"
        v-model="form.auth_header"
        placeholder="Bearer your_token_goes_here"
      />
      <p class="text-sm text-muted-foreground break-all md:break-normal">
        Value for the Authorization header (e.g., "Bearer your_token_goes_here"
        or "Basic your_encoded_base64(username:password)")
      </p>
    </div>

    <!-- Triggers -->
    <div class="space-y-2">
      <Label>Triggers</Label>
      <TriggerSelect v-model="form.triggers" />
      <p v-if="errors.triggers" class="text-sm text-destructive">
        {{ errors.triggers }}
      </p>
      <p class="text-sm text-muted-foreground">
        WordPress actions that will trigger this webhook
      </p>
    </div>

    <!-- Enabled -->
    <div class="flex items-center space-x-2">
      <Switch v-model="form.is_enabled" />
      <Label>Enabled</Label>
    </div>

    <!-- Actions -->
    <div class="flex gap-2 pt-4">
      <Button type="submit" :loading="loading">
        {{ webhook ? 'Save Changes' : 'Create Webhook' }}
      </Button>
      <Button type="button" variant="outline" @click="$emit('cancel')">
        Cancel
      </Button>
    </div>
  </form>
</template>
