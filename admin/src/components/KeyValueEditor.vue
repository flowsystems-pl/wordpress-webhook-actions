<script setup>
import { computed } from 'vue';
import { Button, Input } from '@/components/ui';
import { X, Plus, AlertTriangle } from 'lucide-vue-next';
import { __ } from '@/i18n';

const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  examplePayload: { type: Object, default: null },
  gluePayload: { type: Object, default: null },
  keyPlaceholder: { type: String, default: () => __('Key') },
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

// Only something shaped like a payload path gets checked against the capture:
// identifier segments joined by dots (`args.0.form_id`). A static value that
// merely contains a dot — `application/vnd.github+json`, `1.0`, an email — is
// sent as text by the dispatcher and must not be flagged as a missing path.
const isDotPath = (val) => typeof val === 'string' && /^[A-Za-z0-9_$-]+(\.[A-Za-z0-9_$-]+)+$/.test(val);

const resolveByPath = (obj, path) => {
  if (!obj || !path) return undefined;
  return path.split('.').reduce(
    (acc, key) => (acc != null && typeof acc === 'object' ? acc[key] : undefined),
    obj
  );
};

const isPathMissing = (val) => {
  if (!isDotPath(val)) return false;
  if (props.gluePayload) {
    const r = resolveByPath(props.gluePayload, val);
    if (r !== undefined && r !== null) return false;
  }
  if (props.examplePayload) {
    const r = resolveByPath(props.examplePayload, val);
    if (r !== undefined && r !== null) return false;
  }
  return !!(props.gluePayload || props.examplePayload);
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
          :title="__('Key must only contain letters, numbers, hyphens, or underscores')"
        />
      </div>
      <div class="relative flex-[2]">
        <Input
          :value="row.value"
          :placeholder="__('Dot-path (e.g. event.id) or static text')"
          :class="isPathMissing(row.value) ? 'pr-7 !border-orange-500' : ''"
          @input="update(i, 'value', $event.target.value)"
        />
        <AlertTriangle
          v-if="isPathMissing(row.value)"
          class="absolute right-2 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-orange-500"
          :title="__('Path not found in captured payload — this value will be sent as static text')"
        />
      </div>
      <Button type="button" variant="ghost" size="icon" class="shrink-0" @click="remove(i)">
        <X class="h-4 w-4" />
      </Button>
    </div>
    <Button type="button" variant="outline" size="sm" class="gap-1" @click="add">
      <Plus class="h-3.5 w-3.5" />
      {{ __('Add row') }}
    </Button>
  </div>
</template>
