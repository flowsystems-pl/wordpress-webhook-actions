<script setup>
import { ref, computed, onMounted } from 'vue'
import { Check, ChevronsUpDown, X, Plus } from 'lucide-vue-next'
import { cn } from '@/lib/utils'
import { Badge, Button, Input } from '@/components/ui'
import api from '@/lib/api'

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
const triggers = ref([])
const grouped = ref({})
const categories = ref({})
const loading = ref(true)

const selectedTriggers = computed({
  get: () => props.modelValue,
  set: (value) => emit('update:modelValue', value),
})

const filteredGrouped = computed(() => {
  if (!search.value) return grouped.value

  const filtered = {}
  const searchLower = search.value.toLowerCase()

  Object.entries(grouped.value).forEach(([category, items]) => {
    const matchedItems = items.filter(
      (item) =>
        item.name.toLowerCase().includes(searchLower) ||
        item.label.toLowerCase().includes(searchLower)
    )
    if (matchedItems.length > 0) {
      filtered[category] = matchedItems
    }
  })

  return filtered
})

const toggleTrigger = (trigger) => {
  const index = selectedTriggers.value.indexOf(trigger.name)
  if (index === -1) {
    selectedTriggers.value = [...selectedTriggers.value, trigger.name]
  } else {
    selectedTriggers.value = selectedTriggers.value.filter((t) => t !== trigger.name)
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

const isSelected = (name) => selectedTriggers.value.includes(name)

const getTriggerLabel = (name) => {
  const trigger = triggers.value.find((t) => t.name === name)
  return trigger?.label || name
}

const isCustomTrigger = (name) => {
  return !triggers.value.find((t) => t.name === name)
}

onMounted(async () => {
  try {
    const data = await api.triggers.list()
    triggers.value = data.triggers || []
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
        placeholder="Enter custom hook name..."
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
        Add
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
          Or select from available hooks...
        </span>
        <span v-else>
          {{ selectedTriggers.length }} trigger{{ selectedTriggers.length !== 1 ? 's' : '' }} selected
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
            placeholder="Search triggers..."
            class="w-full px-2 py-1 text-sm border rounded focus:outline-none focus:ring-2 focus:ring-ring"
          />
        </div>

        <!-- List -->
        <div class="max-h-64 overflow-y-auto p-1">
          <div v-if="loading" class="p-4 text-center text-muted-foreground">
            Loading...
          </div>

          <template v-else>
            <div
              v-for="(items, category) in filteredGrouped"
              :key="category"
              class="mb-2"
            >
              <div class="px-2 py-1 text-xs font-semibold text-muted-foreground uppercase">
                {{ categories[category] || category }}
              </div>
              <button
                v-for="trigger in items"
                :key="trigger.name"
                type="button"
                :class="cn(
                  'relative flex w-full cursor-pointer select-none items-center rounded-sm py-1.5 pl-8 pr-2 text-sm outline-none hover:bg-accent hover:text-accent-foreground',
                  isSelected(trigger.name) && 'bg-accent'
                )"
                @click="toggleTrigger(trigger)"
              >
                <span
                  :class="cn(
                    'absolute left-2 flex h-3.5 w-3.5 items-center justify-center',
                    !isSelected(trigger.name) && 'invisible'
                  )"
                >
                  <Check class="h-4 w-4" />
                </span>
                <span class="flex-1 text-left">
                  {{ trigger.label }}
                  <span v-if="trigger.isRegistered" class="text-muted-foreground text-xs ml-1">({{ trigger.name }})</span>
                </span>
              </button>
            </div>

            <div
              v-if="Object.keys(filteredGrouped).length === 0"
              class="p-4 text-center text-muted-foreground"
            >
              No triggers found
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
