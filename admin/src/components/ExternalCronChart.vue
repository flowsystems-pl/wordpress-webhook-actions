<script setup>
import { computed } from 'vue'
import { Bar } from 'vue-chartjs'
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  BarElement,
  Tooltip,
} from 'chart.js'

ChartJS.register(CategoryScale, LinearScale, BarElement, Tooltip)

const props = defineProps({
  beats:     { type: Array, default: () => [] },
  uptime24h: { type: Number, default: null },
  avgPing:   { type: Number, default: null },
})

const reversed = computed(() => [...props.beats].reverse())

const chartData = computed(() => ({
  labels: reversed.value.map((b, i) => i + 1),
  datasets: [
    {
      data: reversed.value.map((b) => b.ping ?? 0),
      backgroundColor: reversed.value.map((b) =>
        b.status === 1 ? 'rgba(34,197,94,0.8)' : 'rgba(239,68,68,0.8)'
      ),
      borderRadius: 2,
      borderSkipped: false,
    },
  ],
}))

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  animation: false,
  plugins: { legend: { display: false }, tooltip: {
    callbacks: {
      label: (ctx) => {
        const b = reversed.value[ctx.dataIndex]
        const status = b?.status === 1 ? 'Up' : 'Down'
        const ping   = b?.ping != null ? ` — ${b.ping}ms` : ''
        return `${status}${ping}`
      },
      title: (items) => {
        const b = reversed.value[items[0]?.dataIndex]
        return b?.time ? new Date(b.time).toLocaleString() : ''
      },
    },
  }},
  scales: {
    x: { display: false },
    y: {
      display: true,
      ticks: { color: 'var(--color-muted-foreground)', font: { size: 11 } },
      grid:  { color: 'var(--color-border)' },
      title: { display: true, text: 'ms', color: 'var(--color-muted-foreground)', font: { size: 11 } },
    },
  },
}
</script>

<template>
  <div class="space-y-3">
    <div class="flex gap-6 text-sm">
      <div>
        <span class="text-muted-foreground">Uptime 24h</span>
        <span class="ml-2 font-semibold text-foreground">
          {{ uptime24h != null ? `${uptime24h}%` : '—' }}
        </span>
      </div>
      <div>
        <span class="text-muted-foreground">Avg ping</span>
        <span class="ml-2 font-semibold text-foreground">
          {{ avgPing != null ? `${avgPing}ms` : '—' }}
        </span>
      </div>
      <div>
        <span class="text-muted-foreground">Samples</span>
        <span class="ml-2 font-semibold text-foreground">{{ beats.length }}</span>
      </div>
    </div>

    <div v-if="beats.length" class="h-32">
      <Bar :data="chartData" :options="chartOptions" />
    </div>
    <div v-else class="h-32 flex items-center justify-center text-sm text-muted-foreground border border-dashed border-border rounded-md">
      No heartbeat data yet
    </div>
  </div>
</template>
