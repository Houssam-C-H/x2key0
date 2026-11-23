/** Formats integer USD cents for display. Settlement is always USD (D5); other currencies come with fx rates later. */
export function formatUsd(cents: number | null | undefined, locale: string): string | null {
    if (cents === null || cents === undefined) return null;

    return new Intl.NumberFormat(locale === 'ar' ? 'ar-MA' : locale, { style: 'currency', currency: 'USD' }).format(cents / 100);
}

export function formatCount(n: number, locale: string): string {
    return new Intl.NumberFormat(locale).format(n);
}
