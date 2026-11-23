import { Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { useShared, useT } from '@/lib/i18n';
import { formatUsd } from '@/lib/money';
import { deliveryTime } from '@/lib/offer';
import type { OfferRow } from '@/types/offer';
import { SellerLine } from './SellerLine';

/**
 * "Other sellers (N)" panel (spec 1.5): delivery-method chips, sort, and one row per offer with seller,
 * delivery method and time, stock, minimum quantity, insurance and price ("Lowest price" tag).
 */
export function OffersTable({ offers, methods, selectedId, onSelect }: { offers: OfferRow[]; methods: string[]; selectedId: string | null; onSelect: (id: string) => void }) {
    const t = useT();
    const { locale } = useShared();
    const [method, setMethod] = useState<string | null>(null);
    const [sort, setSort] = useState<'recommended' | 'price_asc'>('recommended');

    const lowest = offers.reduce((min, o) => Math.min(min, o.priceCents), Number.POSITIVE_INFINITY);
    const rows = useMemo(() => {
        const filtered = method === null ? offers : offers.filter((o) => o.deliveryMethod === method);

        return sort === 'price_asc' ? [...filtered].sort((a, b) => a.priceCents - b.priceCents) : filtered;
    }, [offers, method, sort]);

    return (
        <section id="other-sellers" className="card p-5" aria-labelledby="other-sellers-title">
            <div className="mb-4 flex flex-wrap items-center gap-3">
                <h2 id="other-sellers-title" className="text-lg font-bold">
                    {t('offer.other_sellers', { count: offers.length })}
                </h2>
                <label className="ms-auto flex items-center gap-2 text-sm">
                    <span className="text-muted">{t('catalog.sort.label')}</span>
                    <select className="field w-auto py-1.5" value={sort} onChange={(e) => setSort(e.target.value as 'recommended' | 'price_asc')}>
                        <option value="recommended">{t('offer.sort.recommended')}</option>
                        <option value="price_asc">{t('offer.sort.price_asc')}</option>
                    </select>
                </label>
            </div>

            {methods.length > 1 && (
                <div className="mb-4 flex flex-wrap gap-2" role="group" aria-label={t('offer.delivery_method')}>
                    {[null, ...methods].map((m) => (
                        <button
                            key={m ?? 'all'}
                            type="button"
                            aria-pressed={method === m}
                            onClick={() => setMethod(m)}
                            className={`rounded-full border px-3 py-1 text-sm font-semibold ${method === m ? 'border-primary bg-primary-soft text-primary' : 'border-line hover:border-primary'}`}
                        >
                            {m === null ? t('offer.all_methods') : t(`offer.delivery.${m}`)}
                        </button>
                    ))}
                </div>
            )}

            <div className="overflow-x-auto">
                <table className="w-full min-w-[640px] text-sm">
                    <thead className="text-xs uppercase text-muted">
                        <tr>
                            <th className="py-2 text-start">{t('offer.col.seller')}</th>
                            <th className="py-2 text-start">{t('offer.delivery_method')}</th>
                            <th className="py-2 text-start">{t('offer.delivery_time')}</th>
                            <th className="py-2 text-start">{t('offer.col.stock')}</th>
                            <th className="py-2 text-start">{t('offer.col.min_qty')}</th>
                            <th className="py-2 text-start">{t('offer.insurance')}</th>
                            <th className="py-2 text-end">{t('offer.col.price')}</th>
                            <th className="py-2" />
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((o) => (
                            <tr key={o.id} className={`border-t border-line align-middle ${selectedId === o.id ? 'bg-primary-soft/40' : ''}`}>
                                <td className="py-2.5 pe-2">
                                    <SellerLine seller={o.seller} />
                                    <Link href={o.href} className="block truncate text-xs text-muted hover:text-primary">
                                        {o.title}
                                    </Link>
                                </td>
                                <td className="py-2.5">{t(`offer.delivery.${o.deliveryMethod}`)}</td>
                                <td className="py-2.5">{o.auto ? t('offer.instant') : deliveryTime(o.deliveryMinutes, t)}</td>
                                <td className="py-2.5">{o.stock}</td>
                                <td className="py-2.5">{o.minQty}</td>
                                <td className="py-2.5">{o.insuranceDays > 0 ? t('offer.insurance_days', { count: o.insuranceDays }) : '—'}</td>
                                <td className="py-2.5 text-end font-bold">
                                    {o.priceCents === lowest && <span className="me-2 rounded bg-success/10 px-1.5 py-0.5 text-[11px] font-semibold text-success">{t('offer.lowest')}</span>}
                                    {formatUsd(o.priceCents, locale)}
                                </td>
                                <td className="py-2.5 text-end">
                                    <button type="button" className="btn-outline-pill px-3 py-1 text-xs" onClick={() => onSelect(o.id)}>
                                        {t('offer.select')}
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </section>
    );
}
