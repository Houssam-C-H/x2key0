import { createHmac } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { expect, type Page } from '@playwright/test';

export interface Fixtures {
    password: string;
    totpSecret: string;
    buyer: string;
    seller: string;
    admin: string;
    offerPath: string;
    offerId: string;
    gameCategoryId: number;
}

export const fx = (): Fixtures => JSON.parse(readFileSync('storage/e2e-results/fixtures.json', 'utf8')) as Fixtures;

/** Cookie choice made and bookmark prompt hidden, so overlays never cover what a test clicks. */
export async function quiet(page: Page): Promise<void> {
    await page.addInitScript(() => {
        document.cookie = 'cookie_consent=declined; path=/; SameSite=Lax';
        sessionStorage.setItem('x2k_bookmark_hidden', '1');
    });
}

/** RFC 6238 TOTP (SHA-1, 6 digits, 30 s) from a base32 secret. */
export function totp(secret: string, at = Date.now()): string {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const c of secret.replace(/=+$/, '').toUpperCase()) bits += alphabet.indexOf(c).toString(2).padStart(5, '0');
    const key = Buffer.from(bits.match(/.{8}/g)!.map((b) => parseInt(b, 2)));
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(at / 1000 / 30)));
    const h = createHmac('sha1', key).update(counter).digest();
    const o = h[h.length - 1]! & 0xf;
    return (((h.readUInt32BE(o) & 0x7fffffff) % 1_000_000) + '').padStart(6, '0');
}

export async function login(page: Page, username: string, twoFactorSecret?: string): Promise<void> {
    const f = fx();
    await page.goto('/en/login');
    await page.locator('input[name=login]').fill(username);
    await page.locator('input[name=password]').fill(f.password);
    await page.locator('form').filter({ has: page.locator('input[name=password]') }).getByRole('button', { name: 'Log in' }).click();
    if (twoFactorSecret) {
        await page.waitForURL('**/two-factor-challenge');
        await page.locator('input[name=code]').fill(totp(twoFactorSecret));
        await page.locator('form').filter({ has: page.locator('input[name=code]') }).locator('button[type=submit]').click();
    }
    await page.waitForURL((url) => !url.pathname.endsWith('/login') && !url.pathname.includes('two-factor'));
}

/** No sideways scrolling: the page is never wider than the screen (phone pass). */
export async function expectNoHorizontalScroll(page: Page): Promise<void> {
    const overflow = await page.evaluate(() => {
        const doc = document.documentElement;
        const wide = [...document.querySelectorAll<HTMLElement>('body *')]
            .filter((el) => el.getBoundingClientRect().right > doc.clientWidth + 1 && getComputedStyle(el).position !== 'fixed')
            .filter((el) => !el.closest('[class*="overflow-x-auto"], [class*="overflow-auto"], [class*="no-scrollbar"], [class*="overflow-hidden"]'))
            .slice(0, 5)
            .map((el) => `${el.tagName.toLowerCase()}.${String(el.className).split(' ').slice(0, 3).join('.')}`);
        return { scroll: doc.scrollWidth - doc.clientWidth, wide };
    });
    expect(overflow, `page wider than the screen; widest elements: ${overflow.wide.join(', ')}`).toEqual({ scroll: 0, wide: [] });
}
