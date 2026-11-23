import { router } from '@inertiajs/react';
import { MonthPicker } from '@/Components/Orders/MonthPicker';
import { DashboardLayout, Panel } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';
import { formatUsd } from '@/lib/money';

interface Row {
    type: string;
    order: string | null;
    amount: number;
    currency: string;
    at: string;
}

interface Props {
    month: string;
    months: string[];
    type: 'in' | 'out' | null;
    inflow: number;
    outflow: number;
    rows: Row[];
}

/** Buy › Funding Details (spec 1.7 / 7.3): funds inflow / outflow totals and the month's movements, CSV export. */
export default function Funding({ month, months, type, inflow, outflow, rows }: Props) {
    const t = useT();
    const { locale } = useShared();
    const query = { month, ...(type ? { type } : {}) };

    return (
        <DashboardLayout title={t('menu.buy.funding')} bare>
            <div className="grid gap-4 sm:grid-cols-2">
                <Panel title={t('funding.inflow')}>
                    <p className="text-2xl font-extrabold text-success">{formatUsd(inflow, locale)}</p>
                </Panel>
                <Panel title={t('funding.outflow')}>
                    <p className="text-2xl font-extrabold text-danger">{formatUsd(outflow, locale)}</p>
                </Panel>
            </div>
            <Panel title={t('menu.buy.funding')}>
                <div className="mb-3 flex flex-wrap items-center gap-3">
                    <MonthPicker path="/buy/funding" month={month} months={months} extra={type ? { type } : {}} />
                    <label className="flex items-center gap-2 text-sm">
                        <span className="font-medium">{t('funding.type')}</span>
                        <select className="field w-auto py-1.5" value={type ?? ''} onChange={(e) => router.get('/buy/funding', { month, type: e.target.value || undefined }, { preserveScroll: true, preserveState: true })}>
                            <option value="">{t('funding.type.all')}</option>
                            <option value="in">{t('funding.inflow')}</option>
                            <option value="out">{t('funding.outflow')}</option>
                        </select>
                    </label>
                    <a className="btn-outline-pill ms-auto px-3 py-1 text-sm" href={`/buy/funding/export?${new URLSearchParams(query).toString()}`}>
                        {t('funding.export')}
                    </a>
                </div>
                {rows.length === 0 ? (
                    <p className="py-8 text-center text-sm text-muted">{t('common.no_results')}</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[560px] text-sm">
                            <thead className="text-xs uppercase text-muted">
                                <tr>
                                    <th className="py-2 text-start">{t('order.col.order')}</th>
                                    <th className="py-2 text-start">{t('funding.type')}</th>
                                    <th className="py-2 text-end">{t('credits.amount')}</th>
                                    <th className="py-2 text-start ps-4">{t('storage.import.date')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((r, i) => (
                                    <tr key={i} className="border-t border-line">
                                        <td className="py-2">{r.order ?? '—'}</td>
                                        <td className="py-2">{t(`funding.kind.${r.type}`)}</td>
                                        <td className={`py-2 text-end font-semibold ${r.amount >= 0 ? 'text-success' : 'text-danger'}`}>
                                            {r.amount >= 0 ? '+' : '−'} {formatUsd(Math.abs(r.amount), locale)}
                                        </td>
                                        <td className="py-2 ps-4 text-muted">{new Date(r.at).toLocaleString(locale)}</td>
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
