<script setup>
import {
  CalendarRoot,
  CalendarHeader,
  CalendarHeading,
  CalendarPrev,
  CalendarNext,
  CalendarGrid,
  CalendarGridHead,
  CalendarGridRow,
  CalendarHeadCell,
  CalendarGridBody,
  CalendarCell,
  CalendarCellTrigger,
} from 'radix-vue'
import { ChevronLeft, ChevronRight } from 'lucide-vue-next'
import { cn } from '@/lib/utils'

defineProps({
  modelValue: {
    type: Object, // CalendarDate
    default: undefined,
  },
})

defineEmits(['update:modelValue'])
</script>

<template>
  <CalendarRoot
    v-slot="{ grid, weekDays }"
    :model-value="modelValue"
    class="p-3"
    @update:model-value="$emit('update:modelValue', $event)"
  >
    <CalendarHeader class="relative flex items-center justify-between mb-2">
      <CalendarPrev
        class="inline-flex items-center justify-center rounded-md text-sm ring-offset-background transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 h-7 w-7 bg-transparent p-0"
      >
        <ChevronLeft class="h-4 w-4" />
      </CalendarPrev>
      <CalendarHeading class="text-sm font-medium" />
      <CalendarNext
        class="inline-flex items-center justify-center rounded-md text-sm ring-offset-background transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 h-7 w-7 bg-transparent p-0"
      >
        <ChevronRight class="h-4 w-4" />
      </CalendarNext>
    </CalendarHeader>

    <div class="flex flex-col gap-y-4 sm:flex-row sm:gap-y-0 sm:gap-x-4">
      <CalendarGrid v-for="month in grid" :key="month.value.toString()">
        <CalendarGridHead>
          <CalendarGridRow class="flex">
            <CalendarHeadCell
              v-for="day in weekDays"
              :key="day"
              class="w-8 rounded-md text-[0.8rem] font-normal text-muted-foreground"
            >
              {{ day }}
            </CalendarHeadCell>
          </CalendarGridRow>
        </CalendarGridHead>
        <CalendarGridBody>
          <CalendarGridRow
            v-for="(week, i) in month.rows"
            :key="i"
            class="flex mt-2 w-full"
          >
            <CalendarCell
              v-for="day in week"
              :key="day.toString()"
              :date="day"
              class="relative p-0 text-center text-sm focus-within:relative focus-within:z-20"
            >
              <CalendarCellTrigger
                :day="day"
                :month="month.value"
                :class="cn(
                  'inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-normal',
                  'ring-offset-background transition-colors',
                  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
                  'disabled:pointer-events-none disabled:opacity-50',
                  'hover:bg-accent hover:text-accent-foreground',
                  'h-8 w-8 p-0',
                  '[&[data-today]:not([data-selected])]:bg-accent [&[data-today]:not([data-selected])]:text-accent-foreground',
                  '[&[data-selected]]:bg-primary [&[data-selected]]:text-primary-foreground',
                  '[&[data-selected]]:hover:bg-primary [&[data-selected]]:hover:text-primary-foreground',
                  '[&[data-outside-view]]:text-muted-foreground [&[data-outside-view]]:opacity-50',
                  '[&[data-disabled]]:text-muted-foreground [&[data-disabled]]:opacity-50',
                )"
              />
            </CalendarCell>
          </CalendarGridRow>
        </CalendarGridBody>
      </CalendarGrid>
    </div>
  </CalendarRoot>
</template>
