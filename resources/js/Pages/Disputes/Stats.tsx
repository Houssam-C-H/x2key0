import { router } from '@inertiajs/react';
import { DashboardLayout } from '@/Layouts/DashboardLayout';
import { useT } from '@/lib/i18n';

interface Props {
    as: 'buyer' | 'seller';
    period: 'month' | '30d' | '90d';
    rows: { title: string; disputes: number; shareBps: number; rateBps: number; refundRateBps: number; orders: number }[];
    total: number;
    isSeller: boolean;
}

const pct = (bps: number) => `${Number((bps / 100).toFixed(2))}%`;

/** Dispute Statistics (spec 1.9): per game × category — disputes, share, dispute rate, refund resolution rate. */
export default function DisputeStats({ as, period, rows, total, isSeller }: Props) {
    const t = useT();
    const go = (params: Record<string, string>) => router.get('/disputes/stats', { as, period, ...params }, { preserveScroll: true });

    return (
        <DashboardLayout title={t('menu.disputes.stats')}>
            <div className="mb-4 flex flex-wrap items-center gap-2">
                {isSeller &&
                    (['buyer', 'seller'] as const).map((role) => (
                        <button key={role} type="button" onClick={() => go({ as: role })} className={`rounded-full px-4 py-1.5 text-sm font-semibold ${as === role ? 'bg-primary text-white' : 'bg-canvas'}`}>
                            {t(`dispute.as.${role}`)}
                        </button>
                    ))}
                <select className="field ms-auto w-48" aria-label={t('dispute.stats.period')} value={period} onChange={(e) => go({ period: e.target.value })}>
                    {(['month', '30d', '90d'] as const).map((p) => (
                        <option key={p} value={p}>
                            {t(`dispute.stats.period_${p}`)}
                        </option>
                    ))}
                </select>
            </div>
            <p className="mb-4 text-sm text-muted">{t('dispute.stats.total', { count: total })}</p>
            {rows.length === 0 ? (
                <p className="py-10 text-center text-sm text-muted">{t('common.no_results')}</p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="text-xs uppercase text-muted">
                            <tr>
                                <th className="py-2 text-start">{t('dispute.stats.service')}</th>
                                <th className="py-2 text-end">{t('dispute.stats.disputes')}</th>
                                <th className="py-2 text-end">{t('dispute.stats.share')}</th>
                                <th className="py-2 text-end">{t('dispute.stats.rate')}</th>
                                <th className="py-2 text-end">{t('dispute.stats.refund_rate')}</th>
                                <th className="py-2 text-end">{t('dispute.stats.orders')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((r) => (
                                <tr key={r.title} className="border-t border-line">
                                    <td className="py-2 font-semibold">{r.title}</td>
                                    <td className="py-2 text-end">{r.disputes}</td>
                                    <td className="py-2 text-end">{pct(r.shareBps)}</td>
                                    <td className="py-2 text-end">{pct(r.rateBps)}</td>
                                    <td className="py-2 text-end">{pct(r.refundRateBps)}</td>
                                    <td className="py-2 text-end">{r.orders}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </DashboardLayout>
    );
}
