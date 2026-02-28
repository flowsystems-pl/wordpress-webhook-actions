<script setup>
import { RouterView, RouterLink, useRoute } from 'vue-router';
import {
  Webhook,
  ScrollText,
  Settings,
  Moon,
  Sun,
  Clock,
} from 'lucide-vue-next';
import { useTheme } from './composables/useTheme';
import HealthStatusBar from './components/HealthStatusBar.vue';

const route = useRoute();
const { theme, toggleTheme } = useTheme();

const navItems = [
  { path: '/webhooks', label: 'Webhooks', icon: Webhook },
  { path: '/logs', label: 'Logs', icon: ScrollText },
  { path: '/queue', label: 'Queue', icon: Clock },
  { path: '/settings', label: 'Settings', icon: Settings },
];

const isActive = (path) => {
  return route.path.startsWith(path);
};
</script>

<template>
  <div class="min-h-[500px] flex flex-col">
    <!-- Header -->
    <div
      class="flex items-center justify-between mb-6 pb-4 border-b border-border"
    >
      <h1 class="text-2xl font-semibold text-foreground">Webhook Actions</h1>
      <button
        @click="toggleTheme"
        class="p-2 rounded-md hover:bg-muted text-muted-foreground hover:text-foreground transition-colors"
        :title="
          theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'
        "
      >
        <Sun v-if="theme === 'dark'" class="w-5 h-5" />
        <Moon v-else class="w-5 h-5" />
      </button>
    </div>

    <!-- Health Status Bar -->
    <HealthStatusBar />

    <!-- Navigation -->
    <nav class="flex flex-wrap gap-1 mb-6 border-b border-border">
      <RouterLink
        v-for="item in navItems"
        :key="item.path"
        :to="item.path"
        :class="[
          'flex items-center gap-1.5 sm:gap-2 px-2.5 sm:px-4 py-2 text-xs sm:text-sm font-medium transition-colors border-b-2 -mb-px whitespace-nowrap',
          isActive(item.path)
            ? 'border-primary text-primary'
            : 'border-transparent text-muted-foreground hover:text-foreground hover:border-muted',
        ]"
      >
        <component :is="item.icon" class="w-4 h-4" />
        {{ item.label }}
      </RouterLink>
    </nav>

    <!-- Content -->
    <main class="flex-1">
      <RouterView />
    </main>

    <!-- Footer -->
    <footer class="mt-8 pt-4 border-t border-border">
      <div
        class="flex flex-wrap items-center justify-between text-sm text-muted-foreground"
      >
        <a
          href="https://flowsystems.pl"
          target="_blank"
          rel="noopener noreferrer"
          class="flow-logo hover:text-foreground transition-colors"
        >
          Flow_Systems<span class="cursor">█</span>
        </a>
        <span class="text-muted-foreground">⭐ Love the plugin? <a
          href="https://wordpress.org/support/plugin/flowsystems-webhook-actions/reviews/#new-post"
          target="_blank"
          rel="noopener noreferrer"
          class="underline underline-offset-2 hover:text-foreground transition-colors"
        >Leave a review on WordPress.org</a></span>
      </div>
    </footer>
  </div>
</template>
