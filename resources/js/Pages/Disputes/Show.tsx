import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { TextArea } from '@/Components/Field';
import { DashboardLayout, Panel } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';
import { formatUsd } from '@/lib/money';
import { DISPUTE_STATUS_STYLE, type DisputeRow } from './Index';

interface Detail extends DisputeRow {
    viewer: 'buyer' | 'seller';
    description: string;
    requested: { resolution: string; qty: number; toCredits: boolean };
    proposal: { resolution: string; qty: number | null; by: string | null } | null;
    item: { title: string; quantity: number; delivered: number; refunded: number; unitPrice: number };
    orderStatus: string;
    messages: { id: number; role: string; body: string; mine: boolean; hidden: boolean; author: string | null; at: string }[];
    evidence: { id: number; name: string; mime: string; size: number; by: string; href: string; at: string }[];
    decision: { outcome: string; byStaff: boolean; refundQty: number; refundAmount: number; refundDestination: string | null; redeliverQty: number; rationale: string | null; at: string } | null;
    escalatedAt: string | null;
    closedAt: string | null;
}

interface Props {
    dispute: Detail;
    can: { reply: boolean; propose: boolean; accept: boolean; escalate: boolean; withdraw: boolean };
    resolutions: string[];
    maxFiles: number;
}

/** A dispute case (spec Part 10 / 6.4): summary, request and proposals, thread with evidence, actions, decision. */
export default function DisputeShow({ dispute, can, resolutions, maxFiles }: Props) {
    const t = useT();
    const { locale, errors } = useShared();
    const base = dispute.href;
    const reply = useForm<{ body: string; files: File[] }>({ body: '', files: [] });
    const propose = useForm({ resolution: resolutions[0] ?? 'refund_partial', qty: 1, note: '' });
    const [showPropose, setShowPropose] = useState(false);
    const post = (path: string, confirmKey?: string) => {
        if (confirmKey && !window.confirm(t(confirmKey))) return;
        router.post(`${base}/${path}`, {}, { preserveScroll: true });
    };
    const outcomeText = (resolution: string, qty: number | null) => t('dispute.outcome_qty', { outcome: t(`dispute.outcome.${resolution}`), qty: qty ?? 0 });

    return (
        <DashboardLayout title={t('dispute.show.title', { case: dispute.case })} bare>
            <Panel title={t('dispute.show.title', { case: dispute.case })}>
                <div className="flex flex-wrap items-center gap-3">
                    <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${DISPUTE_STATUS_STYLE[dispute.status] ?? ''}`}>{t(`dispute.status.${dispute.status}`)}</span>
                    <span className="text-sm text-muted">
                        {t('dispute.col.order')}:{' '}
                        <Link href={dispute.order.href} className="font-semibold text-ink hover:text-primary">
                            {dispute.order.number}
                        </Link>
                    </span>
                    <span className="text-sm text-muted">{t(`dispute.reason.${dispute.reason}`)}</span>
                    <span className="text-sm text-muted">{t('dispute.opened_by', { role: t(`dispute.by.${dispute.openedBy}`) })}</span>
                </div>
                <p className="mt-3 text-sm">{t(`dispute.explain.${dispute.status}`)}</p>
                {dispute.responseDueAt && <p className="mt-1 text-sm text-muted">{t('dispute.response_due', { time: new Date(dispute.responseDueAt).toLocaleString(locale) })}</p>}
                {errors.dispute && <p className="mt-3 rounded-md bg-danger/5 p-3 text-sm text-danger">{errors.dispute}</p>}

                <dl className="mt-4 grid gap-4 text-sm sm:grid-cols-4">
                    <div>
                        <dt className="text-xs text-muted">{t('dispute.show.item')}</dt>
                        <dd className="font-semibold">{dispute.item.title}</dd>
                    </div>
                    <div>
                        <dt className="text-xs text-muted">{t('dispute.show.units')}</dt>
                        <dd className="font-semibold">{t('dispute.show.units_value', { quantity: dispute.item.quantity, delivered: dispute.item.delivered, refunded: dispute.item.refunded })}</dd>
                    </div>
                    <div>
                        <dt className="text-xs text-muted">{t('dispute.show.requested')}</dt>
                        <dd className="font-semibold">{outcomeText(dispute.requested.resolution, dispute.requested.qty)}</dd>
                    </div>
                    <div>
                        <dt className="text-xs text-muted">{t('dispute.col.amount')}</dt>
                        <dd className="font-semibold">{formatUsd(dispute.amount, locale)}</dd>
                    </div>
                </dl>

                {dispute.proposal && (
                    <div className="mt-4 rounded-md bg-primary-soft p-3 text-sm">
                        {t('dispute.show.proposal', { by: t(`dispute.by.${dispute.proposal.by ?? 'buyer'}`), outcome: outcomeText(dispute.proposal.resolution, dispute.proposal.qty) })}
                    </div>
                )}

                <div className="mt-4 flex flex-wrap gap-2">
                    {can.accept && (
                        <button type="button" className="btn-primary px-4 py-1.5" onClick={() => post('accept', 'dispute.confirm.accept')}>
                            {dispute.proposal && dispute.proposal.by !== dispute.viewer ? t('dispute.action.accept_proposal') : t('dispute.action.accept_request')}
                        </button>
                    )}
                    {can.propose && (
                        <button type="button" className="btn-outline-pill px-3 py-1" onClick={() => setShowPropose((v) => !v)}>
                            {t('dispute.action.propose')}
                        </button>
                    )}
                    {can.escalate && (
                        <button type="button" className="btn-outline-pill px-3 py-1" onClick={() => post('escalate', 'dispute.confirm.escalate')}>
                            {t('dispute.action.escalate')}
                        </button>
                    )}
                    {can.withdraw && (
                        <button type="button" className="btn-outline-pill px-3 py-1 text-danger" onClick={() => post('withdraw', 'dispute.confirm.withdraw')}>
                            {t('dispute.action.withdraw')}
                        </button>
                    )}
                </div>

                {showPropose && can.propose && (
                    <form
                        className="mt-4 grid gap-3 rounded-md border border-line p-4 sm:grid-cols-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            propose.post(`${base}/propose`, { preserveScroll: true, onSuccess: () => setShowPropose(false) });
                        }}
                    >
                        <select className="field" aria-label={t('dispute.create.resolution')} value={propose.data.resolution} onChange={(e) => propose.setData('resolution', e.target.value)}>
                            {resolutions.map((r) => (
                                <option key={r} value={r}>
                                    {t(`dispute.outcome.${r}`)}
                                </option>
                            ))}
                        </select>
                        <input type="number" min={0} max={dispute.item.quantity} className="field" aria-label={t('dispute.create.qty')} value={propose.data.qty} onChange={(e) => propose.setData('qty', Number(e.target.value))} />
                        <button type="submit" className="btn-primary" disabled={propose.processing}>
                            {t('dispute.action.send_proposal')}
                        </button>
                        <div className="sm:col-span-3">
                            <TextArea label={t('dispute.show.note')} name="note" value={propose.data.note} onChange={(v) => propose.setData('note', v)} error={propose.errors.note} rows={2} />
                        </div>
                    </form>
                )}
            </Panel>

            {dispute.decision && (
                <Panel title={t('dispute.decision.title')}>
                    <p className="text-sm font-semibold">
                        {outcomeText(dispute.decision.outcome, dispute.decision.refundQty || dispute.decision.redeliverQty)} · {t(dispute.decision.byStaff ? 'dispute.decision.by_staff' : 'dispute.decision.by_agreement')}
                    </p>
                    {dispute.decision.refundAmount > 0 && (
                        <p className="mt-1 text-sm">{t('dispute.decision.refunded', { amount: formatUsd(dispute.decision.refundAmount, locale) ?? '', destination: t(`dispute.destination.${dispute.decision.refundDestination ?? 'original'}`) })}</p>
                    )}
                    {dispute.decision.rationale && <p className="mt-2 whitespace-pre-line text-sm text-muted">{dispute.decision.rationale}</p>}
                </Panel>
            )}

            <Panel title={t('dispute.thread.title')}>
                <div className="rounded-md bg-canvas p-3 text-sm">
                    <p className="text-xs font-semibold text-muted">{t('dispute.thread.description')}</p>
                    <p className="mt-1 whitespace-pre-line">{dispute.description}</p>
                </div>
                <ul className="mt-4 space-y-3">
                    {dispute.messages.map((m) =>
                        m.role === 'system' ? (
                            <li key={m.id} className="text-center text-xs text-muted">
                                {m.body} · {new Date(m.at).toLocaleString(locale)}
                            </li>
                        ) : (
                            <li key={m.id} className={`flex ${m.mine ? 'justify-end' : 'justify-start'}`}>
                                <div className={`max-w-[80%] rounded-lg px-3 py-2 text-sm ${m.mine ? 'bg-primary text-white' : m.role === 'staff' ? 'bg-warning/15' : 'bg-canvas'}`}>
                                    <p className="text-xs opacity-75">
                                        {m.role === 'staff' ? t('dispute.by.staff') : (m.author ?? t(`dispute.by.${m.role}`))} · {new Date(m.at).toLocaleString(locale)}
                                    </p>
                                    <p className="mt-1 whitespace-pre-line">{m.body}</p>
                                    {m.hidden && m.mine && <p className="mt-1 text-xs opacity-75">{t('chat.contact_hidden')}</p>}
                                </div>
                            </li>
                        ),
                    )}
                </ul>

                {dispute.evidence.length > 0 && (
                    <div className="mt-6">
                        <h3 className="mb-2 text-sm font-bold">{t('dispute.evidence.title')}</h3>
                        <ul className="space-y-1 text-sm">
                            {dispute.evidence.map((e) => (
                                <li key={e.id} className="flex flex-wrap gap-2">
                                    <a href={e.href} target="_blank" rel="noreferrer" className="text-primary hover:underline">
                                        {e.name}
                                    </a>
                                    <span className="text-muted">
                                        {t(`dispute.by.${e.by}`)} · {Math.ceil(e.size / 1024)} KB
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {can.reply && (
                    <form
                        className="mt-6 space-y-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            reply.post(`${base}/messages`, { preserveScroll: true, forceFormData: true, onSuccess: () => reply.reset() });
                        }}
                    >
                        <TextArea label={t('dispute.thread.reply')} name="body" value={reply.data.body} onChange={(v) => reply.setData('body', v)} error={reply.errors.body} rows={3} />
                        <div className="flex flex-wrap items-center gap-3">
                            <input type="file" multiple aria-label={t('dispute.evidence.add')} onChange={(e) => reply.setData('files', Array.from(e.target.files ?? []).slice(0, maxFiles))} />
                            <button type="submit" className="btn-primary ms-auto" disabled={reply.processing || (reply.data.body.trim() === '' && reply.data.files.length === 0)}>
                                {t('dispute.thread.send')}
                            </button>
                        </div>
                    </form>
                )}
            </Panel>
        </DashboardLayout>
    );
}
