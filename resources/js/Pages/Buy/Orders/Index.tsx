import { Link, router } from '@inertiajs/react';
import { Pagination } from '@/Components/Catalog/Pagination';
import { DashboardLayout } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';
import { formatUsd } from '@/lib/money';
import type { Paged } from '@/types/offer';

export const ORDER_STATUS_STYLE: Record<string, string> = {
    pending_payment: 'bg-warning/15 text-warning',
    payment_review: 'bg-warning/15 text-warning',
    paid: 'bg-primary-soft text-primary',
    delivering: 'bg-primary-soft text-primary',
    delivered: 'bg-success/10 text-success',
    completed: 'bg-success/10 text-success',
    cancelled: 'bg-canvas text-muted',
    refunded: 'bg-canvas text-muted',
};

interface Row {
    publicId: string;
    number: string;
    title: string;
    seller: string;
    quantity: number;
    total: number;
    currency: string;
    status: string;
    createdAt: string;
}

/** Buy › Purchase Orders (spec 6.1 buyer tabs). */
export default function PurchaseOrders({ orders, counts, tab, tabs }: { orders: Paged<Row>; counts: Record<string, number>; tab: string; tabs: string[] }) {
    const t = useT();
    const { locale } = useShared();
    const visit = (next: string, page = 1) => router.get('/buy/orders', { tab: next === 'all' ? undefined : next, page: page > 1 ? page : undefined }, { preserveScroll: true, preserveState: true });

    return (
        <DashboardLayout title={t('menu.buy.orders')}>
            <nav className="-mx-6 mb-4 flex gap-1 overflow-x-auto border-b border-line px-6" aria-label={t('menu.buy.orders')}>
                {tabs.map((name) => (
                    <button
                        key={name}
                        type="button"
                        aria-current={tab === name ? 'page' : undefined}
                        onClick={() => visit(name)}
                        className={`whitespace-nowrap border-b-2 px-3 py-3 text-sm font-semibold ${tab === name ? 'border-primary text-primary' : 'border-transparent text-muted hover:text-ink'}`}
                    >
                        {t(`order.tab.${name}`)} <span className="text-xs">({counts[name] ?? 0})</span>
                    </button>
                ))}
            </nav>

            {orders.items.length === 0 ? (
                <div className="flex flex-col items-center gap-3 py-12 text-center">
                    <span className="flex h-14 w-14 items-center justify-center rounded-full bg-canvas text-2xl font-bold text-muted">!</span>
                    <p className="font-semibold">{t('common.no_results')}</p>
                </div>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[640px] text-sm">
                        <thead className="text-xs uppercase text-muted">
                            <tr>
                                <th className="py-2 text-start">{t('order.col.order')}</th>
                                <th className="py-2 text-start">{t('offer.col.product')}</th>
                                <th className="py-2 text-start">{t('offer.col.seller')}</th>
                                <th className="py-2 text-end">{t('checkout.line.total')}</th>
                                <th className="py-2 text-start">{t('offer.col.status')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {orders.items.map((o) => (
                                <tr key={o.publicId} className="border-t border-line">
                                    <td className="py-2.5">
                                        <Link href={`/buy/orders/${o.publicId}`} className="font-semibold text-primary hover:underline">
                                            {o.number}
                                        </Link>
                                        <p className="text-xs text-muted">{new Date(o.createdAt).toLocaleString(locale)}</p>
                                    </td>
                                    <td className="max-w-[260px] truncate py-2.5">
                                        {o.title} × {o.quantity}
                                    </td>
                                    <td className="py-2.5">{o.seller}</td>
                                    <td className="py-2.5 text-end font-semibold">{formatUsd(o.total, locale)}</td>
                                    <td className="py-2.5">
                                        <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${ORDER_STATUS_STYLE[o.status] ?? ''}`}>{t(`order.status.${o.status}`)}</span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            <div className="mt-4">
                <Pagination page={orders.page} lastPage={orders.lastPage} onPage={(p) => visit(tab, p)} />
            </div>
        </DashboardLayout>
    );
}
