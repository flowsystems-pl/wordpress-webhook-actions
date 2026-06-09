import { createApp } from 'vue'
import App from './App.vue'
import router from './router'
import { __, _n, _x, sprintf } from './i18n'
import './style.css'

// Set default theme before app mounts
const initTheme = () => {
  const stored = localStorage.getItem('fswa-theme')
  const theme = stored === 'light' ? 'light' : 'dark'

  const appEl = document.getElementById('fswa-app')
  if (appEl) {
    appEl.classList.add(theme)
  }

  const wpContent = document.getElementById('wpcontent')
  if (wpContent) {
    wpContent.classList.add('fswa-theme', theme)
  }
}

initTheme()

const app = createApp(App)

// Expose i18n helpers to all templates (no per-file import needed for `{{ __('…') }}`).
app.config.globalProperties.__ = __
app.config.globalProperties._n = _n
app.config.globalProperties._x = _x
app.config.globalProperties.sprintf = sprintf

app.use(router)

app.mount('#fswa-app')
