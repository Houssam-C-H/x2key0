import { DashboardLayout, Panel } from '@/Layouts/DashboardLayout';
import { formatUsd } from '@/lib/money';
import { useShared, useT } from '@/lib/i18n';

interface Row {
    id: string;
    type: string;
    quantity: number;
    unitMillicents: number;
    total: number;
    status: 'settled' | 'unsettled' | 'sent';
    createdAt: string;
    settledAt: string | null;
}

/** Mail promotion records (spec 14.3): Type · Quantity · Unit price · Total · Status (Settled / Unsettled) · Created. */
export default function MarketingRecords({ records }: { records: Row[] }) {
    const t = useT();
    const { locale } = useShared();

    return (
        <DashboardLayout title={t('menu.seller.marketing.records')} bare>
            <Panel title={t('menu.seller.marketing.records')}>
                {records.length === 0 ? (
                    <p className="text-sm text-muted">{t('marketing.no_records')}</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-canvas text-xs text-muted">
                                <tr>
                                    <th className="px-3 py-2 text-start">{t('marketing.col.type')}</th>
                                    <th className="px-3 py-2 text-end">{t('marketing.col.quantity')}</th>
                                    <th className="px-3 py-2 text-end">{t('marketing.unit_price')}</th>
                                    <th className="px-3 py-2 text-end">{t('marketing.total')}</th>
                                    <th className="px-3 py-2 text-start">{t('coupon.col.status')}</th>
                                    <th className="px-3 py-2 text-start">{t('marketing.col.created')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {records.map((r) => (
                                    <tr key={r.id}>
                                        <td className="px-3 py-2">{t('marketing.type.' + r.type)}</td>
                                        <td className="px-3 py-2 text-end">{r.quantity}</td>
                                        <td className="px-3 py-2 text-end">USD {(r.unitMillicents / 100000).toFixed(3)}</td>
                                        <td className="px-3 py-2 text-end">{formatUsd(r.total, locale)}</td>
                                        <td className="px-3 py-2">
                                            <span className={'rounded-full px-2 py-0.5 text-xs font-semibold ' + (r.status === 'settled' ? 'bg-success/10 text-success' : 'bg-warning-soft text-ink')}>{t('marketing.status.' + r.status)}</span>
                                        </td>
                                        <td className="px-3 py-2">{new Date(r.createdAt).toLocaleString(locale)}</td>
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
