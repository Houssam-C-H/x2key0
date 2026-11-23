import type { ReactNode } from 'react';
import { Panel } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';
import { formatUsd } from '@/lib/money';
import { deliveryTime } from '@/lib/offer';

export interface OrderDetail {
    publicId: string;
    number: string;
    status: string;
    substatus: string | null;
    currency: string;
    createdAt: string;
    paymentDueAt: string | null;
    paidAt: string | null;
    deliveryDueAt: string | null;
    deliveredAt: string | null;
    confirmDueAt: string | null;
    acceptedAt: string | null;
    completedAt: string | null;
    cancelledAt: string | null;
    cancelReason: string | null;
    lines: {
        subtotal: number;
        discountSeller: number;
        discountPlatform: number;
        buyerFee: number;
        addonFee: number;
        tax: number;
        total: number;
        credits: number;
        psp: number;
        refunded: number;
        sellerNet: number;
        commission: number;
    };
    item: {
        title: string;
        gameCategory: string;
        deliveryMethod: string;
        auto: boolean;
        deliveryMinutes: number | null;
        quantity: number;
        delivered: number;
        refunded: number;
        remaining: number;
        unitPrice: number;
        offerHref: string | null;
        snapshot: {
            description: string | null;
            insuranceDays: number;
            version: number;
        };
    };
    seller: { name: string; href: string };
    payment: {
        status: string;
        method: string;
        redirectUrl: string | null;
    } | null;
    deliveries: {
        id: number;
        quantity: number;
        by: string;
        note: string | null;
        secrets: number;
        evidence: { id: number; url: string; mime: string }[];
        at: string;
    }[];
    milestones: {
        title: string;
        percent: number;
        note: string | null;
        at: string;
    }[];
    refunds: {
        amount: number;
        destination: string;
        status: string;
        at: string;
    }[];
    timeline: {
        to: string;
        from: string | null;
        toSub: string | null;
        fromSub: string | null;
        units: number | null;
        actor: string;
        reason: string | null;
        at: string;
    }[];
}

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

export function StatusBadge({ status, substatus }: { status: string; substatus?: string | null }) {
    const t = useT();

    return (
        <span className="inline-flex flex-wrap items-center gap-1.5">
            <span className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ${ORDER_STATUS_STYLE[status] ?? ''}`}>{t(`order.status.${status}`)}</span>
            {substatus && (
                <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${substatus === 'issue' ? 'bg-danger/10 text-danger' : 'bg-canvas text-ink'}`}>
                    {t(`order.substatus.${substatus}`)}
                </span>
            )}
        </span>
    );
}

/** Item, quantities and snapshot. */
export function ItemPanel({ order, children }: { order: OrderDetail; children?: ReactNode }) {
    const t = useT();
    const { locale } = useShared();
    const money = (c: number) => formatUsd(c, locale) ?? '';

    return (
        <Panel title={t('order.item')}>
            <div className="space-y-2 text-sm">
                <p className="font-semibold">
                    {order.item.offerHref ? (
                        <a href={order.item.offerHref} className="hover:text-primary">
                            {order.item.title}
                        </a>
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
                    <Fact label={t('offer.quantity')} value={String(order.item.quantity)} />
                    <Fact
                        label={t('order.delivered_units')}
                        value={`${order.item.delivered} / ${order.item.quantity}${order.item.refunded > 0 ? ` (${t('order.refunded_units', { count: order.item.refunded })})` : ''}`}
                    />
                    <Fact label={t('offer.col.unit_price')} value={money(order.item.unitPrice)} />
                    <Fact
                        label={t('offer.delivery_method')}
                        value={`${t(`offer.delivery.${order.item.deliveryMethod}`)}${order.item.auto ? ` · ${t('offer.instant')}` : order.item.deliveryMinutes ? ` · ${deliveryTime(order.item.deliveryMinutes, t)}` : ''}`}
                    />
                </dl>
                <details className="pt-2">
                    <summary className="cursor-pointer text-primary">{t('order.snapshot')}</summary>
                    <p className="mt-2 whitespace-pre-line text-muted">{order.item.snapshot.description ?? '—'}</p>
                    <p className="mt-1 text-xs text-muted">
                        {t('order.snapshot_note', {
                            version: order.item.snapshot.version,
                        })}
                    </p>
                </details>
                {children}
            </div>
        </Panel>
    );
}

/** Deliveries (notes, evidence, number of secrets) and service milestones. */
export function DeliveriesPanel({ order, actions }: { order: OrderDetail; actions?: ReactNode }) {
    const t = useT();
    const { locale } = useShared();

    if (order.deliveries.length === 0 && order.milestones.length === 0 && !actions) return null;

    return (
        <Panel title={t('order.deliveries')}>
            {order.milestones.length > 0 && (
                <div className="mb-4">
                    <div className="h-2 overflow-hidden rounded-full bg-canvas">
                        <div
                            className="h-full bg-primary"
                            style={{
                                width: `${order.milestones[order.milestones.length - 1]?.percent ?? 0}%`,
                            }}
                        />
                    </div>
                    <ul className="mt-2 space-y-1 text-sm">
                        {order.milestones.map((m, i) => (
                            <li key={i}>
                                <span className="font-semibold">{m.percent}%</span> · {m.title} {m.note && <span className="text-muted">— {m.note}</span>}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            {order.deliveries.length > 0 && (
                <ul className="space-y-2 text-sm">
                    {order.deliveries.map((d) => (
                        <li key={d.id} className="rounded-md border border-line p-3">
                            <p className="font-semibold">
                                {t('order.delivery_line', {
                                    count: d.quantity,
                                })}{' '}
                                · <span className="text-muted">{t(`order.delivered_by.${d.by}`)}</span>
                                <span className="float-end text-xs text-muted">{new Date(d.at).toLocaleString(locale)}</span>
                            </p>
                            {d.note && <p className="mt-1 whitespace-pre-line">{d.note}</p>}
                            {d.secrets > 0 && (
                                <p className="mt-1 text-xs text-muted">
                                    {t('order.secrets_count', {
                                        count: d.secrets,
                                    })}
                                </p>
                            )}
                            {d.evidence.map((e) => (
                                <a key={e.id} href={e.url} target="_blank" rel="noreferrer" className="mt-1 inline-block text-xs font-semibold text-primary hover:underline">
                                    {t('order.evidence')} #{e.id}
                                </a>
                            ))}
                        </li>
                    ))}
                </ul>
            )}
            {actions}
        </Panel>
    );
}

/** Price lines, refunds and payment. `seller` shows commission and net instead of buyer fees. */
export function MoneyPanel({ order, seller = false }: { order: OrderDetail; seller?: boolean }) {
    const t = useT();
    const { locale } = useShared();
    const money = (c: number) => formatUsd(c, locale) ?? '';
    const rows: [string, number, boolean?][] = seller
        ? [
              [t('checkout.line.amount'), order.lines.subtotal],
              [t('order.commission'), -order.lines.commission],
              [t('order.seller_net'), order.lines.sellerNet, true],
          ]
        : [
              [t('checkout.line.amount'), order.lines.subtotal],
              [t('checkout.line.service_charge'), order.lines.buyerFee],
              ...(order.lines.addonFee > 0 ? [[t('checkout.line.addon_fee'), order.lines.addonFee] as [string, number]] : []),
              [t('checkout.line.total'), order.lines.total, true],
              ...(order.lines.credits > 0 ? [[t('checkout.line.credits'), order.lines.credits] as [string, number]] : []),
          ];

    return (
        <Panel title={t('checkout.summary')}>
            <dl className="space-y-2 text-sm">
                {rows.map(([label, amount, strong]) => (
                    <div key={label} className={`flex justify-between ${strong ? 'border-t border-line pt-2 text-base font-bold' : ''}`}>
                        <dt>{label}</dt>
                        <dd>{amount < 0 ? `− ${money(-amount)}` : money(amount)}</dd>
                    </div>
                ))}
                {order.refunds.map((r, i) => (
                    <div key={i} className="flex justify-between text-success">
                        <dt>{t(`order.refund_to.${r.destination}`)}</dt>
                        <dd>{money(r.amount)}</dd>
                    </div>
                ))}
                {!seller && order.payment && (
                    <div className="flex justify-between text-muted">
                        <dt>
                            {t('order.payment_status', {
                                method: order.payment.method,
                            })}
                        </dt>
                        <dd>{t(`order.payment.${order.payment.status}`)}</dd>
                    </div>
                )}
            </dl>
        </Panel>
    );
}

/** Timeline reasons with their own line (disputes and chargebacks, M11). */
const REASON_LINES = ['dispute_opened', 'dispute_closed', 'dispute_refund', 'dispute_redelivery', 'chargeback_opened', 'chargeback_won', 'chargeback_lost'];

export function Timeline({ order }: { order: OrderDetail }) {
    const t = useT();
    const { locale } = useShared();

    return (
        <Panel title={t('order.timeline')}>
            <ol className="space-y-3 border-s border-line ps-4 text-sm">
                {order.timeline.map((h, i) => (
                    <li key={i} className="relative">
                        <span className="absolute -start-[1.3rem] top-1.5 h-2.5 w-2.5 rounded-full bg-primary" />
                        <p className="font-medium">
                            {h.reason !== null && REASON_LINES.includes(h.reason)
                                ? t(`order.timeline.reason.${h.reason}`)
                                : h.from === h.to && h.toSub !== h.fromSub && h.toSub
                                  ? t('order.timeline.substatus', {
                                        status: t(`order.substatus.${h.toSub}`),
                                    })
                                  : h.units !== null
                                    ? t('order.timeline.units', {
                                          status: t(`order.status.${h.to}`),
                                          count: h.units,
                                      })
                                    : t(`order.timeline.${h.to}`)}
                        </p>
                        <p className="text-xs text-muted">{new Date(h.at).toLocaleString(locale)}</p>
                    </li>
                ))}
            </ol>
        </Panel>
    );
}

function Fact({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="text-xs text-muted">{label}</dt>
            <dd className="font-medium">{value}</dd>
        </div>
    );
}

type StepState = 'done' | 'current' | 'todo' | 'failed';

/**
 * Visual order progress (top of both order pages): Placed → Paid → In progress → Delivered → Completed, with the
 * time of each reached step and the next deadline under the current one. Cancelled / refunded orders end in red;
 * an open dispute (ISSUE) marks the current step.
 */
export function OrderProgress({ order }: { order: OrderDetail }) {
    const t = useT();
    const { locale } = useShared();
    const at = (iso: string | null) => (iso ? new Date(iso).toLocaleString(locale, { dateStyle: 'medium', timeStyle: 'short' }) : null);
    const ended = order.status === 'cancelled' || order.status === 'refunded';
    const rank: Record<string, number> = { pending_payment: 0, payment_review: 1, paid: 1, delivering: 2, delivered: 3, completed: 4 };
    let reached = rank[order.status] ?? 0;
    if (ended) {
        reached = order.deliveredAt ? 3 : order.acceptedAt ? 2 : order.paidAt ? 1 : 0;
    }

    const steps: { key: string; time: string | null; hint: string | null }[] = [
        {
            key: 'placed',
            time: at(order.createdAt),
            hint: order.status === 'pending_payment' && order.paymentDueAt ? t('order.progress.pay_before', { time: at(order.paymentDueAt) ?? '' }) : null,
        },
        {
            key: order.status === 'payment_review' ? 'review' : 'paid',
            time: at(order.paidAt),
            hint: order.status === 'paid' && order.deliveryDueAt ? t('order.progress.deliver_by', { time: at(order.deliveryDueAt) ?? '' }) : null,
        },
        {
            key: 'in_progress',
            time: at(order.acceptedAt),
            hint: order.status === 'delivering' && order.deliveryDueAt ? t('order.progress.deliver_by', { time: at(order.deliveryDueAt) ?? '' }) : null,
        },
        {
            key: 'delivered',
            time: at(order.deliveredAt),
            hint: order.status === 'delivered' && order.confirmDueAt ? t('order.progress.confirm_by', { time: at(order.confirmDueAt) ?? '' }) : null,
        },
        { key: 'completed', time: at(order.completedAt), hint: null },
    ];

    const state = (i: number): StepState => {
        if (ended) return i <= reached ? 'done' : 'todo';
        if (order.status === 'completed' || i < reached) return 'done';
        return i === reached ? 'current' : 'todo';
    };
    const issue = order.substatus === 'issue';
    const style: Record<StepState, string> = {
        done: 'bg-primary text-white border-primary',
        current: issue ? 'bg-danger text-white border-danger' : 'bg-white text-primary border-primary ring-4 ring-primary-soft',
        todo: 'bg-white text-muted border-line',
        failed: 'bg-danger text-white border-danger',
    };

    return (
        <nav aria-label={t('order.progress.title')} className="card overflow-x-auto p-5">
            <ol className="flex min-w-[560px] items-start">
                {steps.map((step, i) => {
                    const s = state(i);
                    return (
                        <li key={step.key} className="relative flex flex-1 flex-col items-center text-center">
                            {i > 0 && (
                                <span
                                    aria-hidden="true"
                                    className={`absolute top-4 h-0.5 w-full -translate-x-1/2 rtl:translate-x-1/2 ${state(i) === 'done' || s === 'current' ? 'bg-primary' : 'bg-line'}`}
                                    style={{ insetInlineStart: 0 }}
                                />
                            )}
                            <span
                                aria-current={s === 'current' ? 'step' : undefined}
                                className={`relative z-10 flex h-8 w-8 items-center justify-center rounded-full border-2 text-sm font-bold ${style[s]}`}
                            >
                                {s === 'done' ? '✓' : i + 1}
                            </span>
                            <span className={`mt-2 text-sm font-semibold ${s === 'todo' ? 'text-muted' : ''}`}>{t(`order.progress.${step.key}`)}</span>
                            {step.time && s !== 'todo' && <span className="text-xs text-muted">{step.time}</span>}
                            {s === 'current' && issue && <span className="mt-1 text-xs font-semibold text-danger">{t('order.progress.issue')}</span>}
                            {s === 'current' && !issue && step.hint && <span className="mt-1 text-xs text-primary">{step.hint}</span>}
                        </li>
                    );
                })}
                {ended && (
                    <li className="relative flex flex-1 flex-col items-center text-center">
                        <span aria-hidden="true" className="absolute top-4 h-0.5 w-full -translate-x-1/2 bg-danger rtl:translate-x-1/2" style={{ insetInlineStart: 0 }} />
                        <span className={`relative z-10 flex h-8 w-8 items-center justify-center rounded-full border-2 text-sm font-bold ${style.failed}`}>✕</span>
                        <span className="mt-2 text-sm font-semibold text-danger">{t(`order.progress.${order.status}`)}</span>
                        {order.cancelledAt && <span className="text-xs text-muted">{at(order.cancelledAt)}</span>}
                    </li>
                )}
            </ol>
        </nav>
    );
}
