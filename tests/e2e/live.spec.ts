import { expect, test, type Page } from '@playwright/test';
import { fx, login, quiet } from './helpers';

/**
 * Live chat over Reverb (ADR-021): a message from the buyer reaches the seller's open chat through the websocket,
 * well before the 5-second polling would. Skipped when the app has no Reverb key configured.
 */
test.skip(({ isMobile }) => isMobile, 'desktop only: one live check is enough');

/** Resolves with the conversation.updated frames the page receives over its websocket. */
function watchSocket(page: Page): { subscribed: Promise<void>; signals: string[] } {
    const signals: string[] = [];
    let ready!: () => void;
    const subscribed = new Promise<void>((resolve) => (ready = resolve));
    page.on('websocket', (ws) => {
        ws.on('framereceived', ({ payload }) => {
            const text = typeof payload === 'string' ? payload : payload.toString();
            if (text.includes('pusher_internal:subscription_succeeded')) ready();
            if (text.includes('conversation.updated')) signals.push(text);
        });
    });
    return { subscribed, signals };
}

test('a chat message arrives live over the websocket', async ({ browser }) => {
    test.setTimeout(180_000); // two sign-ins
    const f = fx();
    const buyerCtx = await browser.newContext();
    const sellerCtx = await browser.newContext();
    const buyer = await buyerCtx.newPage();
    const seller = await sellerCtx.newPage();
    await quiet(buyer);
    await quiet(seller);

    await login(buyer, f.buyer);
    await buyer.goto('/en/store/e2e-store');
    await buyer.getByRole('button', { name: 'Contact seller' }).click();
    await buyer.waitForURL(/\/chat\/[0-9a-z]{26}$/);
    const chatPath = new URL(buyer.url()).pathname;

    const socket = watchSocket(seller);
    await login(seller, f.seller);
    await seller.goto(chatPath);
    const live = await Promise.race([socket.subscribed.then(() => true), seller.waitForTimeout(10_000).then(() => false)]);
    test.skip(!live, 'Reverb is not configured or not running (seller never subscribed to the private channel)');

    const text = `Live check ${Math.random().toString(36).replace(/[^a-z]/g, "").slice(0, 8)}`; // no digits: long numbers are masked as phone numbers
    await buyer.getByRole('textbox', { name: 'Write a message' }).fill(text);
    await buyer.getByRole('button', { name: 'Send', exact: true }).click();

    // The signal must come over the websocket (not the 5 s polling); the thread then refreshes on it.
    await expect.poll(() => socket.signals.length, { message: 'conversation.updated frame received', timeout: 10_000 }).toBeGreaterThan(0);
    await expect(seller.getByText(text)).toBeVisible({ timeout: 15_000 });

    await buyerCtx.close();
    await sellerCtx.close();
});
