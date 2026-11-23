import { MonthPicker } from '@/Components/Orders/MonthPicker';
import { DashboardLayout, Panel } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';
import { formatUsd } from '@/lib/money';

interface Row {
    journalType: string;
    description: string | null;
    currency: string;
    amount: number;
    balance: number;
    at: string;
    order: string | null;
}

/** Buy › Credits Balance (spec 1.7): balance and the month's log — Date · Description · Currency · Amount · Balance. */
export default function Credits({ balance, month, months, rows }: { balance: number; currency: string; month: string; months: string[]; rows: Row[] }) {
    const t = useT();
    const { locale } = useShared();

    return (
        <DashboardLayout title={t('menu.buy.credits')} bare>
            <Panel title={t('credits.balance')} description={t('credits.help')}>
                <p className="text-3xl font-extrabold">{formatUsd(balance, locale)}</p>
            </Panel>
            <Panel title={t('credits.log')}>
                <div className="mb-3">
                    <MonthPicker path="/buy/credits" month={month} months={months} />
                </div>
                {rows.length === 0 ? (
                    <p className="py-8 text-center text-sm text-muted">{t('common.no_results')}</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[640px] text-sm">
                            <thead className="text-xs uppercase text-muted">
                                <tr>
                                    <th className="py-2 text-start">{t('storage.import.date')}</th>
                                    <th className="py-2 text-start">{t('credits.description')}</th>
                                    <th className="py-2 text-start">{t('credits.currency')}</th>
                                    <th className="py-2 text-end">{t('credits.amount')}</th>
                                    <th className="py-2 text-end">{t('credits.balance_after')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((r, i) => (
                                    <tr key={i} className="border-t border-line">
                                        <td className="py-2 text-muted">{new Date(r.at).toLocaleString(locale)}</td>
                                        <td className="py-2">
                                            {t(`ledger.type.${r.journalType}`)}
                                            {r.order && <span className="text-muted"> · {r.order}</span>}
                                        </td>
                                        <td className="py-2">{r.currency}</td>
                                        <td className={`py-2 text-end font-semibold ${r.amount >= 0 ? 'text-success' : 'text-danger'}`}>
                                            {r.amount >= 0 ? '+' : '−'} {formatUsd(Math.abs(r.amount), locale)}
                                        </td>
                                        <td className="py-2 text-end">{formatUsd(r.balance, locale)}</td>
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
