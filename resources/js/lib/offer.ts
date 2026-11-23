import type { Translate } from '@/lib/i18n';

/** "15 min", "2 h", "2 days" from minutes (DELIVERY ETA, spec 1.8 #9). */
export function deliveryTime(minutes: number, t: Translate): string {
    if (minutes < 60) return t('offer.time.minutes', { count: minutes });
    if (minutes < 1440 || minutes % 1440 !== 0) return t('offer.time.hours', { count: Math.round(minutes / 60) });

    return t('offer.time.days', { count: minutes / 1440 });
}

/** Unit price for a quantity, following the quantity discount tiers. */
export function unitPriceFor(qty: number, priceCents: number, tiers: { minQty: number; priceCents: number }[]): number {
    let price = priceCents;
    for (const tier of tiers) {
        if (qty >= tier.minQty) price = tier.priceCents;
    }

    return price;
}

/** Decimal string ("12.5") → integer cents, or null when it is not a valid amount. No floating-point math. */
export function toCents(value: string): number | null {
    const match = /^(\d{1,7})(?:\.(\d{1,2}))?$/.exec(value.trim());
    if (!match) return null;

    return Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'));
}

/** Reads Laravel's XSRF-TOKEN cookie for fetch() calls that are not Inertia visits. */
export function xsrfToken(): string {
    const match = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));

    return match ? decodeURIComponent(match.slice('XSRF-TOKEN='.length)) : '';
}

/** Integer cents → "12.50" for inputs, without floating-point math. */
export function toDecimal(cents: number): string {
    return `${Math.floor(cents / 100)}.${String(cents % 100).padStart(2, '0')}`;
}
