import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { sectionForm, waitForWebProfiler } from './helpers';

/** REQ-DEMO-013 — README screenshots (captured as PNG, converted to WebP by scripts/to-webp.mjs). */
const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test.describe('Slack kit screenshots', () => {
  test.use({ viewport: { width: 1280, height: 1100 } });

  test('slack-settings — slack section after "Test connection"', async ({ page }) => {
    await page.goto('/admin/settings/sections/slack');
    await page.getByRole('button', { name: 'Test connection' }).click();
    await expect(page.getByText('Connected to Demo workspace (T00000000) as demobot.')).toBeVisible();
    await expect(sectionForm(page)).toBeVisible();
    await waitForWebProfiler(page);
    await page.screenshot({ path: resolve(outDir, 'slack-settings.png'), fullPage: true });
  });

  test('home — account link card and fake Slack log', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByTestId('demo-link-card')).toBeVisible();
    await waitForWebProfiler(page);
    await page.screenshot({ path: resolve(outDir, 'home.png'), fullPage: true });
  });
});
