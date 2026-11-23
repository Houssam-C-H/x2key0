import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Pagination } from '@/Components/Catalog/Pagination';
import { StatusBadge } from '@/Components/Orders/OrderParts';
import { DashboardLayout } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';
import { formatUsd } from '@/lib/money';
import type { Paged } from '@/types/offer';

interface Row {
    publicId: string;
    number: string;
    title: string;
    type: string;
    quantity: number;
    unitPrice: number;
    total: number;
    status: string;
    substatus: string | null;
    remark: string | null;
    acquisitionCost: number | null;
    paidAt: string | null;
}

interface Filters {
    game: number | null;
    type: string | null;
    number: string;
    title: string;
    remark: string;
    from: string | null;
    to: string | null;
}

interface Props {
    orders: Paged<Row>;
    counts: Record<string, number>;
    tab: string;
    tabs: string[];
    filters: Filters;
    games: { id: number; name: string }[];
    types: string[];
}

/** Seller Center › Sold Orders (spec 1.8): tabs with counts, filters, sub-status chips, remarks, acquisition cost. */
export default function SoldOrders({ orders, counts, tab, tabs, filters, games, types }: Props) {
    const t = useT();
    const { locale } = useShared();
    const [f, setF] = useState<Filters>(filters);
    const money = (c: number | null) => (c === null ? '—' : (formatUsd(c, locale) ?? ''));

    const visit = (nextTab: string, next: Filters, page = 1) =>
        router.get(
            '/seller/orders',
            Object.fromEntries(Object.entries({ tab: nextTab === 'all' ? undefined : nextTab, ...next, page: page > 1 ? page : undefined }).filter(([, v]) => v !== null && v !== '' && v !== undefined)),
            { preserveScroll: true, preserveState: true },
        );

    return (
        <DashboardLayout title={t('menu.seller.orders')} bare>
            <section className="card p-0">
                <nav className="flex gap-1 overflow-x-auto border-b border-line px-3" aria-label={t('menu.seller.orders')}>
                    {tabs.map((name) => (
                        <button
                            key={name}
                            type="button"
                            aria-current={tab === name ? 'page' : undefined}
                            onClick={() => visit(name, filters)}
                            className={`whitespace-nowrap border-b-2 px-3 py-3 text-sm font-semibold ${tab === name ? 'border-primary text-primary' : 'border-transparent text-muted hover:text-ink'}`}
                        >
                            {t(`order.seller_tab.${name}`)} <span className="text-xs">({counts[name] ?? 0})</span>
                        </button>
                    ))}
                </nav>

                <form
                    className="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        visit(tab, f);
                    }}
                >
                    <label className="flex flex-col gap-1 text-sm">
                        <span className="font-medium">{t('order.filter.game')}</span>
                        <select className="field" value={f.game ?? ''} onChange={(e) => setF({ ...f, game: e.target.value === '' ? null : Number(e.target.value) })}>
                            <option value="">{t('offer.manage.all_services')}</option>
                            {games.map((g) => (
                                <option key={g.id} value={g.id}>
                                    {g.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="flex flex-col gap-1 text-sm">
                        <span className="font-medium">{t('order.filter.type')}</span>
                        <select className="field" value={f.type ?? ''} onChange={(e) => setF({ ...f, type: e.target.value === '' ? null : e.target.value })}>
                            <option value="">{t('offer.manage.all_services')}</option>
                            {types.map((ty) => (
                                <option key={ty} value={ty}>
                                    {t(`category.${ty}`)}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="flex flex-col gap-1 text-sm">
                        <span className="font-medium">{t('order.col.order')}</span>
                        <input className="field" value={f.number} onChange={(e) => setF({ ...f, number: e.target.value })} />
                    </label>
                    <label className="flex flex-col gap-1 text-sm">
                        <span className="font-medium">{t('order.filter.title')}</span>
                        <input className="field" value={f.title} onChange={(e) => setF({ ...f, title: e.target.value })} />
                    </label>
                    <label className="flex flex-col gap-1 text-sm">
                        <span className="font-medium">{t('order.remark')}</span>
                        <input className="field" value={f.remark} onChange={(e) => setF({ ...f, remark: e.target.value })} />
                    </label>
                    <label className="flex flex-col gap-1 text-sm">
                        <span className="font-medium">{t('order.filter.from')}</span>
                        <input className="field" type="date" value={f.from ?? ''} onChange={(e) => setF({ ...f, from: e.target.value || null })} />
                    </label>
                    <label className="flex flex-col gap-1 text-sm">
                        <span className="font-medium">{t('order.filter.to')}</span>
                        <input className="field" type="date" value={f.to ?? ''} onChange={(e) => setF({ ...f, to: e.target.value || null })} />
                    </label>
                    <div className="flex items-end">
                        <button type="submit" className="btn-primary w-full">
                            {t('offer.manage.search')}
                        </button>
                    </div>
                </form>

                <div className="px-4 pb-4">
                    {orders.items.length === 0 ? (
                        <div className="flex flex-col items-center gap-3 py-12 text-center">
                            <span className="flex h-14 w-14 items-center justify-center rounded-full bg-canvas text-2xl font-bold text-muted">!</span>
                            <p className="font-semibold">{t('common.no_results')}</p>
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[860px] text-sm">
                                <thead className="text-xs uppercase text-muted">
                                    <tr>
                                        <th className="py-2 text-start">{t('offer.col.product')}</th>
                                        <th className="py-2 text-start">{t('offer.col.unit_price')}</th>
                                        <th className="py-2 text-start">{t('order.filter.type')}</th>
                                        <th className="py-2 text-start">{t('offer.col.status')}</th>
                                        <th className="py-2 text-start">{t('order.remark')}</th>
                                        <th className="py-2 text-end">{t('order.col.total')}</th>
                                        <th className="py-2 text-end">{t('order.acquisition_cost')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {orders.items.map((o) => (
                                        <tr key={o.publicId} className="border-t border-line align-top">
                                            <td className="max-w-[280px] py-2.5">
                                                <Link href={`/seller/orders/${o.publicId}`} className="font-semibold text-primary hover:underline">
                                                    {o.number}
                                                </Link>
                                                <p className="truncate">
                                                    {o.title} × {o.quantity}
                                                </p>
                                                <p className="text-xs text-muted">{o.paidAt ? new Date(o.paidAt).toLocaleString(locale) : ''}</p>
                                            </td>
                                            <td className="py-2.5">{money(o.unitPrice)}</td>
                                            <td className="py-2.5">{t(`category.${o.type}`)}</td>
                                            <td className="py-2.5">
                                                <StatusBadge status={o.status} substatus={o.substatus} />
                                            </td>
                                            <td className="max-w-[160px] truncate py-2.5 text-muted">{o.remark ?? '—'}</td>
                                            <td className="py-2.5 text-end font-semibold">{money(o.total)}</td>
                                            <td className="py-2.5 text-end">{money(o.acquisitionCost)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    <div className="mt-4">
                        <Pagination page={orders.page} lastPage={orders.lastPage} onPage={(p) => visit(tab, filters, p)} />
                    </div>
                </div>
            </section>
        </DashboardLayout>
    );
}
