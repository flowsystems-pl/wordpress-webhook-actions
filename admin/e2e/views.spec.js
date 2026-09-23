import { test, expect } from '@playwright/test';

// Keep in sync with `navItems` in admin/src/App.vue.
const ROUTES = [
  'ai-builder',
  'webhooks',
  'logs',
  'queue',
  'tokens',
  'vault',
  'notifications',
  'external-cron',
  'activity',
  'settings',
  'pro',
];

const PLUGIN_PAGE = '/wp-admin/admin.php?page=fswa-webhook-actions';

async function gotoView(page, route) {
  await page.goto(`${PLUGIN_PAGE}#/${route}`);
  await page.waitForSelector('#fswa-app nav', { state: 'attached' });
  // Let async data (health status, provider status, etc.) settle before measuring.
  await page.waitForLoadState('networkidle');
}

// The one layout invariant every view must hold, on every breakpoint: nothing
// pushes the page wider than the viewport. This is exactly the class of bug
// the AI-builder model bar shipped with (badges + buttons refusing to wrap),
// so it doubles as a regression guard for that fix.
async function expectNoHorizontalOverflow(page) {
  const overflow = await page.evaluate(() => {
    const doc = document.documentElement;
    return doc.scrollWidth - doc.clientWidth;
  });
  expect(overflow, 'page should not scroll horizontally').toBeLessThanOrEqual(1);
}

for (const route of ROUTES) {
  test(`${route}: renders with no horizontal overflow`, async ({ page }) => {
    await gotoView(page, route);
    await expectNoHorizontalOverflow(page);
  });
}

test('mobile: main nav collapses into a dropdown', async ({ page, isMobile }) => {
  test.skip(!isMobile, 'desktop project keeps the full tab strip');
  await gotoView(page, 'webhooks');

  const tabStrip = page.locator('#fswa-app nav').first();
  await expect(tabStrip).toBeHidden();

  const trigger = page.getByRole('button', { name: /webhooks/i });
  await expect(trigger).toBeVisible();

  await trigger.click();
  // Every route should be reachable from the open dropdown.
  await expect(page.getByRole('link', { name: /build with ai/i })).toBeVisible();
  await expect(page.getByRole('link', { name: /credentials vault/i })).toBeVisible();

  await page.getByRole('link', { name: /^logs$/i }).click();
  await expect(page).toHaveURL(/#\/logs/);
});

test('desktop: full nav strip stays visible', async ({ page, isMobile }) => {
  test.skip(isMobile, 'mobile project uses the dropdown');
  await gotoView(page, 'webhooks');
  await expect(page.getByRole('link', { name: /build with ai/i })).toBeVisible();
  await expect(page.getByRole('link', { name: /credentials vault/i })).toBeVisible();
});
