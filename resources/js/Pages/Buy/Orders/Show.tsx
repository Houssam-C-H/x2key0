import { Link, router } from '@inertiajs/react';
import { useEffect } from 'react';
import { DashboardLayout, Panel } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';
import { formatUsd } from '@/lib/money';
import { deliveryTime } from '@/lib/offer';
import { ORDER_STATUS_STYLE } from './Index';

interface Order {
    publicId: string;
    number: string;
    status: string;
    currency: string;
    createdAt: string;
    paymentDueAt: string | null;
    paidAt: string | null;
    deliveryDueAt: string | null;
    cancelReason: string | null;
    lines: { subtotal: number; discountSeller: number; discountPlatform: number; buyerFee: number; addonFee: number; tax: number; total: number; credits: number; psp: number };
    item: {
        title: string;
        gameCategory: string;
        deliveryMethod: string;
        deliveryMinutes: number | null;
        quantity: number;
        unitPrice: number;
        offerHref: string | null;
        snapshot: { description: string | null; insuranceDays: number; version: number };
    };
    seller: { name: string; href: string };
    payment: { status: string; method: string; redirectUrl: string | null } | null;
    timeline: { to: string; from: string | null; actor: string; reason: string | null; at: string }[];
}

/**
 * Order detail (spec 1.6): status, price lines, item snapshot, payment, reverse-chronological timeline.
 * While payment is pending the page refreshes itself (the return URL never changes state — spec 9.3 #2).
 */
export default function OrderShow({ order }: { order: Order }) {
    const t = useT();
    const { locale } = useShared();
    const money = (cents: number) => formatUsd(cents, locale) ?? '';
    const waiting = order.status === 'pending_payment';

    useEffect(() => {
        if (!waiting) return;
        const timer = window.setInterval(() => router.reload({ only: ['order'] }), 4000);

        return () => window.clearInterval(timer);
    }, [waiting]);

    const lines: [string, number][] = [
        [t('checkout.line.amount'), order.lines.subtotal],
        ...(order.lines.discountSeller > 0 ? [[t('checkout.line.discount_seller'), -order.lines.discountSeller] as [string, number]] : []),
        ...(order.lines.discountPlatform > 0 ? [[t('checkout.line.discount_platform'), -order.lines.discountPlatform] as [string, number]] : []),
        [t('checkout.line.service_charge'), order.lines.buyerFee],
        ...(order.lines.addonFee > 0 ? [[t('checkout.line.addon_fee'), order.lines.addonFee] as [string, number]] : []),
        ...(order.lines.tax > 0 ? [[t('checkout.line.tax'), order.lines.tax] as [string, number]] : []),
    ];

    return (
        <DashboardLayout title={t('order.detail_title', { number: order.number })} bare>
            <Panel title={order.number}>
                <div className="flex flex-wrap items-center gap-3">
                    <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${ORDER_STATUS_STYLE[order.status] ?? ''}`}>{t(`order.status.${order.status}`)}</span>
                    <span className="text-sm text-muted">{new Date(order.createdAt).toLocaleString(locale)}</span>
                </div>
                <p className="mt-3 text-sm">{t(`order.explain.${order.status}`)}</p>
                {waiting && order.payment?.redirectUrl && (
                    <div className="mt-4 flex flex-wrap items-center gap-3">
                        <a href={order.payment.redirectUrl} className="btn-primary">
                            {t('order.pay_now')}
                        </a>
                        {order.paymentDueAt && <span className="text-sm text-muted">{t('order.pay_before', { time: new Date(order.paymentDueAt).toLocaleTimeString(locale) })}</span>}
                    </div>
                )}
                {order.status === 'paid' && order.deliveryDueAt && <p className="mt-2 text-sm text-muted">{t('order.delivery_due', { time: new Date(order.deliveryDueAt).toLocaleString(locale) })}</p>}
                {order.cancelReason && <p className="mt-2 text-sm text-muted">{t(`order.cancel_reason.${order.cancelReason.split(':')[0]}`)}</p>}
            </Panel>

            <div className="grid gap-6 lg:grid-cols-[1fr_340px]">
                <Panel title={t('order.item')}>
                    <div className="space-y-2 text-sm">
                        <p className="font-semibold">
                            {order.item.offerHref ? (
                                <Link href={order.item.offerHref} className="hover:text-primary">
                                    {order.item.title}
                                </Link>
                            ) : (
                                order.item.title
                            )}
                        </p>
                        <p className="text-muted">
                            {order.item.gameCategory} ·{' '}
                            <a href={order.seller.href} className="hover:text-primary">
                                {order.seller.name}
                            </a>
                        </p>
                        <dl className="grid grid-cols-2 gap-2 pt-2 sm:grid-cols-4">
                            <div>
                                <dt className="text-xs text-muted">{t('offer.quantity')}</dt>
                                <dd className="font-medium">{order.item.quantity}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-muted">{t('offer.col.unit_price')}</dt>
                                <dd className="font-medium">{money(order.item.unitPrice)}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-muted">{t('offer.delivery_method')}</dt>
                                <dd className="font-medium">{t(`offer.delivery.${order.item.deliveryMethod}`)}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-muted">{t('offer.delivery_time')}</dt>
                                <dd className="font-medium">{order.item.deliveryMinutes ? deliveryTime(order.item.deliveryMinutes, t) : '—'}</dd>
                            </div>
                        </dl>
                        <details className="pt-2">
                            <summary className="cursor-pointer text-primary">{t('order.snapshot')}</summary>
                            <p className="mt-2 whitespace-pre-line text-muted">{order.item.snapshot.description ?? '—'}</p>
                            <p className="mt-1 text-xs text-muted">{t('order.snapshot_note', { version: order.item.snapshot.version })}</p>
                        </details>
                    </div>
                </Panel>

                <Panel title={t('checkout.summary')}>
                    <dl className="space-y-2 text-sm">
                        {lines.map(([label, amount]) => (
                            <div key={label} className="flex justify-between">
                                <dt>{label}</dt>
                                <dd>{amount < 0 ? `− ${money(-amount)}` : money(amount)}</dd>
                            </div>
                        ))}
                        <div className="flex justify-between border-t border-line pt-2 text-base font-bold">
                            <dt>{t('checkout.line.total')}</dt>
                            <dd>{money(order.lines.total)}</dd>
                        </div>
                        {order.lines.credits > 0 && (
                            <div className="flex justify-between text-muted">
                                <dt>{t('checkout.line.credits')}</dt>
                                <dd>{money(order.lines.credits)}</dd>
                            </div>
                        )}
                        {order.payment && (
                            <div className="flex justify-between text-muted">
                                <dt>{t('order.payment_status', { method: order.payment.method })}</dt>
                                <dd>{t(`order.payment.${order.payment.status}`)}</dd>
                            </div>
                        )}
                    </dl>
                </Panel>
            </div>

            <Panel title={t('order.timeline')}>
                <ol className="space-y-3 border-s border-line ps-4 text-sm">
                    {order.timeline.map((h, i) => (
                        <li key={i} className="relative">
                            <span className="absolute -start-[1.3rem] top-1.5 h-2.5 w-2.5 rounded-full bg-primary" />
                            <p className="font-medium">{t(`order.timeline.${h.to}`)}</p>
                            <p className="text-xs text-muted">{new Date(h.at).toLocaleString(locale)}</p>
                        </li>
                    ))}
                </ol>
            </Panel>
        </DashboardLayout>
    );
}
