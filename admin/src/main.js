import { createApp } from 'vue'
import App from './App.vue'
import router from './router'
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

app.use(router)

app.mount('#fswa-app')
