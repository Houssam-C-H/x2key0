import { router } from '@inertiajs/react';
import { DashboardLayout, Panel } from '@/Layouts/DashboardLayout';
import { formatUsd } from '@/lib/money';
import { useShared, useT } from '@/lib/i18n';

interface Props {
    month: string;
    type: string;
    types: string[];
    rows: { date: string | null; type: string; order: string | null; amount: number }[];
    totals: { commission: number; rewards: number; reversals: number; net: number };
    rank: number;
}

/** Alliance Bill (spec Part 15): the month's commissions, video rewards and reversals, read from the ledger. */
export default function AffiliateBills({ month, type, types, rows, totals, rank }: Props) {
    const t = useT();
    const { locale } = useShared();
    const money = (cents: number) => formatUsd(cents, locale) ?? '';
    const go = (next: { month?: string; type?: string }) => router.get('/affiliate/bills', { month: next.month ?? month, type: (next.type ?? type) === 'all' ? undefined : next.type ?? type }, { preserveScroll: true, preserveState: true });

    return (
        <DashboardLayout title={t('menu.affiliate.bills')} bare>
            <Panel title={t('menu.affiliate.bills')}>
                <div className="mb-4 flex flex-wrap items-end gap-3">
                    <label className="grid gap-1 text-sm">
                        <span className="font-medium">{t('affiliate.bills.month')}</span>
                        <input className="field" type="month" value={month} onChange={(e) => e.target.value && go({ month: e.target.value })} />
                    </label>
                    <label className="grid gap-1 text-sm">
                        <span className="font-medium">{t('marketing.col.type')}</span>
                        <select className="field" value={type} onChange={(e) => go({ type: e.target.value })}>
                            {types.map((k) => (
                                <option key={k} value={k}>
                                    {t('affiliate.bills.type.' + k)}
                                </option>
                            ))}
                        </select>
                    </label>
                </div>
                <dl className="mb-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                    {(['commission', 'rewards', 'reversals', 'net'] as const).map((k) => (
                        <div key={k} className="rounded-md bg-canvas p-3">
                            <dt className="text-xs text-muted">{t('affiliate.bills.total.' + k)}</dt>
                            <dd className="text-lg font-bold">{money(totals[k])}</dd>
                        </div>
                    ))}
                </dl>
                {rows.length === 0 ? (
                    <p className="text-sm text-muted">{t('affiliate.bills.empty')}</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-canvas text-xs text-muted">
                                <tr>
                                    <th className="px-3 py-2 text-start">{t('affiliate.bills.date')}</th>
                                    <th className="px-3 py-2 text-start">{t('marketing.col.type')}</th>
                                    <th className="px-3 py-2 text-start">{t('affiliate.bills.order')}</th>
                                    <th className="px-3 py-2 text-end">{t('affiliate.bills.earning')}</th>
                                    <th className="px-3 py-2 text-end">{t('affiliate.bills.rank')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {rows.map((r, i) => (
                                    <tr key={i}>
                                        <td className="px-3 py-2">{r.date ? new Date(r.date).toLocaleString(locale) : '—'}</td>
                                        <td className="px-3 py-2">{t('affiliate.bills.type.' + r.type)}</td>
                                        <td className="px-3 py-2 font-mono text-xs">{r.order ?? '—'}</td>
                                        <td className={'px-3 py-2 text-end ' + (r.amount < 0 ? 'text-danger' : '')}>{money(r.amount)}</td>
                                        <td className="px-3 py-2 text-end">{rank}</td>
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
