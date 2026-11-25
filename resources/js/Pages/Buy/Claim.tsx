import { Link } from '@inertiajs/react';
import { ORDER_STATUS_STYLE } from '@/Components/Orders/OrderParts';
import { DashboardLayout } from '@/Layouts/DashboardLayout';
import { useLocalePath, useShared, useT } from '@/lib/i18n';
import { formatUsd } from '@/lib/money';

interface ClaimOrder {
    publicId: string;
    number: string;
    title: string;
    seller: string;
    status: string;
    paidAt: string | null;
    total: number;
    openCase: boolean;
    claimable: boolean;
}

/** Get help with an order: pick the order, then the dispute form applies the full rules. */
export default function Claim({ orders }: { orders: ClaimOrder[] }) {
    const t = useT();
    const lp = useLocalePath();
    const { locale } = useShared();

    return (
        <DashboardLayout title={t('protection.claim_title')}>
            <p className="mb-4 text-sm text-muted">
                {t('protection.claim_intro')}{' '}
                <Link href={lp('/buyer-protection')} className="font-semibold text-primary hover:underline">
                    {t('protection.title')}
                </Link>
            </p>

            {orders.length === 0 ? (
                <p className="rounded-xl border border-dashed border-line p-8 text-center text-muted">{t('protection.claim_none')}</p>
            ) : (
                <ul className="space-y-2">
                    {orders.map((o) => (
                        <li key={o.publicId} className="flex flex-wrap items-center gap-3 rounded-xl border border-line p-3">
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <Link href={`/buy/orders/${o.publicId}`} className="font-semibold text-primary hover:underline">
                                        {o.number}
                                    </Link>
                                    <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${ORDER_STATUS_STYLE[o.status] ?? ''}`}>{t(`order.status.${o.status}`)}</span>
                                </div>
                                <p className="truncate text-sm">{o.title}</p>
                                <p className="text-xs text-muted">
                                    {o.seller} · {o.paidAt ? new Date(o.paidAt).toLocaleDateString(locale) : ''} · {formatUsd(o.total, locale)}
                                </p>
                            </div>
                            {o.openCase ? (
                                <Link href={`/orders/${o.publicId}/disputes/new`} className="btn-outline-pill px-4 py-2 text-sm">
                                    {t('protection.view_case')}
                                </Link>
                            ) : o.claimable ? (
                                <Link href={`/orders/${o.publicId}/disputes/new`} className="btn-primary px-4 text-sm">
                                    {t('protection.claim_cta')}
                                </Link>
                            ) : (
                                <span className="text-xs text-muted">{t('protection.not_claimable')}</span>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </DashboardLayout>
    );
}
