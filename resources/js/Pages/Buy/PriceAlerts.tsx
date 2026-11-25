import { Link, router } from '@inertiajs/react';
import { DashboardLayout } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';
import { formatUsd } from '@/lib/money';

interface AlertRow {
    id: number;
    title: string;
    href: string;
    target: number;
    lowest: number | null;
    notifiedAt: string | null;
}

/** My price alerts: target vs lowest now, reached or watching, remove. */
export default function PriceAlerts({ alerts, max }: { alerts: AlertRow[]; max: number }) {
    const t = useT();
    const { locale } = useShared();

    return (
        <DashboardLayout title={t('price_alert.page_title')}>
            <p className="mb-4 text-sm text-muted">
                {t('price_alert.page_hint')} ({alerts.length}/{max})
            </p>
            {alerts.length === 0 ? (
                <p className="rounded-xl border border-dashed border-line p-8 text-center text-muted">{t('price_alert.none')}</p>
            ) : (
                <ul className="space-y-2">
                    {alerts.map((a) => {
                        const reached = a.lowest !== null && a.lowest <= a.target;
                        return (
                            <li key={a.id} className="flex flex-wrap items-center gap-3 rounded-xl border border-line p-3">
                                <div className="min-w-0 flex-1">
                                    <Link href={a.href} className="font-semibold hover:text-primary">
                                        {a.title}
                                    </Link>
                                    <p className="text-xs text-muted">
                                        {t('price_alert.col.target')}: <span className="font-semibold text-ink">{formatUsd(a.target, locale)}</span> · {t('price_alert.col.lowest')}:{' '}
                                        <span className="font-semibold text-ink">{a.lowest === null ? '—' : formatUsd(a.lowest, locale)}</span>
                                    </p>
                                </div>
                                <span className={'rounded-full px-2 py-0.5 text-xs font-semibold ' + (reached ? 'bg-success/10 text-success' : 'bg-canvas text-muted')}>
                                    {reached ? t('price_alert.reached') : t('price_alert.waiting')}
                                </span>
                                <button type="button" className="text-xs font-semibold text-danger hover:underline" onClick={() => router.delete(`/price-alerts/${a.id}`, { preserveScroll: true })}>
                                    {t('price_alert.remove')}
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}
        </DashboardLayout>
    );
}
