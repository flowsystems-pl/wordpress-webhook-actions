import { ref } from 'vue'
import api from '@/lib/api'

const proActive = ref(false)
let fetched = false

const refresh = () => {
  fetched = true
  return api.pro.status()
    .then((data) => { proActive.value = data.state === 'active' })
    .catch(() => {})
}

export function usePro() {
  if (!fetched) refresh()
  return { proActive, refresh }
}
