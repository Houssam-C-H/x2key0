import { Link } from '@inertiajs/react';
import { DashboardLayout, Panel } from '@/Layouts/DashboardLayout';
import { formatUsd } from '@/lib/money';
import { useShared, useT } from '@/lib/i18n';

interface Row {
    member: string;
    country: string | null;
    registeredAt: string;
    orders: number;
    recentAt: string | null;
    commission: number;
    active: boolean;
}

/** My Affiliates (spec Part 15): direct and indirect invitees (masked), register date, country, orders, commission. */
export default function AffiliateReferrals({ level, rows, counts }: { level: 1 | 2; rows: Row[]; counts: { direct: number; indirect: number } }) {
    const t = useT();
    const { locale } = useShared();

    return (
        <DashboardLayout title={t('menu.affiliate.referrals')} bare>
            <Panel title={t('menu.affiliate.referrals')}>
                <div className="mb-3 flex gap-2">
                    {([1, 2] as const).map((l) => (
                        <Link
                            key={l}
                            href={'/affiliate/referrals' + (l === 2 ? '?level=2' : '')}
                            preserveScroll
                            className={'rounded-full border px-3 py-1 text-sm font-semibold ' + (level === l ? 'border-primary bg-primary-soft text-primary' : 'border-line')}
                        >
                            {t(l === 1 ? 'affiliate.referrals.direct' : 'affiliate.referrals.indirect')} ({l === 1 ? counts.direct : counts.indirect})
                        </Link>
                    ))}
                </div>
                {rows.length === 0 ? (
                    <p className="text-sm text-muted">{t('affiliate.referrals.empty')}</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-canvas text-xs text-muted">
                                <tr>
                                    <th className="px-3 py-2 text-start">{t('affiliate.referrals.member')}</th>
                                    <th className="px-3 py-2 text-start">{t('affiliate.referrals.registered')}</th>
                                    <th className="px-3 py-2 text-start">{t('affiliate.referrals.country')}</th>
                                    <th className="px-3 py-2 text-end">{t('affiliate.stats.orders')}</th>
                                    <th className="px-3 py-2 text-start">{t('affiliate.referrals.recent')}</th>
                                    <th className="px-3 py-2 text-end">{t('affiliate.referrals.commission')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {rows.map((r, i) => (
                                    <tr key={i}>
                                        <td className="px-3 py-2">
                                            {r.member}
                                            {!r.active && <span className="ms-2 text-xs text-muted">{t('affiliate.referrals.expired')}</span>}
                                        </td>
                                        <td className="px-3 py-2">{new Date(r.registeredAt).toLocaleDateString(locale)}</td>
                                        <td className="px-3 py-2">{r.country ?? '—'}</td>
                                        <td className="px-3 py-2 text-end">{r.orders}</td>
                                        <td className="px-3 py-2">{r.recentAt ? new Date(r.recentAt).toLocaleDateString(locale) : '—'}</td>
                                        <td className="px-3 py-2 text-end">{formatUsd(r.commission, locale)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Panel>
        </DashboardLayout>
    );
}
