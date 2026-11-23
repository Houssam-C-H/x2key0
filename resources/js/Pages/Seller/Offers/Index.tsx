import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Pagination } from '@/Components/Catalog/Pagination';
import { DashboardLayout, Panel } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';
import { formatUsd } from '@/lib/money';
import { deliveryTime, toDecimal } from '@/lib/offer';
import type { Paged } from '@/types/offer';

export type OfferStatus = 'draft' | 'pending_publish' | 'pending_review' | 'active' | 'deactivated' | 'out_of_stock' | 'expired' | 'system_deactivated' | 'rejected';

interface Row {
    id: number;
    publicId: string;
    title: string;
    href: string;
    service: string;
    status: OfferStatus;
    statusReason: string | null;
    duplicateOf: number | null;
    deliveryMethod: string;
    deliveryMinutes: number;
    auto: boolean;
    storageGroup: string | null;
    stock: number;
    minQty: number;
    priceCents: number;
    sort: number;
    expiresAt: string | null;
    createdAt: string;
    canDeactivate: boolean;
    canRelist: boolean;
}

interface Filters {
    tab: string;
    sort: string;
    perPage: number;
    service: number | null;
    q: string;
}

interface Props {
    offers: Paged<Row>;
    counts: Record<string, number>;
    services: { id: number; title: string; count: number }[];
    filters: Filters;
    tabs: string[];
    sorts: string[];
    perPageOptions: number[];
    listingDays: number[];
    readOnly: boolean;
}

export const STATUS_STYLE: Record<OfferStatus, string> = {
    draft: 'bg-canvas text-muted',
    pending_publish: 'bg-canvas text-muted',
    pending_review: 'bg-warning/15 text-warning',
    active: 'bg-success/10 text-success',
    deactivated: 'bg-canvas text-muted',
    out_of_stock: 'bg-warning/15 text-warning',
    expired: 'bg-canvas text-muted',
    system_deactivated: 'bg-danger/10 text-danger',
    rejected: 'bg-danger/10 text-danger',
};

/** Active Offers (spec 1.8): service chips, status tabs with counts, filters, batch buttons, inline price / sort / stock. */
export default function OffersIndex({ offers, counts, services, filters, tabs, sorts, perPageOptions, listingDays, readOnly }: Props) {
    const t = useT();
    const { locale, errors } = useShared();
    const [selected, setSelected] = useState<number[]>([]);
    const [q, setQ] = useState(filters.q);
    const [days, setDays] = useState(listingDays[0] ?? 7);
    const [confirmDelete, setConfirmDelete] = useState(false);

    const visit = (patch: Partial<Filters>, page = 1) => {
        const next = { ...filters, ...patch };
        router.get(
            '/seller/offers',
            { tab: next.tab, sort: next.sort, per_page: next.perPage, service: next.service ?? undefined, q: next.q || undefined, page: page > 1 ? page : undefined },
            { preserveScroll: true, preserveState: true, replace: true },
        );
        setSelected([]);
    };

    const bulk = (operation: string) => {
        router.post('/seller/offers/bulk', { operation, ids: selected, days }, { preserveScroll: true, onSuccess: () => setSelected([]) });
        setConfirmDelete(false);
    };

    const inline = (id: number, field: 'price' | 'sort' | 'stock', value: string) => router.patch(`/seller/offers/${id}/inline`, { field, value }, { preserveScroll: true, preserveState: true });

    const allChecked = offers.items.length > 0 && offers.items.every((o) => selected.includes(o.id));

    return (
        <DashboardLayout title={t('menu.seller.offers')} bare>
            {readOnly && <p className="rounded-md bg-danger/5 p-3 text-sm text-danger">{t('seller.error.suspended')}</p>}

            <Panel title={t('offer.manage.services')}>
                <div className="flex flex-wrap gap-2">
                    <button type="button" onClick={() => visit({ service: null })} className={`rounded-full border px-3 py-1 text-sm font-semibold ${filters.service === null ? 'border-primary bg-primary-soft text-primary' : 'border-line'}`}>
                        {t('offer.manage.all_services')}
                    </button>
                    {services.map((s) => (
                        <button
                            key={s.id}
                            type="button"
                            onClick={() => visit({ service: s.id })}
                            className={`rounded-full border px-3 py-1 text-sm font-semibold ${filters.service === s.id ? 'border-primary bg-primary-soft text-primary' : 'border-line hover:border-primary'}`}
                        >
                            {s.title} <span className="text-muted">· {t('catalog.offers', { count: s.count })}</span>
                        </button>
                    ))}
                    <Link href="/seller/offers/create" className="btn-primary ms-auto px-4 py-1.5 text-sm">
                        + {t('menu.seller.offers_create')}
                    </Link>
                </div>
            </Panel>

            <section className="card p-0">
                <nav className="flex gap-1 overflow-x-auto border-b border-line px-3" aria-label={t('offer.manage.tabs')}>
                    {tabs.map((tab) => (
                        <button
                            key={tab}
                            type="button"
                            aria-current={filters.tab === tab ? 'page' : undefined}
                            onClick={() => visit({ tab })}
                            className={`whitespace-nowrap border-b-2 px-3 py-3 text-sm font-semibold ${filters.tab === tab ? 'border-primary text-primary' : 'border-transparent text-muted hover:text-ink'}`}
                        >
                            {t(`offer.tab.${tab}`)} <span className="text-xs">({counts[tab] ?? 0})</span>
                        </button>
                    ))}
                </nav>

                <div className="space-y-4 p-4">
                    <form
                        className="flex flex-wrap items-end gap-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            visit({ q });
                        }}
                    >
                        <label className="flex min-w-[200px] flex-1 flex-col gap-1 text-sm">
                            <span className="font-medium">{t('offer.manage.search')}</span>
                            <input className="field" value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('offer.manage.search_hint')} />
                        </label>
                        <label className="flex flex-col gap-1 text-sm">
                            <span className="font-medium">{t('catalog.sort.label')}</span>
                            <select className="field" value={filters.sort} onChange={(e) => visit({ sort: e.target.value })}>
                                {sorts.map((s) => (
                                    <option key={s} value={s}>
                                        {t(`offer.manage.sort.${s}`)}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="flex flex-col gap-1 text-sm">
                            <span className="font-medium">{t('offer.manage.per_page')}</span>
                            <select className="field" value={filters.perPage} onChange={(e) => visit({ perPage: Number(e.target.value) })}>
                                {perPageOptions.map((n) => (
                                    <option key={n} value={n}>
                                        {n}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <button type="submit" className="btn-primary">
                            {t('offer.manage.search')}
                        </button>
                    </form>

                    {!readOnly && (
                        <div className="flex flex-wrap items-center gap-2 rounded-md bg-canvas p-2 text-sm">
                            <span className="px-1 font-semibold">{t('offer.bulk.selected', { count: selected.length })}</span>
                            <button type="button" className="btn-outline-pill px-3 py-1" disabled={selected.length === 0} onClick={() => bulk('deactivate')}>
                                {t('offer.bulk.deactivate')}
                            </button>
                            <button type="button" className="btn-outline-pill px-3 py-1" disabled={selected.length === 0} onClick={() => bulk('relist')}>
                                {t('offer.bulk.relist')}
                            </button>
                            <span className="flex items-center gap-1">
                                <select className="field w-auto py-1" value={days} onChange={(e) => setDays(Number(e.target.value))} aria-label={t('offer.form.listing_days')}>
                                    {listingDays.map((d) => (
                                        <option key={d} value={d}>
                                            {t('offer.days', { count: d })}
                                        </option>
                                    ))}
                                </select>
                                <button type="button" className="btn-outline-pill px-3 py-1" disabled={selected.length === 0} onClick={() => bulk('extend')}>
                                    {t('offer.bulk.extend')}
                                </button>
                            </span>
                            {confirmDelete ? (
                                <span className="flex items-center gap-1">
                                    <button type="button" className="rounded-full bg-danger px-3 py-1 font-semibold text-white" onClick={() => bulk('delete')}>
                                        {t('offer.bulk.confirm_delete', { count: selected.length })}
                                    </button>
                                    <button type="button" className="btn-ghost px-2 py-1" onClick={() => setConfirmDelete(false)}>
                                        {t('common.cancel')}
                                    </button>
                                </span>
                            ) : (
                                <button type="button" className="btn-outline-pill px-3 py-1 text-danger" disabled={selected.length === 0} onClick={() => setConfirmDelete(true)}>
                                    {t('offer.bulk.delete')}
                                </button>
                            )}
                        </div>
                    )}
                    {errors.offers && <p className="text-sm text-danger">{errors.offers}</p>}

                    {offers.items.length === 0 ? (
                        <div className="flex flex-col items-center gap-3 py-12 text-center">
                            <span className="flex h-14 w-14 items-center justify-center rounded-full bg-canvas text-2xl font-bold text-muted">!</span>
                            <p className="font-semibold">{t('common.no_results')}</p>
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[960px] text-sm">
                                <thead className="text-xs uppercase text-muted">
                                    <tr>
                                        <th className="w-8 py-2">
                                            <input type="checkbox" aria-label={t('offer.bulk.select_all')} checked={allChecked} onChange={(e) => setSelected(e.target.checked ? offers.items.map((o) => o.id) : [])} />
                                        </th>
                                        <th className="py-2 text-start">{t('offer.col.product')}</th>
                                        <th className="py-2 text-start">{t('offer.col.sort')}</th>
                                        <th className="py-2 text-start">{t('offer.col.min_qty')}</th>
                                        <th className="py-2 text-start">{t('offer.col.unit_price')}</th>
                                        <th className="py-2 text-start">{t('offer.col.stock')}</th>
                                        <th className="py-2 text-start">{t('offer.delivery_method')}</th>
                                        <th className="py-2 text-start">{t('offer.col.expires')}</th>
                                        <th className="py-2 text-start">{t('offer.col.status')}</th>
                                        <th className="py-2 text-start">{t('offer.col.action')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {offers.items.map((o) => (
                                        <tr key={o.id} className="border-t border-line align-top">
                                            <td className="py-3">
                                                <input
                                                    type="checkbox"
                                                    aria-label={o.title}
                                                    checked={selected.includes(o.id)}
                                                    onChange={(e) => setSelected(e.target.checked ? [...selected, o.id] : selected.filter((id) => id !== o.id))}
                                                />
                                            </td>
                                            <td className="max-w-[260px] py-3 pe-2">
                                                <a href={o.href} className="line-clamp-2 font-semibold hover:text-primary" target="_blank" rel="noreferrer">
                                                    {o.title}
                                                </a>
                                                <p className="text-xs text-muted">
                                                    {o.service} · #{o.publicId.slice(-8)} · {new Date(o.createdAt).toLocaleDateString(locale)}
                                                </p>
                                                {o.duplicateOf !== null && <p className="text-xs font-semibold text-warning">{t('offer.duplicate_flag')}</p>}
                                                {o.statusReason && <p className="text-xs text-danger">{o.statusReason}</p>}
                                            </td>
                                            <td className="py-3">
                                                <input
                                                    className="field w-16 py-1"
                                                    inputMode="numeric"
                                                    defaultValue={o.sort}
                                                    disabled={readOnly}
                                                    aria-label={t('offer.col.sort')}
                                                    onBlur={(e) => e.target.value !== String(o.sort) && inline(o.id, 'sort', e.target.value)}
                                                />
                                            </td>
                                            <td className="py-3">{o.minQty}</td>
                                            <td className="py-3">
                                                <input
                                                    className="field w-24 py-1"
                                                    inputMode="decimal"
                                                    defaultValue={toDecimal(o.priceCents)}
                                                    disabled={readOnly}
                                                    aria-label={t('offer.col.unit_price')}
                                                    onBlur={(e) => e.target.value !== toDecimal(o.priceCents) && inline(o.id, 'price', e.target.value)}
                                                />
                                            </td>
                                            <td className="py-3">
                                                {o.auto ? (
                                                    <span title={o.storageGroup ?? ''}>
                                                        {o.stock} <span className="text-xs text-muted">({t('offer.auto')})</span>
                                                    </span>
                                                ) : (
                                                    <input
                                                        className="field w-20 py-1"
                                                        inputMode="numeric"
                                                        defaultValue={o.stock}
                                                        disabled={readOnly}
                                                        aria-label={t('offer.col.stock')}
                                                        onBlur={(e) => e.target.value !== String(o.stock) && inline(o.id, 'stock', e.target.value)}
                                                    />
                                                )}
                                                <p className="text-xs text-muted">{o.auto ? t('offer.instant') : deliveryTime(o.deliveryMinutes, t)}</p>
                                            </td>
                                            <td className="py-3">{t(`offer.delivery.${o.deliveryMethod}`)}</td>
                                            <td className="py-3 text-muted">{o.expiresAt ? new Date(o.expiresAt).toLocaleDateString(locale) : '—'}</td>
                                            <td className="py-3">
                                                <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${STATUS_STYLE[o.status]}`}>{t(`offer.status.${o.status}`)}</span>
                                            </td>
                                            <td className="space-y-1 py-3">
                                                {!readOnly && o.status !== 'system_deactivated' && (
                                                    <Link href={`/seller/offers/${o.id}/edit`} className="block font-semibold text-primary hover:underline">
                                                        {t('offer.action.edit')}
                                                    </Link>
                                                )}
                                                {!readOnly && o.canDeactivate && (
                                                    <button type="button" className="block text-muted hover:text-ink" onClick={() => router.post('/seller/offers/bulk', { operation: 'deactivate', ids: [o.id] }, { preserveScroll: true })}>
                                                        {t('offer.bulk.deactivate')}
                                                    </button>
                                                )}
                                                {!readOnly && o.canRelist && (
                                                    <button type="button" className="block text-success hover:underline" onClick={() => router.post('/seller/offers/bulk', { operation: 'relist', ids: [o.id] }, { preserveScroll: true })}>
                                                        {t(o.status === 'pending_publish' ? 'offer.action.publish' : 'offer.bulk.relist')}
                                                    </button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    <Pagination page={offers.page} lastPage={offers.lastPage} onPage={(p) => visit({}, p)} />
                </div>
            </section>
        </DashboardLayout>
    );
}
