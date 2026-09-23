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
/**
 * Join the REST base to an endpoint that may already carry a query string.
 *
 * With pretty permalinks the base is a path (`/wp-json/fswa/v1/`) and plain
 * concatenation is fine. With PLAIN permalinks — the WordPress default on a
 * fresh install, and what Playground boots with — the base is itself a query
 * string:
 *
 *   https://example.com/index.php?rest_route=/fswa/v1/
 *
 * Appending `agent/traces?limit=50` then produces a second `?`, so WordPress
 * reads the route as the literal `/fswa/v1/agent/traces?limit=50`, matches
 * nothing, and returns 404. Every filtered or paginated GET/DELETE in the admin
 * fails that way — logs, traces, pagination — on exactly the sites least likely
 * to have touched their settings.
 */
function buildUrl(base, endpoint) {
  if (base.includes('?') && endpoint.includes('?')) {
    // Only the first separator: any further `?` belongs to a value.
    return base + endpoint.replace('?', '&')
  }

  return base + endpoint
}

async function request(endpoint, options = {}) {
  const settings = getSettings()
  const url = buildUrl(settings.restUrl || '', endpoint)

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
    test: (id, data) => post(`webhooks/${id}/test`, data),
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
  credentials: {
    list: () => get('credentials'),
    get: (id) => get(`credentials/${id}`),
    create: (data) => post('credentials', data),
    update: (id, data) => put(`credentials/${id}`, data),
    delete: (id, force = false) => del(`credentials/${id}`, force ? { force: true } : {}),
    keyStatus: () => get('credentials/key-status'),
    reencrypt: () => post('credentials/reencrypt'),
    provisionAppPassword: (name) => post('credentials/provision-app-password', name ? { name } : {}),
  },
  notifications: {
    channelTypes: () => get('notifications/channel-types'),
    channels: {
      list: () => get('notifications/channels'),
      get: (id) => get(`notifications/channels/${id}`),
      create: (data) => post('notifications/channels', data),
      update: (id, data) => put(`notifications/channels/${id}`, data),
      delete: (id, force = false) => del(`notifications/channels/${id}`, force ? { force: true } : {}),
      test: (id, data = {}) => post(`notifications/channels/${id}/test`, data),
    },
    rules: {
      list: (params = {}) => get('notifications/rules', params),
      get: (id) => get(`notifications/rules/${id}`),
      create: (data) => post('notifications/rules', data),
      update: (id, data) => put(`notifications/rules/${id}`, data),
      delete: (id) => del(`notifications/rules/${id}`),
      reorder: (ids) => post('notifications/rules/reorder', { ids }),
      test: (id, data = {}) => post(`notifications/rules/${id}/test`, data),
    },
    preview: (data) => post('notifications/preview', data),
    aiDraft: (data) => post('notifications/ai-draft', data),
    log: (params = {}) => get('notifications/log', params),
    resend: (id) => post(`notifications/log/${id}/resend`),
  },
  pro: {
    status: () => get('pro/status'),
    activatePlugin: () => post('pro/activate-plugin'),
    activate: (licenseKey) => post('license/activate', { license_key: licenseKey }),
    deactivate: () => del('license/deactivate'),
  },
  proSettings: {
    get: () => get('pro/settings'),
    update: (data) => put('pro/settings', data),
  },
  schemas: {
    getByWebhook: (webhookId) => get(`schemas/webhook/${webhookId}`),
    get: (webhookId, triggerName) => get(`schemas/webhook/${webhookId}/trigger/${encodeURIComponent(encodeURIComponent(triggerName))}`),
    update: (webhookId, triggerName, data) => put(`schemas/webhook/${webhookId}/trigger/${encodeURIComponent(encodeURIComponent(triggerName))}`, data),
    delete: (webhookId, triggerName) => del(`schemas/webhook/${webhookId}/trigger/${encodeURIComponent(encodeURIComponent(triggerName))}`),
    resetCapture: (webhookId, triggerName) => post(`schemas/webhook/${webhookId}/trigger/${encodeURIComponent(encodeURIComponent(triggerName))}/capture`),
    getUserTriggers: () => get('schemas/user-triggers'),
  },
  builds: {
    export: (data) => post('export', data),
    resolve: (data) => post('export/resolve', data),
    analyze: (document) => post('import/analyze', { document }),
    import: (data) => post('import', data),
    // Route keeps its pro/ prefix from when publishing was a Pro feature.
    publish: (data) => post('pro/publish', data),
    // May this site publish at all? Playground sandboxes and dev boxes cannot.
    publishEligibility: () => get('pro/publish/eligibility'),
    // Is the published page live yet? Probed server-side — the browser cannot
    // read a cross-origin 404.
    publishStatus: (url) => get('pro/publish/status', { url }),
  },
  chains: {
    list: () => get('chains'),
    get: (id) => get(`chains/${id}`),
    create: (data) => post('chains', data),
    update: (id, data) => put(`chains/${id}`, data),
    delete: (id) => del(`chains/${id}`),
    listLinks: (id) => get(`chains/${id}/links`),
    createLink: (id, data) => post(`chains/${id}/links`, data),
    deleteLink: (id, linkId) => del(`chains/${id}/links/${linkId}`),
  },
  activity: {
    list: (params) => get('activity', params),
    get: (id) => get(`activity/${id}`),
    deleteOld: (days) => del('activity', { older_than_days: days }),
  },
  externalCron: {
    getSettings: () => get('pro/external-cron/settings'),
    saveSettings: (data) => put('pro/external-cron/settings', data),
    getStats: () => get('pro/external-cron/stats'),
    pause: () => post('pro/external-cron/pause'),
    resume: () => post('pro/external-cron/resume'),
  },
  agent: {
    status: () => get('agent/status'),
    savePreference: (data) => post('agent/preference', data),
    saveSource: (data) => post('agent/source', data),
    // Anonymous free trial. The Turnstile token can only come from a browser, so
    // the trial is always started from here, never lazily server-side.
    startTrial: (data) => post('agent/trial', data),
    saveByok: (data) => post('agent/byok', data),
    deleteByok: (provider) => del(`agent/byok/${provider}`),
    abilities: () => get('agent/abilities'),
    listConversations: () => get('agent/conversations'),
    createConversation: (data = {}) => post('agent/conversations', data),
    getConversation: (id) => get(`agent/conversations/${id}`),
    deleteConversation: (id) => del(`agent/conversations/${id}`),
    // `origin` marks a turn the panel composed for the user ("fix_it",
    // "continuation") so the transcript can show it as machine text rather than
    // as something they typed. Omitted for a message they actually wrote.
    message: (id, message, origin = '') =>
      post(`agent/conversations/${id}/message`, origin ? { message, origin } : { message }),
    execute: (id, data = {}) => post(`agent/conversations/${id}/execute`, data),
    step: (id, opts = {}) => post(`agent/conversations/${id}/step`, opts),
    setExecMode: (mode) => post('agent/exec-mode', { mode }),
    undo: (id) => post(`agent/conversations/${id}/undo`),
    revert: (id) => post(`agent/conversations/${id}/revert`),
    // Dev-only trace inspection (see AiDevPanel.vue).
    traces: (limit = 50) => get('agent/traces', { limit }),
    setDebug: (enabled) => post('agent/debug', { enabled }),
    clearTraces: () => del('agent/traces'),
  },
  snippets: {
    list: (params = {}) => get('pro/snippets', params),
    get: (id) => get(`pro/snippets/${id}`),
    create: (data) => post('pro/snippets', data),
    update: (id, data) => patch(`pro/snippets/${id}`, data),
    delete: (id) => del(`pro/snippets/${id}`),
    preview: (data) => post('pro/snippets/preview', data),
    getTriggerSnippet: (webhookId, trigger) => get(`pro/trigger-snippets/${webhookId}/trigger/${encodeURIComponent(trigger)}`),
    saveTriggerSnippet: (webhookId, trigger, data) => post(`pro/trigger-snippets/${webhookId}/trigger/${encodeURIComponent(trigger)}`, data),
  },
}

export default api
