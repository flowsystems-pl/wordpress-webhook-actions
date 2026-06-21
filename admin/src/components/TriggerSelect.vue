<script setup>
import { ref, computed, onMounted } from 'vue'
import { Check, ChevronsUpDown, X, Plus } from 'lucide-vue-next'
import { cn } from '@/lib/utils'
import { Badge, Button, Input } from '@/components/ui'
import api from '@/lib/api'
import { __, _n, sprintf } from '@/i18n'

const props = defineProps({
  modelValue: {
    type: Array,
    default: () => [],
  },
})

const emit = defineEmits(['update:modelValue'])

const open = ref(false)
const search = ref('')
const customTrigger = ref('')
const grouped = ref({})
const categories = ref({})
const loading = ref(true)

const selectedTriggers = computed({
  get: () => props.modelValue,
  set: (value) => emit('update:modelValue', value),
})

const allKnownNames = computed(() => new Set(Object.values(grouped.value).flat()))
const totalCount = computed(() => allKnownNames.value.size)

const formatLabel = (name) =>
  name.replace(/[_-]/g, ' ').replace(/^\w/, (c) => c.toUpperCase())

const filteredGrouped = computed(() => {
  if (!search.value) return grouped.value

  const searchLower = search.value.toLowerCase()
  const filtered = {}

  Object.entries(grouped.value).forEach(([category, names]) => {
    const matched = names.filter((name) => name.toLowerCase().includes(searchLower))
    if (matched.length > 0) filtered[category] = matched
  })

  return filtered
})

const toggleTrigger = (name) => {
  const index = selectedTriggers.value.indexOf(name)
  if (index === -1) {
    selectedTriggers.value = [...selectedTriggers.value, name]
  } else {
    selectedTriggers.value = selectedTriggers.value.filter((t) => t !== name)
  }
}

const removeTrigger = (name) => {
  selectedTriggers.value = selectedTriggers.value.filter((t) => t !== name)
}

const addCustomTrigger = () => {
  const name = customTrigger.value.trim()
  if (name && !selectedTriggers.value.includes(name)) {
    selectedTriggers.value = [...selectedTriggers.value, name]
    customTrigger.value = ''
  }
}

const searchFocused = ref(false)

const isSelected = (name) => selectedTriggers.value.includes(name)

const isCustomTrigger = (name) => !allKnownNames.value.has(name)

onMounted(async () => {
  try {
    const data = await api.triggers.list()
    grouped.value = data.grouped || {}
    categories.value = data.categories || {}
  } catch (error) {
    console.error('Failed to load triggers:', error)
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="space-y-2">
    <!-- Selected triggers -->
    <div v-if="selectedTriggers.length > 0" class="flex flex-wrap gap-1 mb-2">
      <Badge
        v-for="name in selectedTriggers"
        :key="name"
        :variant="isCustomTrigger(name) ? 'outline' : 'secondary'"
        class="cursor-pointer font-mono break-all sm:break-normal"
        @click="removeTrigger(name)"
      >
        <span v-if="isCustomTrigger(name)" class="text-primary mr-1">*</span>
        {{ name }}
        <X class="ml-1 h-3 w-3" />
      </Badge>
    </div>

    <!-- Custom trigger input -->
    <div class="flex gap-2">
      <Input
        v-model="customTrigger"
        type="text"
        :placeholder="__('Enter custom hook name...')"
        class="flex-1"
        @keyup.enter="addCustomTrigger"
      />
      <Button
        type="button"
        variant="outline"
        size="sm"
        :disabled="!customTrigger.trim()"
        @click="addCustomTrigger"
      >
        <Plus class="h-4 w-4 mr-1" />
        {{ __('Add') }}
      </Button>
    </div>

    <!-- Dropdown trigger -->
    <div class="relative">
      <Button
        type="button"
        variant="outline"
        role="combobox"
        :aria-expanded="open"
        class="w-full justify-between"
        @click="open = !open"
      >
        <span v-if="selectedTriggers.length === 0" class="text-muted-foreground">
          {{ __('Or select from available hooks...') }}
        </span>
        <span v-else>
          {{ sprintf(_n('%d trigger selected', '%d triggers selected', selectedTriggers.length), selectedTriggers.length) }}
        </span>
        <span v-if="!loading" class="ml-auto mr-2 text-xs text-muted-foreground font-normal">
          {{ sprintf(__('%d available'), totalCount) }}
        </span>
        <ChevronsUpDown class="ml-2 h-4 w-4 shrink-0 opacity-50" />
      </Button>

      <!-- Dropdown -->
      <div
        v-if="open"
        class="absolute z-50 mt-1 w-full rounded-md border bg-popover text-popover-foreground shadow-md"
      >
        <!-- Search -->
        <div class="p-2 border-b">
          <input
            v-model="search"
            type="text"
            :placeholder="__('Search triggers...')"
            :class="cn('w-full px-2 py-1 text-sm !border rounded !outline-none !shadow-none !bg-background !text-foreground ring-offset-background',
              searchFocused ? '!border-input !ring-2 !ring-ring !ring-offset-2' : '!border-input'
            )"
            @focus="searchFocused = true"
            @blur="searchFocused = false"
          />
        </div>

        <!-- List -->
        <div class="max-h-64 overflow-y-auto p-1">
          <div v-if="loading" class="p-4 text-center text-muted-foreground">
            {{ __('Loading...') }}
          </div>

          <template v-else>
            <div
              v-for="(names, category) in filteredGrouped"
              :key="category"
              class="mb-2"
            >
              <div class="px-2 py-1 text-xs font-semibold text-muted-foreground uppercase">
                {{ categories[category] || category }}
              </div>
              <button
                v-for="name in names"
                :key="name"
                type="button"
                :class="cn(
                  'relative flex w-full cursor-pointer select-none items-center rounded-sm py-1.5 pl-8 pr-2 text-sm outline-none hover:bg-accent hover:text-accent-foreground',
                  isSelected(name) && 'bg-accent'
                )"
                @click="toggleTrigger(name)"
              >
                <span
                  :class="cn(
                    'absolute left-2 flex h-3.5 w-3.5 items-center justify-center',
                    !isSelected(name) && 'invisible'
                  )"
                >
                  <Check class="h-4 w-4" />
                </span>
                <span class="flex-1 text-left">
                  {{ formatLabel(name) }}
                  <span class="text-muted-foreground text-xs ml-1">({{ name }})</span>
                </span>
              </button>
            </div>

            <div
              v-if="Object.keys(filteredGrouped).length === 0"
              class="p-4 text-center text-muted-foreground"
            >
              {{ __('No triggers found') }}
            </div>
          </template>
        </div>
      </div>
    </div>

    <!-- Click outside to close -->
    <div
      v-if="open"
      class="fixed inset-0 z-40"
      @click="open = false"
    />
  </div>
</template>
