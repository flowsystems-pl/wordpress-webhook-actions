<script setup>
import { computed } from 'vue';
import { Button, Input } from '@/components/ui';
import { X, Plus } from 'lucide-vue-next';

const props = defineProps({
  modelValue: { type: Array, default: () => [] },
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
</script>

<template>
  <div class="space-y-2">
    <div v-for="(row, i) in rows" :key="i" class="flex gap-2 items-center">
      <Input
        :value="row.key"
        placeholder="Header name"
        class="flex-1"
        @input="update(i, 'key', $event.target.value)"
      />
      <Input
        :value="row.value"
        placeholder="Dot-path (e.g. event.id) or static text"
        class="flex-[2]"
        @input="update(i, 'value', $event.target.value)"
      />
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
