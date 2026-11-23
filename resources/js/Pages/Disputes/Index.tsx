import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { DashboardLayout } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';
import { formatUsd } from '@/lib/money';

export interface DisputeRow {
    id: string;
    case: string;
    href: string;
    order: { number: string; href: string };
    reason: string;
    status: string;
    openedBy: string;
    amount: number;
    currency: string;
    actionRequired: boolean;
    responseDueAt: string | null;
    updatedAt: string;
}

interface Props {
    as: 'buyer' | 'seller';
    status: 'open' | 'closed';
    filter: string | null;
    q: string;
    counts: { open: number; closed: number };
    rows: DisputeRow[];
    isSeller: boolean;
}

export const DISPUTE_STATUS_STYLE: Record<string, string> = {
    awaiting_counterparty: 'bg-warning/15 text-warning',
    negotiating: 'bg-primary-soft text-primary',
    escalated: 'bg-danger/10 text-danger',
    resolved: 'bg-success/10 text-success',
    cancelled: 'bg-canvas text-muted',
    open: 'bg-warning/15 text-warning',
};

/** Dispute Center / "Resolution Center" (spec 1.9): As Buyer · As Seller, Open/Closed counters, quick filters, search. */
export default function DisputesIndex({ as, status, filter, q, counts, rows, isSeller }: Props) {
    const t = useT();
    const { locale } = useShared();
    const [search, setSearch] = useState(q);
    const go = (params: Record<string, string | null>) =>
        router.get('/disputes', Object.fromEntries(Object.entries({ as, status, filter, q: search, ...params }).filter(([, v]) => v !== null && v !== '')), { preserveState: true, preserveScroll: true });

    return (
        <DashboardLayout title={t('menu.disputes.index')}>
            <div className="space-y-4">
                <div className="flex flex-wrap items-center gap-2">
                    {(['buyer', 'seller'] as const).map((role) =>
                        role === 'seller' && !isSeller ? null : (
                            <button key={role} type="button" onClick={() => go({ as: role, filter: null })} className={`rounded-full px-4 py-1.5 text-sm font-semibold ${as === role ? 'bg-primary text-white' : 'bg-canvas'}`}>
                                {t(`dispute.as.${role}`)}
                            </button>
                        ),
                    )}
                    <div className="ms-auto flex gap-2 text-sm">
                        {(['open', 'closed'] as const).map((s) => (
                            <button key={s} type="button" onClick={() => go({ status: s, filter: null })} className={`rounded-md border px-3 py-1.5 ${status === s ? 'border-primary text-primary' : 'border-line'}`}>
                                {t(`dispute.counter.${s}`)} <span className="font-bold">{counts[s]}</span>
                            </button>
                        ))}
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    {status === 'open' &&
                        ['response_required', 'under_review', 'awaiting_other'].map((f) => (
                            <button key={f} type="button" onClick={() => go({ filter: filter === f ? null : f })} className={`rounded-full border px-3 py-1 text-xs font-semibold ${filter === f ? 'border-primary bg-primary-soft text-primary' : 'border-line'}`}>
                                {t(`dispute.filter.${f}`)}
                            </button>
                        ))}
                    <form
                        className="ms-auto flex gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            go({ q: search });
                        }}
                    >
                        <input className="field w-56" value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('dispute.search')} aria-label={t('dispute.search')} />
                        <button type="submit" className="btn-primary px-4">
                            {t('header.search')}
                        </button>
                    </form>
                </div>

                {rows.length === 0 ? (
                    <p className="py-10 text-center text-sm text-muted">{t('common.no_results')}</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="text-xs uppercase text-muted">
                                <tr>
                                    <th className="py-2 text-start">{t('dispute.col.case')}</th>
                                    <th className="py-2 text-start">{t('dispute.col.order')}</th>
                                    <th className="py-2 text-start">{t('dispute.col.reason')}</th>
                                    <th className="py-2 text-start">{t('dispute.col.status')}</th>
                                    <th className="py-2 text-end">{t('dispute.col.amount')}</th>
                                    <th className="py-2 text-start">{t('dispute.col.updated')}</th>
                                    <th className="py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((r) => (
                                    <tr key={r.id} className="border-t border-line">
                                        <td className="py-2 font-mono">{r.case}</td>
                                        <td className="py-2">
                                            <Link href={r.order.href} className="hover:text-primary">
                                                {r.order.number}
                                            </Link>
                                        </td>
                                        <td className="py-2">{t(`dispute.reason.${r.reason}`)}</td>
                                        <td className="py-2">
                                            <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${DISPUTE_STATUS_STYLE[r.status] ?? ''}`}>{t(`dispute.status.${r.status}`)}</span>
                                            {r.actionRequired && <span className="ms-2 text-xs font-semibold text-danger">{t('dispute.response_required')}</span>}
                                        </td>
                                        <td className="py-2 text-end">{formatUsd(r.amount, locale)}</td>
                                        <td className="py-2 text-muted">{new Date(r.updatedAt).toLocaleDateString(locale)}</td>
                                        <td className="py-2 text-end">
                                            <Link href={r.href} className="btn-outline-pill px-3 py-1">
                                                {t('dispute.view')}
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </DashboardLayout>
    );
}
