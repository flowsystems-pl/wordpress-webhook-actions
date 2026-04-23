import { ref } from 'vue'
import api from '@/lib/api'

const proActive = ref(false)
let fetched = false

export function usePro() {
  if (!fetched) {
    fetched = true
    api.pro.status()
      .then((data) => { proActive.value = data.state === 'active' })
      .catch(() => {})
  }

  return { proActive }
}
