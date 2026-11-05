import { test, expect, type Page } from '@playwright/test';
import { sectionForm } from './helpers';

/**
 * REQ-DEMO-013 — happy path of the Slack kit demo against the in-app fake Slack (no real workspace). Data: `make seed`.
 * Tests run in order: the account link state is shared through var/demo/identities.json.
 */
test.describe.configure({ mode: 'serial' });

async function resetLink(page: Page): Promise<void> {
  await page.goto('/');
  const unlink = page.getByRole('button', { name: 'Unlink' });
  if (await unlink.isVisible().catch(() => false)) {
    await unlink.click();
    await page.locator('dialog.nowo-ui-confirm[open]').getByRole('button', { name: 'Confirm' }).click();
    await expect(page.getByTestId('slack-kit-link-status')).toHaveAttribute('data-status', 'none');
  }
  await page.getByRole('button', { name: 'Clear the fake Slack log' }).click();
}

test.describe('Slack kit demo', () => {
  test('the slack section shows the fields and tests the connection', async ({ page }) => {
    await page.goto('/admin/settings/sections/slack');
    const form = sectionForm(page);
    await expect(form.getByText('Bot token')).toBeVisible();
    await expect(form.getByText('Signing secret')).toBeVisible();
    expect(await page.content()).not.toContain('xoxb-test-not-a-real-token');
    await page.getByRole('button', { name: 'Test connection' }).click();
    await expect(page.getByText('Connected to Demo workspace (T00000000) as demobot.')).toBeVisible();
  });

  test('claim, confirm from the fake Slack, then act through a signed button', async ({ page }) => {
    await resetLink(page);
    await expect(page.getByTestId('slack-kit-link-status')).toHaveAttribute('data-status', 'none');

    // A signed demo.ping click before linking: the actor contract refuses it.
    await page.getByRole('button', { name: 'Post a demo button to #ops' }).click();
    await page.locator('tr[data-action="demo.ping"]').first().getByRole('button', { name: 'Click as the linked member' }).click();
    await expect(page.getByText('Slack ingress answered 403')).toBeVisible();

    await page.getByLabel('Slack member id').fill('U00000001');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByText('Slack id saved. Send the confirmation to finish linking.')).toBeVisible();
    await expect(page.getByTestId('slack-kit-link-status')).toHaveAttribute('data-status', 'claimed');

    await page.getByRole('button', { name: 'Send confirmation' }).click();
    await expect(page.getByText('Confirmation sent: click Confirm in the Slack direct message.')).toBeVisible();

    await page.locator('tr[data-action="nowo_slack_kit.link.confirm"]').first().getByRole('button', { name: 'Click as the linked member' }).click();
    await expect(page.getByText('Slack ingress answered 200: Linked')).toBeVisible();
    await expect(page.getByTestId('slack-kit-link-status')).toHaveAttribute('data-status', 'verified');

    await page.locator('tr[data-action="demo.ping"]').first().getByRole('button', { name: 'Click as the linked member' }).click();
    await expect(page.getByText('Slack ingress answered 200: Pong for demo-account')).toBeVisible();
  });

  test('find by e-mail is a proven claim that still needs the click', async ({ page }) => {
    await resetLink(page);
    await page.getByRole('button', { name: 'Find me by e-mail' }).click();
    await expect(page.getByText('Slack found your account. Send the confirmation to finish linking.')).toBeVisible();
    await expect(page.getByTestId('slack-kit-link-status')).toHaveAttribute('data-status', 'claimed');
    await expect(page.getByTestId('demo-calls')).toContainText('users.lookupByEmail');
  });

  test('forged CSRF tokens are refused', async ({ request }) => {
    const claim = await request.post('/slack-kit/account/slack/claim', { form: { 'nowo_slack_kit_link[slack_user_id]': 'U00000001', 'nowo_slack_kit_link[_token]': 'forged' } });
    expect(claim.status()).toBe(403);
    const test = await request.post('/slack-kit/settings/test-connection', { form: { 'csrf_only[_token]': 'forged' } });
    expect(test.status()).toBe(403);
  });

  test('an unsigned request to the ingress is refused', async ({ request }) => {
    const response = await request.post('/slack/interactions', { form: { payload: '{}' } });
    expect(response.status()).toBe(401);
  });
});
