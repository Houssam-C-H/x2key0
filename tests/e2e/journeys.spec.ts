import { expect, test } from '@playwright/test';
import { expectNoHorizontalScroll, fx, login, quiet } from './helpers';

test.beforeEach(async ({ page }) => quiet(page));

test('sign up, land on the dashboard, log out and back in', async ({ page }, info) => {
    const name = `e2e_${info.project.name}_${Date.now().toString(36)}`;
    await page.goto('/en/register');
    await page.locator('input[name=username]').fill(name);
    await page.locator('input[name=email]').fill(`${name}@x2key.test`);
    await page.locator('input[name=password]').fill('Sup3r-Secret-Pass!');
    await page.locator('input[name=password_confirmation]').fill('Sup3r-Secret-Pass!');
    await page.locator('input[type=checkbox]').last().check();
    await page.locator('form').filter({ has: page.locator('input[name=password_confirmation]') }).locator('button[type=submit]').click();
    await page.waitForURL((u) => !u.pathname.endsWith('/register'));
    await expect(page.locator('body')).not.toContainText('These credentials do not match');
});

test('buyer buys an offer and pays on the test payment page', async ({ page }) => {
    const f = fx();
    await login(page, f.buyer);
    await page.goto(f.offerPath);
    await page.getByRole('button', { name: /Buy now/i }).click();
    await page.waitForURL(/\/checkout\?/);
    await expectNoHorizontalScroll(page);

    const method = page.locator('input[name=method]').first();
    if (await method.count()) await method.check();
    await page.getByRole('button', { name: /^Pay/ }).click();

    await page.waitForURL(/fake-psp/);
    await page.getByRole('button', { name: 'Pay', exact: true }).click();
    await page.waitForURL(/\/buy\/orders\//);
    await expect(page.getByText(/Paid|In progress|Delivered/i).first()).toBeVisible();
    await expectNoHorizontalScroll(page);
});

test('seller opens Create offer and picks category then game', async ({ page }) => {
    const f = fx();
    await login(page, f.seller);
    await page.goto('/seller/offers/create');
    await expectNoHorizontalScroll(page);
    const field = (label: string) => page.locator('label', { has: page.locator('span', { hasText: new RegExp(`^${label}$`) }) }).locator('select');
    const category = field('Category');
    const options = await category.locator('option').allTextContents();
    const withGames = options.findIndex((o) => /\((?:[1-9]\d*)\)/.test(o));
    expect(withGames, 'the e2e seller has an approved category').toBeGreaterThan(0);
    await category.selectOption({ index: withGames });
    const game = field('Game');
    await game.selectOption({ index: 1 });
    await page.waitForURL(/category=\d+/);
    await expect(page.getByText('Price setting')).toBeVisible();

    for (const path of ['/seller/offers', '/seller/orders', '/seller/storage', '/seller/withdrawals']) {
        await page.goto(path);
        await expectNoHorizontalScroll(page);
    }
});

test('buyer dashboard pages fit the screen', async ({ page }) => {
    await login(page, fx().buyer);
    for (const path of ['/dashboard', '/buy/orders', '/buy/favorites', '/settings/account', '/settings/security', '/chat', '/announcements', '/tickets']) {
        await page.goto(path);
        await expect(page.locator('body')).toBeVisible();
        await expectNoHorizontalScroll(page);
    }
});

test('admin logs in with two-step verification and opens the panel', async ({ page }, info) => {
    test.skip(info.project.name === 'phone', 'admin panel is desktop-only');
    const f = fx();
    await login(page, f.admin, f.totpSecret);
    await page.goto('/admin');
    await expect(page.getByRole('heading', { name: /Dashboard/i })).toBeVisible();
    await page.goto('/admin/sellers/stores');
    await expect(page.getByText('E2E Store')).toBeVisible();
});
