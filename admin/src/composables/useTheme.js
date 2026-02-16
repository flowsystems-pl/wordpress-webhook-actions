import { ref, watch, onMounted } from 'vue'

const STORAGE_KEY = 'fswa-theme'
const theme = ref('dark')

export function useTheme() {
  const initTheme = () => {
    const stored = localStorage.getItem(STORAGE_KEY)
    if (stored === 'light' || stored === 'dark') {
      theme.value = stored
    } else {
      theme.value = 'dark'
    }
    applyTheme()
  }

  const applyTheme = () => {
    const app = document.getElementById('fswa-app')
    if (app) {
      app.classList.remove('light', 'dark')
      app.classList.add(theme.value)
    }

    const wpContent = document.getElementById('wpcontent')
    if (wpContent) {
      wpContent.classList.remove('light', 'dark')
      wpContent.classList.add(theme.value)
    }
  }

  const toggleTheme = () => {
    theme.value = theme.value === 'dark' ? 'light' : 'dark'
    localStorage.setItem(STORAGE_KEY, theme.value)
    applyTheme()
  }

  const isDark = () => theme.value === 'dark'

  watch(theme, applyTheme)

  onMounted(initTheme)

  return {
    theme,
    toggleTheme,
    isDark,
    initTheme
  }
}
