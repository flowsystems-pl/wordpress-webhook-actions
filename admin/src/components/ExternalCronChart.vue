<script setup>
import { computed } from 'vue'
import { Line } from 'vue-chartjs'
import {
  BarController,
  BarElement,
  Chart as ChartJS,
  CategoryScale,
  Filler,
  Legend,
  LinearScale,
  LineController,
  LineElement,
  PointElement,
  Tooltip,
} from 'chart.js'
import { __ } from '@/i18n'

ChartJS.register(
  BarController, BarElement, CategoryScale, Filler, Legend,
  LinearScale, LineController, LineElement, PointElement, Tooltip
)

const props = defineProps({
  beats:     { type: Array, default: () => [] },
  uptime24h: { type: Number, default: null },
  avgPing:   { type: Number, default: null },
})

// Vars are on #fswa-app (.dark / .light class), not <html>
function appEl() {
  return document.getElementById('fswa-app') ?? document.documentElement
}
function hsl(varName) {
  const raw = getComputedStyle(appEl()).getPropertyValue(varName).trim()
  return `hsl(${raw})`
}
function hsla(varName, alpha) {
  const raw = getComputedStyle(appEl()).getPropertyValue(varName).trim()
  const [h, s, l] = raw.split(' ')
  return `hsl(${h} ${s} ${l} / ${alpha})`
}

const chartData = computed(() => {
  // API returns newest-first; reverse for chronological display
  const beats = [...props.beats].reverse()

  const labels    = []
  const pingData  = []
  const downData  = []

  for (const beat of beats) {
    const d = new Date(beat.time)
    labels.push(d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }))
    pingData.push(beat.status === 1 ? (beat.ping ?? null) : null)
    downData.push(beat.status === 0 ? 1 : 0)
  }

  const accent     = hsl('--accent')
  const accentFill = hsla('--accent', 0.15)
  const downFill   = hsla('--destructive', 0.35)

  return {
    labels,
    datasets: [
      {
        type: 'line',
        data: pingData,
        fill: 'origin',
        tension: 0.2,
        borderColor: accent,
        backgroundColor: accentFill,
        borderWidth: 2,
        pointRadius: 0,
        pointHitRadius: 100,
        yAxisID: 'y',
        label: __('Response (ms)'),
        order: 1,
      },
      {
        type: 'bar',
        data: downData,
        backgroundColor: downFill,
        borderColor: 'transparent',
        yAxisID: 'y1',
        barThickness: 'flex',
        barPercentage: 1,
        categoryPercentage: 1,
        inflateAmount: 0.05,
        label: 'status',
        order: 2,
      },
    ],
  }
})

const chartOptions = computed(() => {
  // Re-evaluate when beats change so colors pick up current theme
  void props.beats

  const isDark    = appEl().classList.contains('dark')
  const gridColor = isDark ? 'rgba(255,255,255,0.07)' : 'rgba(0,0,0,0.07)'
  const textColor = isDark ? 'rgba(220,220,220,0.65)' : 'rgba(20,20,20,0.85)'
  const bgTooltip = isDark ? 'rgba(17,24,39,0.95)' : 'rgba(240,248,255,0.97)'
  const fgTooltip = isDark ? '#e5e7eb' : '#111827'

  // beats reversed for display (same as chartData)
  const chronological = [...props.beats].reverse()

  return {
    responsive: true,
    maintainAspectRatio: false,
    animation: { duration: 250 },
    elements: { point: { radius: 0, hitRadius: 100 } },
    layout: { padding: { top: 8, right: 16, bottom: 0, left: 0 } },
    scales: {
      x: {
        ticks: {
          color: textColor,
          font: { size: 11 },
          maxRotation: 0,
          autoSkipPadding: 28,
          maxTicksLimit: 8,
        },
        grid: { color: gridColor },
      },
      y: {
        title: { display: true, text: 'ms', color: textColor, font: { size: 11 } },
        ticks: { color: textColor, font: { size: 11 } },
        grid:  { color: gridColor },
        offset: false,
      },
      y1: {
        display: false,
        min: 0,
        max: 1,
        offset: false,
        grid: { drawOnChartArea: false },
      },
    },
    plugins: {
      legend: {
        display: true,
        position: 'top',
        align: 'start',
        labels: {
          color: textColor,
          boxWidth: 12,
          filter: (item, data) => data.datasets[item.datasetIndex]?.type !== 'bar',
        },
      },
      tooltip: {
        mode: 'nearest',
        intersect: false,
        backgroundColor: bgTooltip,
        bodyColor: fgTooltip,
        titleColor: fgTooltip,
        filter: (item) => item?.chart?.data?.datasets?.[item.datasetIndex]?.type !== 'bar',
        callbacks: {
          title: (items) => {
            const beat = chronological[items[0]?.dataIndex]
            return beat ? new Date(beat.time).toLocaleString() : ''
          },
          label: (ctx) => {
            const beat = chronological[ctx.dataIndex]
            const status = beat?.status === 1 ? __('Success') : __('Fail')
            const line = ctx.parsed.y != null ? `${status} — ${ctx.parsed.y}ms` : status
            if (beat?.msg && beat.status !== 1) return [line, beat.msg]
            return line
          },
        },
      },
    },
  }
})
</script>

<template>
  <div class="ping-chart-wrapper space-y-3">
    <!-- Stats row -->
    <div class="flex gap-6 text-sm">
      <div>
        <span class="text-muted-foreground">{{ __('Success rate 24h') }}</span>
        <span class="ml-2 font-semibold text-foreground">
          {{ uptime24h != null ? `${uptime24h}%` : '—' }}
        </span>
      </div>
      <div>
        <span class="text-muted-foreground">{{ __('Avg ping') }}</span>
        <span class="ml-2 font-semibold text-foreground">
          {{ avgPing != null ? `${avgPing}ms` : '—' }}
        </span>
      </div>
      <div>
        <span class="text-muted-foreground">{{ __('Samples') }}</span>
        <span class="ml-2 font-semibold text-foreground">{{ beats.length }}</span>
      </div>
    </div>

    <div v-if="beats.length" class="h-44">
      <Line :data="chartData" :options="chartOptions" />
    </div>
    <div v-else class="h-14 flex items-center justify-center text-sm text-muted-foreground border border-dashed border-border rounded-md">
      {{ __('No heartbeat data yet') }}
    </div>
  </div>
</template>
