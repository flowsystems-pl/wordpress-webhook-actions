// Weekly Live Preview check (run by .github/workflows/live-preview-check.yml).
//
// The Live Preview is the cheapest way to try the plugin, and it can break with
// no change on our side: on 2026-10-11 Contact Form 7 6.2 started requiring PHP
// 8.3 while the blueprint pinned 8.2, so every preview opened on a "Blueprint
// execution failed" box, and nobody noticed for days. This script opens both
// previews the way a visitor does and fails loudly if either one breaks.
//
// Two blueprints are checked because they drift apart:
//   - website: https://wpwebhooks.org/blueprint.json, our own copy, always current
//   - wporg:   the copy WP.org imported at the last release; the WP.org "Live
//              Preview" button uses this one, and an SVN assets commit does
//              NOT update it until WP.org re-imports
//
// Usage: node scripts/check-live-preview.mjs [screenshotDir]
// Needs: playwright (chromium). Exit code 1 on any failure.

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const SLUG = 'flowsystems-webhook-actions';
const ADMIN_PAGE = 'fswa-webhook-actions'; // the admin screen slug, not the plugin slug
const OUT = process.argv[2] || 'preview-check';
const BOOT_MS = 150_000;

mkdirSync(OUT, { recursive: true });
const failures = [];
const fail = (msg) => { failures.push(msg); console.log(`✗ ${msg}`); };
const ok = (msg) => console.log(`✓ ${msg}`);

async function json(url) {
  const res = await fetch(url, { headers: { 'cache-control': 'no-cache' } });
  if (!res.ok) throw new Error(`${url} → HTTP ${res.status}`);
  return res.json();
}

async function blueprintSources() {
  const sources = [{ name: 'website', url: 'https://wpwebhooks.org/blueprint.json' }];
  try {
    const info = await json(`https://wordpress.org/plugins/wp-json/plugins/v1/plugin/${SLUG}`);
    const url = info?.blueprints?.[0]?.url;
    if (url) sources.push({ name: 'wporg', url });
    else fail('wporg: the plugin has no published blueprint (Live Preview disabled?)');
  } catch (e) {
    fail(`wporg: could not read the plugin's blueprint list: ${e.message}`);
  }
  return sources;
}

// Cheap early warning that needs no browser: every wordpress.org plugin the
// blueprint installs must run on the PHP version the blueprint pins.
async function staticCheck({ name, url }) {
  let bp;
  try {
    bp = await json(url);
  } catch (e) {
    return fail(`${name}: blueprint not reachable or not JSON: ${e.message}`);
  }
  const php = String(bp?.preferredVersions?.php ?? '');
  const slugs = (bp.steps || [])
    .filter((s) => s.step === 'installPlugin')
    .map((s) => (s.pluginData || s.pluginZipFile || {}).slug)
    .filter(Boolean);
  for (const slug of slugs) {
    const info = await json(
      `https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=${slug}`,
    );
    const needs = String(info.requires_php || '');
    if (php && php !== 'latest' && needs && cmp(needs, php) > 0) {
      fail(`${name}: ${slug} ${info.version} requires PHP ${needs}, but the blueprint pins PHP ${php}`);
    } else {
      ok(`${name}: ${slug} ${info.version} (requires PHP ${needs || '—'}) fits PHP ${php || 'default'}`);
    }
  }
}

function cmp(a, b) {
  const pa = a.split('.').map(Number); const pb = b.split('.').map(Number);
  for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
    const d = (pa[i] || 0) - (pb[i] || 0);
    if (d) return d;
  }
  return 0;
}

// The real thing: boot Playground with the blueprint and look at what a visitor sees.
async function browserCheck(browser, { name, url }) {
  const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });
  const blueprintErrors = new Set();
  page.on('console', (m) => {
    const t = m.text();
    if (/BlueprintStepExecutionError|does not meet minimum requirements|could not be activated/i.test(t)) {
      blueprintErrors.add(t.split('\n')[0].slice(0, 200));
    }
  });

  await page.goto(`https://playground.wordpress.net/?blueprint-url=${encodeURIComponent(url)}`, { timeout: BOOT_MS });

  // Wait for the WordPress frame to land on the plugin screen.
  const deadline = Date.now() + BOOT_MS;
  let landed = null;
  while (Date.now() < deadline && !landed) {
    landed = page.frames().find((f) => f.url().includes(`page=${ADMIN_PAGE}`)) || null;
    if (!landed) await page.waitForTimeout(3000);
  }
  await page.waitForTimeout(8000); // let the admin SPA render
  await page.screenshot({ path: `${OUT}/${name}.png` });

  const shellText = await page.locator('body').innerText().catch(() => '');
  const appText = landed ? await landed.locator('body').innerText().catch(() => '') : '';

  if (blueprintErrors.size) fail(`${name}: blueprint step failed:\n    ${[...blueprintErrors].join('\n    ')}`);
  if (/Blueprint execution failed|Blueprint could not be downloaded/i.test(shellText)) {
    fail(`${name}: Playground shows an error dialog to the visitor`);
  }
  if (!landed) fail(`${name}: never reached the plugin screen within ${BOOT_MS / 1000}s`);
  else if (!/Build with AI/i.test(appText)) fail(`${name}: reached the plugin screen but "Build with AI" is not on it`);
  else if (!blueprintErrors.size) ok(`${name}: preview boots and lands on Build with AI`);

  await page.close();
}

const sources = await blueprintSources();
for (const s of sources) await staticCheck(s);

const browser = await chromium.launch();
for (const s of sources) {
  try {
    await browserCheck(browser, s);
  } catch (e) {
    fail(`${s.name}: ${e.message.split('\n')[0]}`);
  }
}
await browser.close();

if (failures.length) {
  console.log(`\n${failures.length} problem(s) with the Live Preview — screenshots in ${OUT}/`);
  process.exit(1);
}
console.log('\nLive Preview OK on every source.');
