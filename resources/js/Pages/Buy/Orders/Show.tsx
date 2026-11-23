import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { DeliveriesPanel, ItemPanel, MoneyPanel, type OrderDetail, StatusBadge, Timeline } from '@/Components/Orders/OrderParts';
import { DashboardLayout, Panel } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';
import { xsrfToken } from '@/lib/offer';

interface Secret {
    deliveryId: number;
    kind: string;
    value: string | null;
    account: Record<string, string | null> | null;
}

/**
 * Order detail (spec 1.6 / 8.3): status, deliveries with an audited reveal, cancel after a missed deadline,
 * cancel remainder, refunds, timeline. While payment is pending the page refreshes itself.
 */
export default function OrderShow({ order, can }: { order: OrderDetail; can: { cancel: boolean; cancelRemainder: boolean; reveal: boolean; confirm: boolean } }) {
    const t = useT();
    const { locale, errors } = useShared();
    const [secrets, setSecrets] = useState<Secret[] | null>(null);
    const [revealError, setRevealError] = useState<string | null>(null);
    const [toCredits, setToCredits] = useState(false);
    const waiting = order.status === 'pending_payment';

    useEffect(() => {
        if (!waiting) return;
        const timer = window.setInterval(() => router.reload({ only: ['order', 'can'] }), 4000);

        return () => window.clearInterval(timer);
    }, [waiting]);

    const reveal = async () => {
        setRevealError(null);
        const response = await fetch(`/buy/orders/${order.publicId}/reveal`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrfToken() },
        });
        if (response.status === 423) {
            window.location.href = '/user/confirm-password';

            return;
        }
        if (!response.ok) {
            setRevealError(t('order.reveal.failed'));

            return;
        }
        setSecrets(((await response.json()) as { secrets: Secret[] }).secrets);
    };

    return (
        <DashboardLayout title={t('order.detail_title', { number: order.number })} bare>
            <Panel title={order.number}>
                <div className="flex flex-wrap items-center gap-3">
                    <StatusBadge status={order.status} />
                    <span className="text-sm text-muted">{new Date(order.createdAt).toLocaleString(locale)}</span>
                </div>
                <p className="mt-3 text-sm">{t(`order.explain.${order.status}`)}</p>
                {errors.order && <p className="mt-2 text-sm text-danger">{errors.order}</p>}
                {waiting && order.payment?.redirectUrl && (
                    <div className="mt-4 flex flex-wrap items-center gap-3">
                        <a href={order.payment.redirectUrl} className="btn-primary">
                            {t('order.pay_now')}
                        </a>
                        {order.paymentDueAt && <span className="text-sm text-muted">{t('order.pay_before', { time: new Date(order.paymentDueAt).toLocaleTimeString(locale) })}</span>}
                    </div>
                )}
                {['paid', 'delivering'].includes(order.status) && order.deliveryDueAt && (
                    <p className="mt-2 text-sm text-muted">{t('order.delivery_due', { time: new Date(order.deliveryDueAt).toLocaleString(locale) })}</p>
                )}
                {order.confirmDueAt && order.status === 'delivered' && <p className="mt-2 text-sm text-muted">{t('order.confirm_due', { time: new Date(order.confirmDueAt).toLocaleString(locale) })}</p>}
                {order.cancelReason && <p className="mt-2 text-sm text-muted">{t(`order.cancel_reason.${order.cancelReason.split(':')[0]}`)}</p>}

                {can.confirm && (
                    <div className="mt-4 flex flex-wrap items-center gap-3 rounded-md bg-primary-soft p-3 text-sm">
                        <span className="flex-1">{t('order.confirm_help')}</span>
                        <button type="button" className="btn-primary px-4 py-1.5" onClick={() => router.post(`/buy/orders/${order.publicId}/confirm`, {}, { preserveScroll: true })}>
                            {t('order.confirm')}
                        </button>
                    </div>
                )}

                {(can.cancel || can.cancelRemainder) && (
                    <div className="mt-4 flex flex-wrap items-center gap-3 rounded-md bg-canvas p-3 text-sm">
                        <span className="flex-1">{t(can.cancel ? 'order.cancel_help' : 'order.cancel_remainder_help')}</span>
                        <label className="flex items-center gap-2">
                            <input type="checkbox" checked={toCredits} onChange={(e) => setToCredits(e.target.checked)} />
                            {t('order.refund_to_credits')}
                        </label>
                        <button
                            type="button"
                            className="btn-outline-pill px-3 py-1 text-danger"
                            onClick={() => router.post(`/buy/orders/${order.publicId}/${can.cancel ? 'cancel' : 'cancel-remainder'}`, { to_credits: toCredits }, { preserveScroll: true })}
                        >
                            {t(can.cancel ? 'order.cancel' : 'order.cancel_remainder')}
                        </button>
                    </div>
                )}
            </Panel>

            <div className="grid gap-6 lg:grid-cols-[1fr_340px]">
                <div className="space-y-6">
                    <ItemPanel order={order} />
                    <DeliveriesPanel
                        order={order}
                        actions={
                            can.reveal ? (
                                <div className="mt-4 space-y-3">
                                    {secrets === null ? (
                                        <button type="button" className="btn-primary" onClick={() => void reveal()}>
                                            {t('order.reveal.button')}
                                        </button>
                                    ) : (
                                        <div className="space-y-2">
                                            {secrets.map((s, i) =>
                                                s.value !== null ? (
                                                    <code key={i} className="block break-all rounded bg-canvas px-3 py-2 font-mono text-sm">
                                                        {s.value}
                                                    </code>
                                                ) : (
                                                    <dl key={i} className="grid grid-cols-[auto_1fr] gap-x-3 rounded bg-canvas px-3 py-2 font-mono text-sm">
                                                        {Object.entries(s.account ?? {})
                                                            .filter(([, v]) => v)
                                                            .map(([k, v]) => (
                                                                <div key={k} className="contents">
                                                                    <dt className="text-muted">{t(`storage.account.${k}`)}</dt>
                                                                    <dd className="break-all">{v}</dd>
                                                                </div>
                                                            ))}
                                                    </dl>
                                                ),
                                            )}
                                            <button type="button" className="text-xs text-muted hover:text-ink" onClick={() => setSecrets(null)}>
                                                {t('storage.items.hide')}
                                            </button>
                                        </div>
                                    )}
                                    <p className="text-xs text-muted">{t('order.reveal.note')}</p>
                                    {revealError && <p className="text-sm text-danger">{revealError}</p>}
                                </div>
                            ) : undefined
                        }
                    />
                </div>
                <MoneyPanel order={order} />
            </div>

            <Timeline order={order} />
        </DashboardLayout>
    );
}
