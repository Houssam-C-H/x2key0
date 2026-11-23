import { MonthPicker } from '@/Components/Orders/MonthPicker';
import { DashboardLayout, Panel } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';

interface Row {
    type: string;
    description: string | null;
    amount: number;
    at: string;
    order: string | null;
}

/** Buy › Points Record (spec 1.7): current points and the month's statement — Date · Order · Score · Behavior. */
export default function Points({ points, month, months, rows }: { points: number; month: string; months: string[]; rows: Row[] }) {
    const t = useT();
    const { locale } = useShared();

    return (
        <DashboardLayout title={t('menu.buy.points')} bare>
            <Panel title={t('points.current')} description={t('points.help')}>
                <p className="text-3xl font-extrabold">{new Intl.NumberFormat(locale).format(points)}</p>
            </Panel>
            <Panel title={t('points.record')}>
                <div className="mb-3">
                    <MonthPicker path="/buy/points" month={month} months={months} />
                </div>
                {rows.length === 0 ? (
                    <p className="py-8 text-center text-sm text-muted">{t('common.no_results')}</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[560px] text-sm">
                            <thead className="text-xs uppercase text-muted">
                                <tr>
                                    <th className="py-2 text-start">{t('storage.import.date')}</th>
                                    <th className="py-2 text-start">{t('order.col.order')}</th>
                                    <th className="py-2 text-end">{t('points.score')}</th>
                                    <th className="py-2 text-start ps-4">{t('points.behavior')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((r, i) => (
                                    <tr key={i} className="border-t border-line">
                                        <td className="py-2 text-muted">{new Date(r.at).toLocaleString(locale)}</td>
                                        <td className="py-2">{r.order ?? '—'}</td>
                                        <td className={`py-2 text-end font-semibold ${r.amount >= 0 ? 'text-success' : 'text-danger'}`}>
                                            {r.amount >= 0 ? '+' : '−'}
                                            {new Intl.NumberFormat(locale).format(Math.abs(r.amount))}
                                        </td>
                                        <td className="py-2 ps-4">{t(`points.type.${r.type}`)}</td>
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
