/**
 * Apply a translation dictionary to a .pot and emit a locale .po plus the
 * JED .json consumed by @wordpress/i18n / wp_set_script_translations().
 *
 * The .json is written at a STABLE, handle-based path
 * (`<domain>-<locale>-<handle>.json`) and loaded via the
 * `load_script_translation_file` PHP filter, which sidesteps the brittle
 * md5-of-script-path filename that `wp i18n make-json` would otherwise require.
 *
 * Dictionary format (JSON): { "<msgid>": "<translation>" } and for plurals
 * { "<singular msgid>": ["form0","form1","form2"] }. Context-qualified keys use
 * "<context><msgid>". Missing entries stay untranslated (English fallback).
 *
 * Usage:
 *   node scripts/i18n-translate.mjs <pot> <dict.json> <locale> <pluralForms> <handle> <outDir> <domain>
 */
import { readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import gettextParser from 'gettext-parser'

const [, , potPath, dictPath, locale, pluralForms, handle, outDir, domain] = process.argv

const po = gettextParser.po.parse(readFileSync(potPath))
const dict = JSON.parse(readFileSync(dictPath, 'utf8'))

po.charset = 'utf-8'
po.headers['Language'] = locale
po.headers['plural-forms'] = pluralForms
po.headers['Plural-Forms'] = pluralForms

let translated = 0
const total = []
for (const ctx of Object.keys(po.translations)) {
  for (const msgid of Object.keys(po.translations[ctx])) {
    if (msgid === '') continue
    total.push(msgid)
    const key = ctx ? `${ctx}${msgid}` : msgid
    const val = dict[key] ?? dict[msgid]
    const entry = po.translations[ctx][msgid]
    if (val == null) continue
    entry.msgstr = Array.isArray(val) ? val : [val]
    translated++
  }
}

writeFileSync(join(outDir, `${domain}-${locale}.po`), gettextParser.po.compile(po, { sort: true }))

// Build the JED locale_data for JS strings (entries with an admin/src reference).
const localeData = {
  '': { domain: 'messages', lang: locale, 'plural-forms': pluralForms },
}
for (const ctx of Object.keys(po.translations)) {
  for (const msgid of Object.keys(po.translations[ctx])) {
    if (msgid === '') continue
    const entry = po.translations[ctx][msgid]
    const ref = entry.comments?.reference || ''
    if (!ref.includes('admin/src')) continue // JS/Vue strings only
    const hasTranslation = entry.msgstr.some((s) => s !== '')
    if (!hasTranslation) continue
    const key = ctx ? `${ctx}${msgid}` : msgid
    localeData[key] = entry.msgstr
  }
}

const jed = {
  'translation-revision-date': new Date().toISOString(),
  generator: 'fswa-i18n',
  domain: 'messages',
  locale_data: { messages: localeData },
}
writeFileSync(
  join(outDir, `${domain}-${locale}-${handle}.json`),
  JSON.stringify(jed)
)

console.error(
  `Locale ${locale}: translated ${translated}/${total.length}; ` +
  `JS JSON entries: ${Object.keys(localeData).length - 1}`
)
