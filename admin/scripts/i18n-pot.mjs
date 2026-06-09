/**
 * Build the combined translation template (.pot) for the free plugin.
 *
 * Merges a PHP-only .pot (produced by `wp i18n make-pot --skip-js`) with the
 * JS/Vue strings extracted from the Vue SPA source (see i18n-extract.mjs), so a
 * single .pot drives both `load_plugin_textdomain` (PHP/.mo) and
 * `wp_set_script_translations` (JS/.json).
 *
 * Usage:
 *   node scripts/i18n-pot.mjs <php.pot> <out.pot> [srcDir] [repoRoot]
 */
import { readFileSync, writeFileSync } from 'node:fs'
import { join, dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import gettextParser from 'gettext-parser'
import { extractDir } from './i18n-extract.mjs'

const __dirname = dirname(fileURLToPath(import.meta.url))

const phpPotPath = process.argv[2]
const outPath = process.argv[3]
const srcDir = process.argv[4] || resolve(__dirname, '../src')
const repoRoot = process.argv[5] || resolve(__dirname, '../..')

const po = gettextParser.po.parse(readFileSync(phpPotPath))
po.charset = 'utf-8'
const ctxs = po.translations

let added = 0
let merged = 0
for (const e of extractDir(srcDir, repoRoot)) {
  const ctx = e.msgctxt || ''
  ctxs[ctx] = ctxs[ctx] || {}
  const existing = ctxs[ctx][e.msgid]
  const refList = [...e.references].join(' ')
  if (existing) {
    // Append JS references to the PHP-sourced entry.
    const refs = (existing.comments?.reference || '').split(/\s+/).filter(Boolean)
    for (const r of e.references) if (!refs.includes(r)) refs.push(r)
    existing.comments = { ...existing.comments, reference: refs.join(' ') }
    if (e.msgid_plural && !existing.msgid_plural) {
      existing.msgid_plural = e.msgid_plural
      existing.msgstr = ['', '']
    }
    merged++
  } else {
    ctxs[ctx][e.msgid] = {
      msgctxt: e.msgctxt,
      msgid: e.msgid,
      msgid_plural: e.msgid_plural,
      msgstr: e.msgid_plural ? ['', ''] : [''],
      comments: { reference: refList },
    }
    added++
  }
}

const out = gettextParser.po.compile(po, { sort: true })
writeFileSync(outPath, out)
console.error(`Wrote ${outPath} — JS strings: +${added} new, ${merged} shared with PHP`)
