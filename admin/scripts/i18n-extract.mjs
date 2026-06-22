/**
 * Extract translatable strings from the Vue admin SPA.
 *
 * `wp i18n make-pot` cannot parse `.vue` single-file components, and the built
 * bundle is minified (function names mangled), so neither can extract our JS
 * strings. This module scans the SOURCE (.vue + .js) for the i18n helper calls
 * (`__`, `_x`, `_n`, `_nx` from `@/i18n`) and returns gettext-style entries.
 *
 * Exported for reuse by the pot-builder and by the `wp-i18n-language` skill.
 */
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'

const STRING_RE = /^\s*(['"])((?:\\.|(?!\1).)*)\1/

/** Unescape a JS string literal body into its runtime value. */
function unescape(body) {
  return body.replace(/\\(['"\\nrt])/g, (_, c) =>
    c === 'n' ? '\n' : c === 'r' ? '\r' : c === 't' ? '\t' : c
  )
}

/** Read up to `count` consecutive string-literal args starting at `idx`. */
function readStringArgs(src, idx, count) {
  const out = []
  let i = idx
  for (let n = 0; n < count; n++) {
    // skip whitespace + a leading comma between args
    while (i < src.length && /[\s,]/.test(src[i])) i++
    const m = STRING_RE.exec(src.slice(i))
    if (!m) break
    out.push(unescape(m[2]))
    i += m.index + m[0].length
  }
  return out
}

const FUNCS = {
  __: { strings: 1 },
  _e: { strings: 1 },
  _x: { strings: 1, ctxAt: 1 }, // __(text, ctx) — 2nd string is context
  _n: { strings: 2 },
  _nx: { strings: 2, ctxAt: 2 }, // (single, plural, n, ctx)
}

/** Extract entries from a single source file's text. */
export function extractFromText(text, ref) {
  const entries = []
  const callRe = /\b(__|_e|_x|_n|_nx)\s*\(/g
  let m
  while ((m = callRe.exec(text))) {
    const fn = m[1]
    const spec = FUNCS[fn]
    const after = m.index + m[0].length
    // _x context is the string right after msgid; _nx context is the 4th arg
    // (we only read leading strings, so context for _nx is not captured here —
    // _nx is unused in this codebase). Read the leading string args:
    const strings = readStringArgs(text, after, spec.ctxAt === 1 ? 2 : spec.strings)
    if (!strings.length) continue
    const entry = { msgid: strings[0], reference: ref }
    if (fn === '_n') entry.msgid_plural = strings[1]
    if (fn === '_x' && strings[1] != null) entry.msgctxt = strings[1]
    if (fn === '_nx') entry.msgid_plural = strings[1]
    entries.push(entry)
  }
  return entries
}

function walk(dir, acc = []) {
  for (const name of readdirSync(dir)) {
    const full = join(dir, name)
    const st = statSync(full)
    if (st.isDirectory()) {
      if (name === 'node_modules' || name === 'dist') continue
      walk(full, acc)
    } else if (/\.(vue|js|mjs)$/.test(name) && !full.includes('/scripts/') && !/\/i18n\.js$/.test(full)) {
      acc.push(full)
    }
  }
  return acc
}

/** Extract all entries from a source directory. Returns deduped entries. */
export function extractDir(srcDir, repoRoot) {
  const files = walk(srcDir)
  const byKey = new Map()
  for (const file of files) {
    const ref = relative(repoRoot, file)
    const text = readFileSync(file, 'utf8')
    for (const e of extractFromText(text, ref)) {
      const key = (e.msgctxt || '') + '' + e.msgid
      if (byKey.has(key)) {
        byKey.get(key).references.add(e.reference)
        if (e.msgid_plural) byKey.get(key).msgid_plural = e.msgid_plural
      } else {
        byKey.set(key, {
          msgid: e.msgid,
          msgid_plural: e.msgid_plural,
          msgctxt: e.msgctxt,
          references: new Set([e.reference]),
        })
      }
    }
  }
  return [...byKey.values()]
}

// CLI: print a JSON dump of extracted entries.
if (import.meta.url === `file://${process.argv[1]}`) {
  const srcDir = process.argv[2] || join(process.cwd(), 'src')
  const repoRoot = process.argv[3] || join(process.cwd(), '..')
  const entries = extractDir(srcDir, repoRoot).map((e) => ({
    ...e,
    references: [...e.references],
  }))
  console.log(JSON.stringify(entries, null, 2))
  console.error(`Extracted ${entries.length} unique JS/Vue strings from ${srcDir}`)
}
