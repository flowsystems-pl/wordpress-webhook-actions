/**
 * API wrapper for WordPress REST API
 */

const getSettings = () => window.fswaSettings || {}

/**
 * Make a request to the WP REST API
 *
 * @param {string} endpoint - The API endpoint (relative to rest URL)
 * @param {object} options - Fetch options
 * @returns {Promise<any>}
 */
async function request(endpoint, options = {}) {
  const settings = getSettings()
  const url = `${settings.restUrl}${endpoint}`

  const headers = {
    'Content-Type': 'application/json',
    'X-WP-Nonce': settings.nonce,
    ...options.headers,
  }

  const response = await fetch(url, {
    ...options,
    headers,
  })

  if (!response.ok) {
    const errorData = await response.json().catch(() => ({}))
    const err = new Error(errorData.message || `HTTP ${response.status}`)
    err.code = errorData.code
    err.data = errorData
    throw err
  }

  // Handle empty responses
  const text = await response.text()
  if (!text) {
    return null
  }

  // Parse pagination headers
  const total = response.headers.get('X-WP-Total')
  const totalPages = response.headers.get('X-WP-TotalPages')

  const data = JSON.parse(text)

  if (total !== null) {
    return {
      items: data,
      total: parseInt(total, 10),
      totalPages: parseInt(totalPages, 10),
    }
  }

  return data
}

/**
 * GET request
 */
export function get(endpoint, params = {}) {
  const searchParams = new URLSearchParams()
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null) {
      searchParams.append(key, value)
    }
  })

  const queryString = searchParams.toString()
  const url = queryString ? `${endpoint}?${queryString}` : endpoint

  return request(url, { method: 'GET' })
}

/**
 * POST request
 */
export function post(endpoint, data = {}) {
  return request(endpoint, {
    method: 'POST',
    body: JSON.stringify(data),
  })
}

/**
 * PUT request
 */
export function put(endpoint, data = {}) {
  return request(endpoint, {
    method: 'PUT',
    body: JSON.stringify(data),
  })
}

/**
 * PATCH request
 */
export function patch(endpoint, data = {}) {
  return request(endpoint, {
    method: 'PATCH',
    body: JSON.stringify(data),
  })
}

/**
 * DELETE request
 */
export function del(endpoint, params = {}) {
  const searchParams = new URLSearchParams()
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null) {
      searchParams.append(key, value)
    }
  })

  const queryString = searchParams.toString()
  const url = queryString ? `${endpoint}?${queryString}` : endpoint

  return request(url, { method: 'DELETE' })
}

// API endpoints
export const api = {
  webhooks: {
    list: () => get('webhooks'),
    get: (id) => get(`webhooks/${id}`),
    create: (data) => post('webhooks', data),
    update: (id, data) => put(`webhooks/${id}`, data),
    delete: (id) => del(`webhooks/${id}`),
    toggle: (id) => post(`webhooks/${id}/toggle`),
    logs: (id, params) => get(`webhooks/${id}/logs`, params),
  },
  logs: {
    list: (params) => get('logs', params),
    get: (id) => get(`logs/${id}`),
    delete: (id) => del(`logs/${id}`),
    deleteOld: (days) => del('logs', { older_than_days: days }),
    stats: (params) => get('logs/stats', params),
    retry: (id) => post(`logs/${id}/retry`),
    replay: (id) => post(`logs/${id}/replay`),
    bulkRetry: (ids) => post('logs/bulk-retry', { ids }),
  },
  triggers: {
    list: () => get('triggers'),
  },
  settings: {
    get: () => get('settings'),
    update: (data) => put('settings', data),
    info: () => get('settings/info'),
    archive: () => get('settings/archive'),
    downloadArchive: () => get('settings/archive/download'),
    clearLogs: () => post('settings/clear-logs'),
  },
  queue: {
    list: (params) => get('queue', params),
    stats: () => get('queue/stats'),
    execute: (data) => post('queue/execute', data),
    delete: (data) => post('queue/delete', data),
    retry: (data) => post('queue/retry', data),
  },
  dispatcher: {
    process: (data) => post('dispatcher/process', data),
  },
  cron: {
    info: () => get('cron/info'),
    regenerateToken: () => post('cron/regenerate-token'),
  },
  health: {
    stats: () => get('health'),
  },
  tokens: {
    list: () => get('tokens'),
    create: (data) => post('tokens', data),
    rotate: (id, data = {}) => post(`tokens/${id}/rotate`, data),
    updateExpiry: (id, expiresAt) => patch(`tokens/${id}`, { expires_at: expiresAt }),
    delete: (id) => del(`tokens/${id}`),
  },
  pro: {
    status: () => get('pro/status'),
    activate: (licenseKey) => post('license/activate', { license_key: licenseKey }),
    deactivate: () => del('license/deactivate'),
  },
  schemas: {
    getByWebhook: (webhookId) => get(`schemas/webhook/${webhookId}`),
    get: (webhookId, triggerName) => get(`schemas/webhook/${webhookId}/trigger/${encodeURIComponent(encodeURIComponent(triggerName))}`),
    update: (webhookId, triggerName, data) => put(`schemas/webhook/${webhookId}/trigger/${encodeURIComponent(encodeURIComponent(triggerName))}`, data),
    delete: (webhookId, triggerName) => del(`schemas/webhook/${webhookId}/trigger/${encodeURIComponent(encodeURIComponent(triggerName))}`),
    resetCapture: (webhookId, triggerName) => post(`schemas/webhook/${webhookId}/trigger/${encodeURIComponent(encodeURIComponent(triggerName))}/capture`),
    getUserTriggers: () => get('schemas/user-triggers'),
  },
}

export default api
