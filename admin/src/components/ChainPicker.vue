<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { Input, Label, Select, SelectTrigger, SelectValue, SelectContent, SelectItem, Button, Checkbox } from '@/components/ui';
import { Plus, X } from 'lucide-vue-next';
import api from '@/lib/api';
import { useChains } from '@/composables/useChains';
import { __, sprintf } from '@/i18n';

const props = defineProps({
  modelValue: {
    type: Object,
    default: () => ({ chain_id: null, new_chain_name: '', source_webhook_ids: [] }),
  },
  currentWebhookId: { type: [Number, String, null], default: null },
});

const emit = defineEmits(['update:modelValue']);

const { chains, fetchChains } = useChains();

const webhooks = ref([]);
const loadingWebhooks = ref(false);

const fetchWebhooks = async () => {
  loadingWebhooks.value = true;
  try {
    const result = await api.webhooks.list();
    webhooks.value = Array.isArray(result) ? result : (result.items || []);
  } catch (e) {
    webhooks.value = [];
  } finally {
    loadingWebhooks.value = false;
  }
};

onMounted(() => {
  fetchChains();
  fetchWebhooks();
});

const NEW_CHAIN_VALUE = '__new__';

const chainSelectValue = computed({
  get() {
    if (props.modelValue.chain_id != null) {
      return String(props.modelValue.chain_id);
    }
    return NEW_CHAIN_VALUE;
  },
  set(val) {
    if (val === NEW_CHAIN_VALUE) {
      emit('update:modelValue', { ...props.modelValue, chain_id: null });
    } else {
      emit('update:modelValue', {
        ...props.modelValue,
        chain_id: Number(val),
        new_chain_name: '',
      });
    }
  },
});

const updateNewChainName = (e) => {
  emit('update:modelValue', { ...props.modelValue, new_chain_name: e.target.value });
};

const currentId = computed(() => {
  const id = props.currentWebhookId;
  return id != null ? Number(id) : null;
});

const selectableSources = computed(() => {
  const id = currentId.value;
  return (webhooks.value || []).filter((w) => Number(w.id) !== id);
});

const sourceSearch = ref('');
const filteredSources = computed(() => {
  const q = sourceSearch.value.trim().toLowerCase();
  if (!q) return selectableSources.value;
  return selectableSources.value.filter((w) => {
    const name = String(w.name || '').toLowerCase();
    const url = String(w.endpoint_url || '').toLowerCase();
    return name.includes(q) || url.includes(q);
  });
});

const sourceIdsSet = computed(() => new Set((props.modelValue.source_webhook_ids || []).map(Number)));

const toggleSource = (webhookId) => {
  const ids = new Set(sourceIdsSet.value);
  const wid = Number(webhookId);
  if (ids.has(wid)) {
    ids.delete(wid);
  } else {
    ids.add(wid);
  }
  emit('update:modelValue', { ...props.modelValue, source_webhook_ids: Array.from(ids) });
};

const isSelected = (webhookId) => sourceIdsSet.value.has(Number(webhookId));

const isCreatingNew = computed(() => props.modelValue.chain_id == null);
</script>

<template>
  <div class="space-y-4">
    <div class="space-y-2">
      <Label>{{ __('Chain') }}</Label>
      <Select v-model="chainSelectValue">
        <SelectTrigger>
          <SelectValue :placeholder="__('Select chain or create new')" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem v-for="c in chains" :key="c.id" :value="String(c.id)">
            {{ c.name }}
          </SelectItem>
          <SelectItem :value="NEW_CHAIN_VALUE">
            <span class="inline-flex items-center gap-1">
              <Plus class="h-3.5 w-3.5" />
              {{ __('New chain…') }}
            </span>
          </SelectItem>
        </SelectContent>
      </Select>

      <div v-if="isCreatingNew" class="space-y-2 pt-2">
        <Label for="new-chain-name" class="text-xs text-muted-foreground">{{ __('New chain name') }}</Label>
        <Input
          id="new-chain-name"
          :value="modelValue.new_chain_name"
          @input="updateNewChainName"
          :placeholder="__('e.g. Order to HubSpot')"
        />
      </div>
    </div>

    <div class="space-y-2 border-t pt-4">
      <Label>{{ __('Trigger this webhook when the following webhooks complete') }}</Label>
      <p class="text-sm text-muted-foreground" v-html="sprintf(__('On a successful (2xx) response from any of these webhooks, this webhook fires with the upstream response, sent payload, and original pre-mapping payload available as %1$sargs[0]%2$s.'), '<code class=&quot;font-mono text-xs&quot;>', '</code>')">
      </p>

      <div v-if="loadingWebhooks" class="text-sm text-muted-foreground py-2">
        {{ __('Loading webhooks…') }}
      </div>
      <div v-else-if="selectableSources.length === 0" class="text-sm text-muted-foreground py-2">
        {{ __('No other webhooks exist yet — create at least one webhook to use it as a chain source.') }}
      </div>
      <template v-else>
        <Input
          v-model="sourceSearch"
          :placeholder="__('Search webhooks by name or URL…')"
          class="text-sm"
        />
        <div class="space-y-1.5 max-h-64 overflow-y-auto rounded-md border p-2">
          <label
            v-for="w in filteredSources"
            :key="w.id"
            class="flex items-center gap-2 cursor-pointer rounded px-2 py-1.5 hover:bg-muted/50"
          >
            <Checkbox :model-value="isSelected(w.id)" @update:model-value="toggleSource(w.id)" />
            <span class="text-sm font-medium">{{ w.name }}</span>
            <span class="text-xs text-muted-foreground truncate">{{ w.endpoint_url }}</span>
          </label>
          <div v-if="filteredSources.length === 0" class="text-sm text-muted-foreground py-2 px-2">
            {{ sprintf(__('No webhooks match “%s”.'), sourceSearch) }}
          </div>
        </div>
      </template>
    </div>
  </div>
</template>
