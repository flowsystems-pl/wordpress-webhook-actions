import { createRouter, createWebHashHistory } from 'vue-router'

const routes = [
  {
    path: '/',
    redirect: '/webhooks',
  },
  {
    path: '/webhooks',
    name: 'WebhooksList',
    component: () => import('@/views/WebhooksList.vue'),
  },
  {
    path: '/webhooks/new',
    name: 'WebhookCreate',
    component: () => import('@/views/WebhookEdit.vue'),
  },
  {
    path: '/webhooks/:id',
    name: 'WebhookEdit',
    component: () => import('@/views/WebhookEdit.vue'),
    props: true,
  },
  {
    path: '/webhooks/:id/logs',
    name: 'WebhookLogs',
    component: () => import('@/views/WebhookLogs.vue'),
    props: true,
  },
  {
    path: '/logs',
    name: 'LogsList',
    component: () => import('@/views/LogsList.vue'),
  },
  {
    path: '/queue',
    name: 'Queue',
    component: () => import('@/views/QueueView.vue'),
  },
  {
    path: '/settings',
    name: 'Settings',
    component: () => import('@/views/SettingsView.vue'),
  },
]

const router = createRouter({
  history: createWebHashHistory(),
  routes,
})

export default router
