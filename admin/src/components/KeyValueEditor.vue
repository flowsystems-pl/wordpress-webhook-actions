<script setup>
import { computed } from 'vue';
import { Button, Input } from '@/components/ui';
import { X, Plus, AlertTriangle } from 'lucide-vue-next';

const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  examplePayload: { type: Object, default: null },
  keyPlaceholder: { type: String, default: 'Key' },
});
const emit = defineEmits(['update:modelValue']);

const rows = computed(() => props.modelValue);

const add = () => emit('update:modelValue', [...props.modelValue, { key: '', value: '' }]);

const remove = (i) =>
  emit('update:modelValue', props.modelValue.filter((_, idx) => idx !== i));

const update = (i, field, val) => {
  const copy = props.modelValue.map((r, idx) => (idx === i ? { ...r, [field]: val } : r));
  emit('update:modelValue', copy);
};

const isDotPath = (val) => val && val.includes('.') && !/\s/.test(val);

const resolveByPath = (obj, path) => {
  if (!obj || !path) return undefined;
  return path.split('.').reduce(
    (acc, key) => (acc != null && typeof acc === 'object' ? acc[key] : undefined),
    obj
  );
};

const isPathMissing = (val) => {
  if (!props.examplePayload || !isDotPath(val)) return false;
  const resolved = resolveByPath(props.examplePayload, val);
  return resolved === undefined || resolved === null;
};

const isKeyInvalid = (key) => key && !/^[a-zA-Z0-9\-_]+$/.test(key);
</script>

<template>
  <div class="space-y-2">
    <div v-for="(row, i) in rows" :key="i" class="flex gap-2 items-center">
      <div class="relative flex-1">
        <Input
          :value="row.key"
          :placeholder="keyPlaceholder"
          :class="isKeyInvalid(row.key) ? 'pr-7 !border-orange-500' : ''"
          @input="update(i, 'key', $event.target.value)"
        />
        <AlertTriangle
          v-if="isKeyInvalid(row.key)"
          class="absolute right-2 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-orange-500"
          title="Key must only contain letters, numbers, hyphens, or underscores"
        />
      </div>
      <div class="relative flex-[2]">
        <Input
          :value="row.value"
          placeholder="Dot-path (e.g. event.id) or static text"
          :class="isPathMissing(row.value) ? 'pr-7 !border-orange-500' : ''"
          @input="update(i, 'value', $event.target.value)"
        />
        <AlertTriangle
          v-if="isPathMissing(row.value)"
          class="absolute right-2 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-orange-500"
          title="Path not found in captured payload"
        />
      </div>
      <Button type="button" variant="ghost" size="icon" class="shrink-0" @click="remove(i)">
        <X class="h-4 w-4" />
      </Button>
    </div>
    <Button type="button" variant="outline" size="sm" class="gap-1" @click="add">
      <Plus class="h-3.5 w-3.5" />
      Add row
    </Button>
  </div>
</template>
