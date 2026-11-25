import { expect, test } from '@playwright/test';
import { expectNoHorizontalScroll, fx, quiet } from './helpers';

test.beforeEach(async ({ page }) => quiet(page));

test('home: hero, search, categories, sections', async ({ page }) => {
    await page.goto('/en');
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Level up');
    await expect(page.getByRole('heading', { name: /Select Categories/ })).toBeVisible();
    await expect(page.getByRole('heading', { name: /Hot games/ })).toBeVisible();
    await expectNoHorizontalScroll(page);

    await page.getByRole('main').getByRole('searchbox').fill('steam');
    await page.getByRole('main').getByRole('button', { name: 'Search' }).click();
    await page.waitForURL(/\/en\/search\?q=steam/);
    await expectNoHorizontalScroll(page);
});

test('browse menu: categories and popular games', async ({ page }) => {
    await page.goto('/en');
    await page.getByRole('button', { name: 'Menu' }).click();
    const menu = page.getByRole('dialog', { name: 'Browse by category' });
    await expect(menu).toBeVisible();
    await expect(menu.getByRole('heading', { name: /Popular in/ })).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(menu).toBeHidden();
});

test('category hub, game page and offer listing with filters', async ({ page }) => {
    await page.goto('/en/catalog/accounts');
    await expect(page.getByRole('heading', { level: 1, name: 'Accounts' })).toBeVisible();
    await expectNoHorizontalScroll(page);

    await page.goto('/en/fortnite/accounts');
    await expect(page.getByText(/\d+ offers?/).first()).toBeVisible();
    await expectNoHorizontalScroll(page);

    await page.goto('/en/fortnite');
    await expect(page.getByRole('heading', { level: 1, name: 'Fortnite' })).toBeVisible();
    await expectNoHorizontalScroll(page);
});

test('offer page shows the buy box', async ({ page }) => {
    await page.goto(fx().offerPath);
    await expect(page.getByText('E2E test key').first()).toBeVisible();
    await expect(page.getByRole('button', { name: /Buy now/i })).toBeVisible();
    await expectNoHorizontalScroll(page);
});

test('static pages and sign-in screens fit the screen', async ({ page }) => {
    for (const path of ['/en/login', '/en/register', '/en/forgot-password', '/en/games', '/en/help', '/en/news']) {
        await page.goto(path);
        await expect(page.locator('body')).toBeVisible();
        await expectNoHorizontalScroll(page);
    }
});
