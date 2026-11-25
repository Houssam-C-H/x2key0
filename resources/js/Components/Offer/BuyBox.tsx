import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useShared, useT } from '@/lib/i18n';
import { formatUsd } from '@/lib/money';
import { deliveryTime, unitPriceFor } from '@/lib/offer';
import type { OfferRow } from '@/types/offer';
import { FavoriteButton } from './FavoriteButton';
import { SellerLine } from './SellerLine';

interface Props {
    offer: OfferRow;
    msrpCents?: number | null;
    otherCount?: number;
    otherFromCents?: number | null;
    recommended?: boolean;
}

/**
 * Buy box (spec 1.5): seller, stock label, quantity − / +, unit price, total, MSRP, Buy Now, fee note.
 * Buy Now opens checkout with the chosen quantity; guests sign in first.
 */
/**
 * "Buy more, save more" (spec 1.5 quantity tiers): every tier with its unit price and saving, the active one
 * highlighted, clickable to set the quantity, and a nudge towards the next tier.
 */
function QuantityTiers({ base, tiers, qty, max, onPick }: { base: number; tiers: { minQty: number; priceCents: number }[]; qty: number; max: number; onPick: (qty: number) => void }) {
    const t = useT();
    const { locale } = useShared();
    const rows = [{ minQty: 1, priceCents: base }, ...[...tiers].sort((a, b) => a.minQty - b.minQty)];
    const active = rows.reduce((current, row) => (qty >= row.minQty ? row : current), rows[0]!);
    const next = rows.find((row) => row.minQty > qty && row.minQty <= max);

    return (
        <div className="rounded-xl border border-primary/20 bg-primary-soft/50 p-3">
            <p className="mb-2 text-xs font-bold uppercase tracking-wide text-primary">{t('offer.tiers.title')}</p>
            <ul className="space-y-1">
                {rows.map((row) => {
                    const saving = base > 0 ? Math.round(((base - row.priceCents) / base) * 100) : 0;
                    const on = row === active;
                    return (
                        <li key={row.minQty}>
                            <button
                                type="button"
                                disabled={row.minQty > max}
                                onClick={() => onPick(row.minQty)}
                                className={
                                    'flex w-full items-center justify-between gap-2 rounded-lg px-2.5 py-1.5 text-sm transition disabled:opacity-40 ' +
                                    (on ? 'bg-white font-semibold shadow-sm ring-1 ring-primary/30' : 'hover:bg-white/70')
                                }
                            >
                                <span>{t('offer.tiers.from_qty', { qty: row.minQty })}</span>
                                <span className="flex items-center gap-2">
                                    <span>{t('offer.tiers.each', { price: formatUsd(row.priceCents, locale) ?? '' })}</span>
                                    {saving > 0 && <span className="rounded-md bg-success/10 px-1.5 py-0.5 text-[11px] font-bold text-success">−{saving}%</span>}
                                </span>
                            </button>
                        </li>
                    );
                })}
            </ul>
            {next && (
                <p className="mt-2 text-xs text-ink/80">
                    {t('offer.tiers.nudge', { more: next.minQty - qty, price: formatUsd(next.priceCents, locale) ?? '' })}
                </p>
            )}
        </div>
    );
}

export function BuyBox({ offer, msrpCents = null, otherCount = 0, otherFromCents = null, recommended = false }: Props) {
    const t = useT();
    const { locale, auth } = useShared();
    const step = offer.integerMultiple ? offer.minQty : 1;
    const max = Math.max(offer.minQty, Math.min(offer.maxQty ?? offer.stock, offer.stock));
    const [qty, setQty] = useState(offer.minQty);

    // Room under the page for the sticky buy bar on phones (removed when the page changes).
    useEffect(() => {
        document.body.classList.add('pb-24', 'lg:pb-0');
        return () => document.body.classList.remove('pb-24', 'lg:pb-0');
    }, []);
    const unit = unitPriceFor(qty, offer.priceCents, offer.tiers);
    const soldOut = offer.stock < offer.minQty;

    const clamp = (n: number) => {
        let next = Math.max(offer.minQty, Math.min(max, n));
        if (offer.integerMultiple) next = Math.max(offer.minQty, Math.floor(next / step) * step);

        return next;
    };

    return (
        <div className="space-y-4">
            {recommended && <span className="inline-block rounded-full bg-primary-soft px-2.5 py-0.5 text-xs font-bold text-primary">{t('offer.recommended')}</span>}
            <div className="flex items-start justify-between gap-2 text-sm">
                <SellerLine seller={offer.seller} />
                <FavoriteButton type="offer" id={offer.id} active={offer.favorited} className="shrink-0 px-2 py-1" />
            </div>
            <p className={`text-sm font-semibold ${soldOut ? 'text-danger' : 'text-success'}`}>{t(`offer.stock.${soldOut ? 'out_of_stock' : offer.stockLabel}`)}</p>

            <div className="flex items-center gap-3">
                <div className="flex items-center rounded-md border border-line" role="group" aria-label={t('offer.quantity')}>
                    <button type="button" className="px-3 py-2 text-lg" disabled={soldOut || qty <= offer.minQty} onClick={() => setQty(clamp(qty - step))} aria-label="−">
                        −
                    </button>
                    <input
                        className="w-16 border-x border-line py-2 text-center"
                        inputMode="numeric"
                        value={qty}
                        disabled={soldOut}
                        aria-label={t('offer.quantity')}
                        onChange={(e) => setQty(clamp(Number(e.target.value.replace(/\D/g, '')) || offer.minQty))}
                    />
                    <button type="button" className="px-3 py-2 text-lg" disabled={soldOut || qty + step > max} onClick={() => setQty(clamp(qty + step))} aria-label="+">
                        +
                    </button>
                </div>
                <span className="text-sm text-muted">
                    {offer.unitLabel ?? '1'} = USD {formatUsd(unit, locale)}
                </span>
            </div>
            {offer.tiers.length > 0 && (
                <QuantityTiers
                    base={offer.priceCents}
                    tiers={offer.tiers}
                    qty={qty}
                    max={max}
                    onPick={(n) => setQty(clamp(n))}
                />
            )}

            <div>
                {msrpCents !== null && msrpCents > unit && <p className="text-sm text-muted line-through">{formatUsd(msrpCents * qty, locale)}</p>}
                <p className="text-3xl font-extrabold">{formatUsd(unit * qty, locale)}</p>
                <p className="text-xs text-muted">{t('catalog.product.fees_note')}</p>
            </div>

            <button
                type="button"
                className="btn-primary h-12 w-full"
                disabled={soldOut}
                onClick={() => router.visit(auth.user ? `/checkout?offer=${offer.id}&qty=${qty}` : `/${locale}/login`)}
            >
                {t('catalog.product.buy_now')}
            </button>

            {otherCount > 0 && otherFromCents !== null && (
                <a href="#other-sellers" className="block text-sm font-semibold text-primary hover:underline">
                    {t('offer.more_offers', { count: otherCount, price: formatUsd(otherFromCents, locale) ?? '' })}
                </a>
            )}

            {/* Phones/tablets: the price and Buy now stay at the bottom of the screen. */}
            <div className="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-white/95 px-4 pt-3 shadow-[0_-4px_16px_rgb(0_0_0/0.06)] backdrop-blur lg:hidden" style={{ paddingBottom: 'calc(0.75rem + env(safe-area-inset-bottom, 0px))' }}>
                <div className="mx-auto flex max-w-xl items-center gap-3">
                    <div className="min-w-0 flex-1">
                        <p className="text-lg font-extrabold leading-tight">{formatUsd(unit * qty, locale)}</p>
                        <p className="truncate text-xs text-muted">
                            {qty} × {offer.unitLabel ?? '1'}
                        </p>
                    </div>
                    <button
                        type="button"
                        className="btn-primary h-11 px-6"
                        disabled={soldOut}
                        onClick={() => router.visit(auth.user ? `/checkout?offer=${offer.id}&qty=${qty}` : `/${locale}/login`)}
                    >
                        {t('catalog.product.buy_now')}
                    </button>
                </div>
            </div>

            <dl className="grid grid-cols-2 gap-2 border-t border-line pt-3 text-sm">
                <dt className="text-muted">{t('offer.delivery_method')}</dt>
                <dd className="font-medium">{t(`offer.delivery.${offer.deliveryMethod}`)}</dd>
                <dt className="text-muted">{t('offer.delivery_time')}</dt>
                <dd className="font-medium">{offer.auto ? t('offer.instant') : deliveryTime(offer.deliveryMinutes, t)}</dd>
                <dt className="text-muted">{t('offer.insurance')}</dt>
                <dd className="font-medium">{offer.insuranceDays > 0 ? t('offer.insurance_days', { count: offer.insuranceDays }) : '—'}</dd>
            </dl>
        </div>
    );
}
