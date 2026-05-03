import { ref } from 'vue'

const STORAGE_KEY = 'fswa_sync_warning_dismissed'

export function useSyncWarning() {
  const dontShowAgain = ref(false)

  const isWarningDismissed = () => localStorage.getItem(STORAGE_KEY) === '1'

  const applyDismiss = () => {
    if (dontShowAgain.value) {
      localStorage.setItem(STORAGE_KEY, '1')
    }
    dontShowAgain.value = false
  }

  const resetDontShowAgain = () => {
    dontShowAgain.value = false
  }

  return { dontShowAgain, isWarningDismissed, applyDismiss, resetDontShowAgain }
}
