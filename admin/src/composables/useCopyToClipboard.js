import { ref } from 'vue'

export function useCopyToClipboard(resetMs = 2000) {
  const copiedKey = ref(null)

  async function copy(text, key = 'default') {
    try {
      await navigator.clipboard.writeText(text)
    } catch {
      const el = document.createElement('textarea')
      el.value = text
      document.body.appendChild(el)
      el.select()
      document.execCommand('copy')
      document.body.removeChild(el)
    }
    copiedKey.value = key
    setTimeout(() => { copiedKey.value = null }, resetMs)
  }

  return { copiedKey, copy }
}
