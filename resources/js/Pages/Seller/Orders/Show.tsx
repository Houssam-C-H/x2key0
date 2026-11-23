import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { type OrderDispute, OrderDisputeBox } from '@/Components/Disputes/OrderDisputeBox';
import { DeliveriesPanel, ItemPanel, MoneyPanel, type OrderDetail, OrderProgress, StatusBadge, Timeline } from '@/Components/Orders/OrderParts';
import { DashboardLayout, Panel } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';

interface Props {
    order: OrderDetail & { remark: string | null; acquisitionCost: string; buyerInputs: { label: string; value: string }[]; buyer: string };
    can: { accept: boolean; inTrading: boolean; deliver: boolean; cancel: boolean; write: boolean };
    dispute: OrderDispute;
}

const SECRET_METHODS = ['digital_key', 'code', 'file', 'account_transfer'];

/** Sold order (spec 1.8 / 6.1 / 8.2): buyer inputs, accept → in trading, deliver per method, milestones, notes, cancel. */
export default function SoldOrderShow({ order, can, dispute }: Props) {
    const t = useT();
    const { locale, errors } = useShared();
    const method = order.item.deliveryMethod;
    const base = `/seller/orders/${order.publicId}`;
    const [cancelling, setCancelling] = useState(false);

    const deliver = useForm<{ quantity: number; secrets: string; note: string; evidence: File | null }>({ quantity: order.item.remaining, secrets: '', note: '', evidence: null });
    const milestone = useForm({ title: '', percent: Math.min(100, (order.milestones[order.milestones.length - 1]?.percent ?? 0) + 25), note: '' });
    const notes = useForm({ remark: order.remark ?? '', acquisition_cost: order.acquisitionCost });
    const cancel = useForm({ reason: '' });

    return (
        <DashboardLayout title={t('order.detail_title', { number: order.number })} bare>
            <OrderProgress order={order} />
            <Panel title={order.number}>
                <div className="flex flex-wrap items-center gap-3">
                    <StatusBadge status={order.status} substatus={order.substatus} />
                    <button type="button" className="btn-outline-pill order-last ms-auto px-3 py-1 text-sm" onClick={() => router.post(`/chat/order/${order.publicId}`)}>
                        {t('chat.open_order_chat')}
                    </button>
                    <span className="text-sm text-muted">
                        {t('order.buyer')}: <span className="font-semibold text-ink">{order.buyer}</span>
                    </span>
                    {order.deliveryDueAt && ['paid', 'delivering'].includes(order.status) && (
                        <span className="text-sm text-muted">{t('order.delivery_due', { time: new Date(order.deliveryDueAt).toLocaleString(locale) })}</span>
                    )}
                </div>
                {errors.order && <p className="mt-2 text-sm text-danger">{errors.order}</p>}
                {can.write && (can.accept || can.inTrading) && (
                    <div className="mt-4 flex flex-wrap gap-2">
                        {can.accept && (
                            <button type="button" className="btn-outline-pill px-3 py-1" onClick={() => router.post(`${base}/substatus`, { substatus: 'restocking' }, { preserveScroll: true })}>
                                {t('order.action.accept')}
                            </button>
                        )}
                        {can.inTrading && (
                            <button type="button" className="btn-outline-pill px-3 py-1" onClick={() => router.post(`${base}/substatus`, { substatus: 'in_trading' }, { preserveScroll: true })}>
                                {t('order.action.in_trading')}
                            </button>
                        )}
                    </div>
                )}
                <OrderDisputeBox dispute={dispute} />
            </Panel>

            {order.buyerInputs.length > 0 && (
                <Panel title={t('checkout.buyer_inputs')}>
                    <dl className="grid gap-2 text-sm sm:grid-cols-2">
                        {order.buyerInputs.map((i) => (
                            <div key={i.label}>
                                <dt className="text-xs text-muted">{i.label}</dt>
                                <dd className="break-all font-mono">{i.value}</dd>
                            </div>
                        ))}
                    </dl>
                </Panel>
            )}

            <div className="grid gap-6 lg:grid-cols-[1fr_340px]">
                <div className="space-y-6">
                    <ItemPanel order={order} />
                    <DeliveriesPanel order={order} />

                    {can.deliver && can.write && method !== 'service' && (
                        <Panel title={t('order.deliver.title')} description={t(SECRET_METHODS.includes(method) ? (method === 'account_transfer' ? 'order.deliver.help_accounts' : 'order.deliver.help_codes') : 'order.deliver.help_note')}>
                            <form
                                className="space-y-3"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    deliver.post(`${base}/deliver`, { preserveScroll: true, forceFormData: true, onSuccess: () => deliver.reset('secrets', 'note', 'evidence') });
                                }}
                            >
                                <label className="flex max-w-[10rem] flex-col gap-1 text-sm">
                                    <span className="font-medium">{t('order.deliver.units', { max: order.item.remaining })}</span>
                                    <input className="field" type="number" min={1} max={order.item.remaining} value={deliver.data.quantity} onChange={(e) => deliver.setData('quantity', Number(e.target.value))} />
                                </label>
                                {SECRET_METHODS.includes(method) && (
                                    <label className="flex flex-col gap-1 text-sm">
                                        <span className="font-medium">{t('order.deliver.secrets')}</span>
                                        <textarea className={`field font-mono ${errors.secrets ? 'border-danger' : ''}`} rows={5} autoComplete="off" spellCheck={false} value={deliver.data.secrets} onChange={(e) => deliver.setData('secrets', e.target.value)} />
                                        {errors.secrets && <span className="text-danger">{errors.secrets}</span>}
                                    </label>
                                )}
                                <label className="flex flex-col gap-1 text-sm">
                                    <span className="font-medium">{t('order.deliver.note')}</span>
                                    <textarea className={`field ${errors.note ? 'border-danger' : ''}`} rows={2} value={deliver.data.note} onChange={(e) => deliver.setData('note', e.target.value)} />
                                    {errors.note && <span className="text-danger">{errors.note}</span>}
                                </label>
                                {!SECRET_METHODS.includes(method) && (
                                    <label className="flex flex-col gap-1 text-sm">
                                        <span className="font-medium">{t('order.deliver.evidence')}</span>
                                        <input className="field" type="file" accept="image/jpeg,image/png,image/webp" onChange={(e) => deliver.setData('evidence', e.target.files?.[0] ?? null)} />
                                        {errors.evidence && <span className="text-danger">{errors.evidence}</span>}
                                    </label>
                                )}
                                <button type="submit" className="btn-primary" disabled={deliver.processing}>
                                    {t('order.deliver.submit')}
                                </button>
                            </form>
                        </Panel>
                    )}

                    {can.deliver && can.write && method === 'service' && (
                        <Panel title={t('order.milestone.title')}>
                            <form
                                className="grid gap-3 sm:grid-cols-[1fr_8rem_auto] sm:items-end"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    milestone.post(`${base}/milestones`, { preserveScroll: true, onSuccess: () => milestone.reset('title', 'note') });
                                }}
                            >
                                <label className="flex flex-col gap-1 text-sm">
                                    <span className="font-medium">{t('order.milestone.name')}</span>
                                    <input className="field" value={milestone.data.title} onChange={(e) => milestone.setData('title', e.target.value)} />
                                </label>
                                <label className="flex flex-col gap-1 text-sm">
                                    <span className="font-medium">{t('order.milestone.percent')}</span>
                                    <input className="field" type="number" min={1} max={100} value={milestone.data.percent} onChange={(e) => milestone.setData('percent', Number(e.target.value))} />
                                </label>
                                <button type="submit" className="btn-primary" disabled={milestone.processing}>
                                    {t('order.milestone.add')}
                                </button>
                                {(errors.percent || errors.title) && <p className="text-sm text-danger sm:col-span-3">{errors.percent ?? errors.title}</p>}
                            </form>
                        </Panel>
                    )}
                </div>

                <div className="space-y-6">
                    <MoneyPanel order={order} seller />
                    <Panel title={t('order.private_notes')} description={t('order.private_notes_help')}>
                        <form
                            className="space-y-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                notes.put(`${base}/notes`, { preserveScroll: true });
                            }}
                        >
                            <label className="flex flex-col gap-1 text-sm">
                                <span className="font-medium">{t('order.remark')}</span>
                                <textarea className="field" rows={2} maxLength={500} value={notes.data.remark} onChange={(e) => notes.setData('remark', e.target.value)} />
                            </label>
                            <label className="flex flex-col gap-1 text-sm">
                                <span className="font-medium">{t('order.acquisition_cost')}</span>
                                <input className="field" inputMode="decimal" value={notes.data.acquisition_cost} onChange={(e) => notes.setData('acquisition_cost', e.target.value)} />
                                {notes.errors.acquisition_cost && <span className="text-danger">{notes.errors.acquisition_cost}</span>}
                            </label>
                            <button type="submit" className="btn-outline-pill px-3 py-1" disabled={!can.write || notes.processing}>
                                {t('common.save')}
                            </button>
                        </form>
                    </Panel>
                    {can.cancel && can.write && (
                        <Panel title={t('order.seller_cancel.title')} description={t('order.seller_cancel.help')}>
                            {cancelling ? (
                                <form
                                    className="space-y-2"
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        cancel.post(`${base}/cancel`, { preserveScroll: true });
                                    }}
                                >
                                    <input className="field w-full" placeholder={t('order.seller_cancel.reason')} value={cancel.data.reason} onChange={(e) => cancel.setData('reason', e.target.value)} />
                                    <button type="submit" className="w-full rounded-full bg-danger px-3 py-1.5 font-semibold text-white" disabled={cancel.data.reason.trim() === ''}>
                                        {t('order.seller_cancel.confirm')}
                                    </button>
                                </form>
                            ) : (
                                <button type="button" className="btn-outline-pill px-3 py-1 text-danger" onClick={() => setCancelling(true)}>
                                    {t('order.seller_cancel.title')}
                                </button>
                            )}
                        </Panel>
                    )}
                </div>
            </div>

            <Timeline order={order} />
        </DashboardLayout>
    );
}
