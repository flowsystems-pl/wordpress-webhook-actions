<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { useHealthStats } from '../composables/useHealthStats';
import {
  Activity,
  AlertTriangle,
  CheckCircle,
  XCircle,
  Clock,
  Zap,
  Webhook,
  Share2,
  Rocket,
  Timer,
} from 'lucide-vue-next';

const route = useRoute();
const { stats, loading, successRate, hasData, webhooks, logs, queue, velocity, observability } =
  useHealthStats();

const currentContext = computed(() => {
  const path = route.path;
  if (path.startsWith('/webhooks')) return 'webhooks';
  if (path.startsWith('/logs')) return 'logs';
  if (path.startsWith('/queue')) return 'queue';
  return 'webhooks';
});

const successRateColor = computed(() => {
  const rate = successRate.value;
  if (rate >= 95) return 'text-green-500';
  if (rate >= 80) return 'text-yellow-500';
  return 'text-red-500';
});

const successRateBgColor = computed(() => {
  const rate = successRate.value;
  if (rate >= 95) return 'bg-green-500/10';
  if (rate >= 80) return 'bg-yellow-500/10';
  return 'bg-red-500/10';
});

const formatNumber = (num) => {
  if (num >= 1000000) {
    return (num / 1000000).toFixed(1) + 'M';
  }
  if (num >= 1000) {
    return (num / 1000).toFixed(1) + 'K';
  }
  return num.toString();
};

const formatDuration = (ms) => {
  if (ms === 0) return '-';
  if (ms >= 1000) {
    return (ms / 1000).toFixed(1) + 's';
  }
  return ms + 'ms';
};
</script>

<template>
  <div class="mb-4 space-y-2">
    <!-- Warning banners -->
    <div
      v-if="observability.queue_stuck"
      class="flex items-center gap-2 px-3 py-2 rounded-md bg-yellow-500/10 border border-yellow-500/20 text-xs text-yellow-700 dark:text-yellow-400"
    >
      <AlertTriangle class="w-3.5 h-3.5 shrink-0" />
      <span>Queue appears stuck — there are pending deliveries older than 10 minutes. Check your cron setup.</span>
    </div>
    <div
      v-if="observability.wp_cron_only"
      class="flex items-center gap-2 px-3 py-2 rounded-md bg-blue-500/10 border border-blue-500/20 text-xs text-blue-700 dark:text-blue-400"
    >
      <AlertTriangle class="w-3.5 h-3.5 shrink-0" />
      <span>Queue processor has never run. Ensure WP-Cron is active or configure an external cron job for reliable delivery.</span>
    </div>

  <div class="p-2 sm:p-3 rounded-lg border border-border bg-card">
    <!-- Skeleton loader — only on first load, not on subsequent refreshes -->
    <div v-if="stats === null" class="flex items-center gap-3 sm:gap-6">
      <div
        class="animate-pulse flex items-center gap-3 sm:gap-6 w-full flex-wrap"
      >
        <div class="h-7 sm:h-8 w-20 sm:w-24 bg-muted rounded"></div>
        <div class="h-7 sm:h-8 w-24 sm:w-32 bg-muted rounded"></div>
        <div class="h-7 sm:h-8 w-20 sm:w-28 bg-muted rounded"></div>
        <div class="h-7 sm:h-8 w-20 sm:w-24 bg-muted rounded"></div>
      </div>
    </div>

    <!-- Metrics -->
    <div v-else class="flex items-center gap-3 sm:gap-6 flex-wrap">
      <!-- Success Rate or Ready State -->
      <div
        v-if="hasData"
        :class="[
          'flex items-center gap-1.5 sm:gap-2 px-2 sm:px-3 py-1 sm:py-1.5 rounded-md',
          successRateBgColor,
        ]"
      >
        <Activity :class="['w-3.5 h-3.5 sm:w-4 sm:h-4', successRateColor]" />
        <span class="text-xs sm:text-sm font-medium text-muted-foreground"
          >Success Rate</span
        >
        <span :class="['text-xs sm:text-sm font-bold', successRateColor]">
          {{ successRate }}%
        </span>
      </div>
      <!-- Ready state for new installations -->
      <div
        v-else
        class="flex items-center gap-1.5 sm:gap-2 px-2 sm:px-3 py-1 sm:py-1.5 rounded-md bg-primary/10"
      >
        <Rocket class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-primary" />
        <span class="text-xs sm:text-sm font-medium text-primary"
          >Ready to send some data?</span
        >
      </div>

      <!-- Webhooks context -->
      <template v-if="currentContext === 'webhooks'">
        <div class="flex items-center gap-1.5 sm:gap-2 text-xs sm:text-sm">
          <Webhook class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-muted-foreground" />
          <span class="text-muted-foreground">Active/Total</span>
          <span class="font-medium tabular-nums">
            {{ webhooks.active }}/{{ webhooks.total }}
          </span>
        </div>
        <div class="flex items-center gap-1.5 sm:gap-2 text-xs sm:text-sm">
          <Zap class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-muted-foreground" />
          <span class="text-muted-foreground">Sent today</span>
          <span class="font-medium tabular-nums">{{ formatNumber(velocity.last_day) }}</span>
        </div>
        <div class="flex items-center gap-1.5 sm:gap-2 text-xs sm:text-sm">
          <Share2 class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-muted-foreground" />
          <span class="text-muted-foreground">Total sent</span>
          <span class="font-medium tabular-nums">{{
            formatNumber(logs.total_all_time)
          }}</span>
        </div>
        <div class="flex items-center gap-1.5 sm:gap-2 text-xs sm:text-sm">
          <Timer class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-muted-foreground" />
          <span class="text-muted-foreground">Avg response</span>
          <span class="font-medium tabular-nums">{{
            formatDuration(velocity.avg_duration_ms)
          }}</span>
        </div>
      </template>

      <!-- Logs context -->
      <template v-else-if="currentContext === 'logs'">
        <div class="flex items-center gap-1.5 sm:gap-2 text-xs sm:text-sm">
          <CheckCircle class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-green-500" />
          <span class="text-muted-foreground">Success</span>
          <span class="font-medium text-green-500">{{
            formatNumber(logs.success)
          }}</span>
        </div>
        <div class="flex items-center gap-1.5 sm:gap-2 text-xs sm:text-sm">
          <XCircle class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-red-500" />
          <span class="text-muted-foreground">Errors</span>
          <span class="font-medium text-red-500">{{
            formatNumber(logs.error)
          }}</span>
        </div>
        <div class="flex items-center gap-1.5 sm:gap-2 text-xs sm:text-sm">
          <Activity class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-muted-foreground" />
          <span class="text-muted-foreground">Total (7d)</span>
          <span class="font-medium tabular-nums">{{ formatNumber(logs.total) }}</span>
        </div>
        <div class="flex items-center gap-1.5 sm:gap-2 text-xs sm:text-sm">
          <Share2 class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-muted-foreground" />
          <span class="text-muted-foreground">Total sent</span>
          <span class="font-medium tabular-nums">{{
            formatNumber(logs.total_all_time)
          }}</span>
        </div>
        <div class="flex items-center gap-1.5 sm:gap-2 text-xs sm:text-sm">
          <Timer class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-muted-foreground" />
          <span class="text-muted-foreground">Avg response</span>
          <span class="font-medium tabular-nums">{{
            formatDuration(velocity.avg_duration_ms)
          }}</span>
        </div>
      </template>

      <!-- Queue context -->
      <template v-else-if="currentContext === 'queue'">
        <div class="flex items-center gap-1.5 sm:gap-2 text-xs sm:text-sm">
          <Zap class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-muted-foreground" />
          <span class="text-muted-foreground">Last hour</span>
          <span class="font-medium tabular-nums">{{
            formatNumber(velocity.last_hour)
          }}</span>
        </div>
        <div class="flex items-center gap-1.5 sm:gap-2 text-xs sm:text-sm">
          <Activity class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-muted-foreground" />
          <span class="text-muted-foreground">Today</span>
          <span class="font-medium tabular-nums">{{ formatNumber(velocity.last_day) }}</span>
        </div>
        <div class="flex items-center gap-1.5 sm:gap-2 text-xs sm:text-sm">
          <Clock class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-yellow-500" />
          <span class="text-muted-foreground">Pending</span>
          <span class="font-medium text-yellow-500">{{ queue.pending }}</span>
        </div>
        <div class="flex items-center gap-1.5 sm:gap-2 text-xs sm:text-sm">
          <Share2 class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-muted-foreground" />
          <span class="text-muted-foreground">Total sent</span>
          <span class="font-medium tabular-nums">{{
            formatNumber(logs.total_all_time)
          }}</span>
        </div>
        <div v-if="observability.avg_attempts_per_event > 0" class="flex items-center gap-1.5 sm:gap-2 text-xs sm:text-sm">
          <Activity class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-muted-foreground" />
          <span class="text-muted-foreground">Avg attempts</span>
          <span :class="['font-medium', observability.avg_attempts_per_event > 2 ? 'text-yellow-500' : '']">
            {{ observability.avg_attempts_per_event }}
          </span>
        </div>
      </template>
    </div>
  </div>
  </div>
</template>
