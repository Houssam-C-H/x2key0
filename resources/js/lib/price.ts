import { useShared } from '@/lib/i18n';
import { formatUsd } from '@/lib/money';

/**
 * Display prices (ADR-036): USD cents shown in the buyer's display currency when a fresh rate exists
 * (shared `fx`), otherwise in USD. Approximate only — every charge, balance and payout stays in USD, so money
 * screens (checkout totals, wallets, seller pages) keep formatUsd.
 */
export function usePrice(): (cents: number | null | undefined) => string {
    const { locale, fx } = useShared();

    return (cents) => {
        if (cents === null || cents === undefined) return '';
        if (!fx) return formatUsd(cents, locale) ?? '';

        return new Intl.NumberFormat(locale === 'ar' ? 'ar-MA' : locale, { style: 'currency', currency: fx.currency, maximumFractionDigits: 2 }).format((cents / 100) * fx.rate);
    };
}
