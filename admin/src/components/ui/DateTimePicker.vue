<script>
export default { inheritAttrs: false }
</script>

<script setup>
import { ref, computed, watch, useAttrs } from 'vue'
import { parseDate, CalendarDate, today, getLocalTimeZone } from '@internationalized/date'
import { CalendarIcon, X } from 'lucide-vue-next'
import Popover from './Popover.vue'
import Calendar from './Calendar.vue'
import { cn } from '@/lib/utils'

const props = defineProps({
  modelValue: {
    type: String,
    default: '',
  },
  placeholder: {
    type: String,
    default: 'Pick date & time',
  },
})

const emit = defineEmits(['update:modelValue'])
const attrs = useAttrs()

const open = ref(false)
const selectedDate = ref(null)  // CalendarDate
const timeValue = ref('00:00')

// Parse incoming string value ("YYYY-MM-DDTHH:mm") into internal state
const syncFromValue = (val) => {
  if (!val) {
    selectedDate.value = null
    timeValue.value = '00:00'
    return
  }
  try {
    const [datePart, timePart] = val.split('T')
    selectedDate.value = parseDate(datePart)
    timeValue.value = timePart ? timePart.slice(0, 5) : '00:00'
  } catch {
    selectedDate.value = null
    timeValue.value = '00:00'
  }
}

syncFromValue(props.modelValue)
watch(() => props.modelValue, syncFromValue)

const emitCurrent = () => {
  if (!selectedDate.value) {
    emit('update:modelValue', '')
    return
  }
  emit('update:modelValue', `${selectedDate.value.toString()}T${timeValue.value}`)
}

const onDateSelect = (date) => {
  selectedDate.value = date
  emitCurrent()
}

const onTimeInput = (e) => {
  timeValue.value = e.target.value
  emitCurrent()
}

const clear = (e) => {
  e.stopPropagation()
  selectedDate.value = null
  timeValue.value = '00:00'
  emit('update:modelValue', '')
  open.value = false
}

const displayLabel = computed(() => {
  if (!selectedDate.value) return ''
  const d = selectedDate.value
  return `${d.year}-${String(d.month).padStart(2, '0')}-${String(d.day).padStart(2, '0')} ${timeValue.value}`
})
</script>

<template>
  <div :class="attrs.class">
  <Popover :open="open" @update:open="open = $event">
    <template #trigger>
      <button
        type="button"
        :class="cn(
          'flex !h-10 w-full items-center gap-2 rounded-md !border !border-input !bg-background !px-3 !py-2 !text-sm',
          'ring-offset-background focus-visible:!outline-none focus-visible:!ring-2 focus-visible:!ring-ring focus-visible:!ring-offset-2',
          'disabled:cursor-not-allowed disabled:opacity-50',
          !displayLabel && 'text-muted-foreground',
        )"
      >
        <CalendarIcon class="h-4 w-4 shrink-0 opacity-50" />
        <span class="flex-1 text-left truncate">{{ displayLabel || placeholder }}</span>
        <X
          v-if="displayLabel"
          class="h-3.5 w-3.5 shrink-0 opacity-40 hover:opacity-100 transition-opacity"
          @click="clear"
        />
      </button>
    </template>

    <div class="p-0">
      <Calendar
        :model-value="selectedDate"
        @update:model-value="onDateSelect"
      />

      <!-- Time input -->
      <div class="border-t px-3 pb-3 pt-2 flex items-center gap-2">
        <CalendarIcon class="h-4 w-4 text-muted-foreground shrink-0" />
        <span class="text-sm text-muted-foreground">Time</span>
        <input
          type="time"
          :value="timeValue"
          @input="onTimeInput"
          class="flex-1 !rounded-md !border !border-input !bg-background !px-2 !py-1 !text-sm !leading-normal ring-offset-background focus-visible:!outline-none focus-visible:!ring-2 focus-visible:!ring-ring focus-visible:!ring-offset-2 focus:!outline-none focus:!shadow-none focus:!border-input"
        />
      </div>
    </div>
  </Popover>
  </div>
</template>
