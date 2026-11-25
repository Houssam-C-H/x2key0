import { Link, router } from '@inertiajs/react';
import { DashboardLayout, Panel } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';
import { formatUsd } from '@/lib/money';
import { deliveryTime } from '@/lib/offer';

interface Analytics {
    days: number;
    series: { day: string; orders: number; sales: number }[];
    totals: { orders: number; sales: number; net: number; visitors: number; conversionBps: number | null; avgDeliveryMinutes: number | null };
    topOffers: { title: string; orders: number; units: number; sales: number }[];
    pricing: { title: string; href: string; price: number; lowestOther: number | null; position: 'cheapest' | 'alone' | 'above' }[];
}

/** Seller Center › Analytics. */
export default function SellerAnalytics({ analytics: a }: { analytics: Analytics }) {
    const t = useT();
    const { locale } = useShared();
    const money = (c: number) => formatUsd(c, locale) ?? '';
    const max = Math.max(1, ...a.series.map((s) => s.sales));

    const cards: [string, string][] = [
        [t('analytics.orders'), String(a.totals.orders)],
        [t('analytics.sales'), money(a.totals.sales)],
        [t('analytics.net'), money(a.totals.net)],
        [t('analytics.visitors'), String(a.totals.visitors)],
        [t('analytics.conversion'), a.totals.conversionBps === null ? '—' : (a.totals.conversionBps / 100).toFixed(1) + '%'],
        [t('analytics.delivery'), a.totals.avgDeliveryMinutes === null ? '—' : deliveryTime(a.totals.avgDeliveryMinutes, t)],
    ];

    return (
        <DashboardLayout title={t('menu.seller.analytics')} bare>
            <div className="flex flex-wrap gap-2">
                {[7, 30, 90].map((d) => (
                    <button
                        key={d}
                        type="button"
                        onClick={() => router.get('/seller/analytics', { days: d }, { preserveScroll: true })}
                        className={'rounded-full px-4 py-1.5 text-sm font-semibold ' + (a.days === d ? 'bg-primary text-white' : 'bg-white ring-1 ring-line hover:ring-primary')}
                    >
                        {t('analytics.range', { days: d })}
                    </button>
                ))}
            </div>

            <ul className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                {cards.map(([label, value]) => (
                    <li key={label} className="card p-4">
                        <p className="text-xs text-muted">{label}</p>
                        <p className="mt-1 text-xl font-bold">{value}</p>
                    </li>
                ))}
            </ul>

            <Panel title={t('analytics.chart')}>
                <div className="flex h-40 items-end gap-[2px]" role="img" aria-label={t('analytics.chart')}>
                    {a.series.map((s) => (
                        <div key={s.day} className="group relative flex h-full flex-1 items-end">
                            <div className="w-full rounded-t bg-primary/80 transition group-hover:bg-primary" style={{ height: `${Math.max(s.sales > 0 ? 4 : 1, (s.sales / max) * 100)}%` }} />
                            <span className="pointer-events-none absolute bottom-full left-1/2 z-10 mb-1 hidden -translate-x-1/2 whitespace-nowrap rounded bg-ink px-2 py-1 text-[11px] text-white group-hover:block">
                                {s.day.slice(5)} · {money(s.sales)} · {s.orders}
                            </span>
                        </div>
                    ))}
                </div>
                <div className="mt-1 flex justify-between text-[11px] text-muted">
                    <span>{a.series[0]?.day.slice(5)}</span>
                    <span>{a.series[a.series.length - 1]?.day.slice(5)}</span>
                </div>
            </Panel>

            <Panel title={t('analytics.top')}>
                {a.topOffers.length === 0 ? (
                    <p className="text-sm text-muted">{t('analytics.top_none')}</p>
                ) : (
                    <ul className="divide-y divide-line">
                        {a.topOffers.map((o) => (
                            <li key={o.title} className="flex items-center gap-3 py-2 text-sm">
                                <span className="min-w-0 flex-1 truncate font-medium">{o.title}</span>
                                <span className="text-muted">
                                    {o.units} {t('analytics.col.units').toLowerCase()}
                                </span>
                                <span className="w-24 text-end font-semibold">{money(o.sales)}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>

            <Panel title={t('analytics.pricing')} description={t('analytics.pricing_hint')}>
                {a.pricing.length === 0 ? (
                    <p className="text-sm text-muted">—</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[520px] text-sm">
                            <thead className="text-xs uppercase text-muted">
                                <tr>
                                    <th className="py-2 text-start">{t('analytics.col.offer')}</th>
                                    <th className="text-end">{t('analytics.col.yours')}</th>
                                    <th className="text-end">{t('analytics.col.lowest')}</th>
                                    <th className="text-end" />
                                </tr>
                            </thead>
                            <tbody>
                                {a.pricing.map((p) => {
                                    const pct = p.lowestOther ? Math.round(((p.price - p.lowestOther) / p.lowestOther) * 100) : 0;
                                    return (
                                        <tr key={p.href} className="border-t border-line">
                                            <td className="max-w-[260px] truncate py-2">
                                                <Link href={p.href} className="hover:text-primary">
                                                    {p.title}
                                                </Link>
                                            </td>
                                            <td className="text-end font-semibold">{money(p.price)}</td>
                                            <td className="text-end">{p.lowestOther === null ? '—' : money(p.lowestOther)}</td>
                                            <td className="text-end">
                                                <span className={'rounded-full px-2 py-0.5 text-xs font-semibold ' + (p.position === 'above' ? 'bg-warning/15 text-warning-ink' : 'bg-success/10 text-success')}>
                                                    {p.position === 'above' ? t('analytics.position.above', { pct }) : t('analytics.position.' + p.position)}
                                                </span>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </Panel>
        </DashboardLayout>
    );
}
