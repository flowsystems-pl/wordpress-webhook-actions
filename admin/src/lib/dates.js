/**
 * Date utilities for UTC ↔ local time conversion.
 *
 * The DB stores all datetimes in UTC without a timezone marker ("YYYY-MM-DD HH:mm:ss").
 * JS Date parsing of such strings is implementation-defined and typically treats them
 * as local time — which causes off-by-timezone bugs. All helpers here enforce UTC parsing.
 */

const pad = (n) => String(n).padStart(2, '0')

/**
 * Parse a DB UTC string ("YYYY-MM-DD HH:mm:ss") as a Date object (UTC).
 */
export function parseUtcDb(utcStr) {
  if (!utcStr) return null
  return new Date(utcStr.replace(' ', 'T') + 'Z')
}

/**
 * Convert a DB UTC string to a local-time picker value ("YYYY-MM-DDTHH:mm").
 * Used when pre-filling DateTimePicker from a stored expires_at.
 */
export function utcDbToPickerLocal(utcStr) {
  if (!utcStr) return null
  const d = parseUtcDb(utcStr)
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

/**
 * Convert a picker local value ("YYYY-MM-DDTHH:mm") to a DB UTC string ("YYYY-MM-DD HH:mm:ss").
 * Used before sending expires_at to the API.
 */
export function pickerLocalToUtcDb(localStr) {
  if (!localStr) return null
  const d = new Date(localStr) // parsed as local time
  return `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())} ${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())}:00`
}

/**
 * Check whether a DB UTC expires_at string is in the past.
 */
export function isUtcExpired(utcStr) {
  if (!utcStr) return false
  return parseUtcDb(utcStr) <= new Date()
}

/**
 * Format a DB UTC string for display in local time.
 */
export function formatUtcDate(utcStr) {
  if (!utcStr) return '—'
  return parseUtcDb(utcStr).toLocaleString()
}
