import { useState } from 'react';
import { CouponCard, type CouponCardData } from '@/Components/Coupons/CouponCard';
import { DashboardLayout } from '@/Layouts/DashboardLayout';
import { formatUsd } from '@/lib/money';
import { useShared, useT } from '@/lib/i18n';

interface Claim {
    id: string;
    label: string;
    name: string;
    store: { name: string; href: string } | null;
    status: 'available' | 'used' | 'expired' | 'revoked';
    expiresAt: string | null;
    minOrder: number;
}

interface Props {
    mode: 'shopping' | 'platform';
    coupons: CouponCardData[];
    claims: Claim[];
    points: number | null;
}

const STATUS_STYLE: Record<Claim['status'], string> = {
    available: 'bg-success/10 text-success',
    used: 'bg-canvas text-muted',
    expired: 'bg-canvas text-muted',
    revoked: 'bg-danger/10 text-danger',
};

/** Coupon Center (spec 14.1): "Get shopping coupons" (store codes) or "Get platform coupons", plus My coupons. */
export default function CouponCenter({ mode, coupons, claims, points }: Props) {
    const t = useT();
    const { locale, errors } = useShared();
    const [tab, setTab] = useState<'get' | 'mine'>('get');
    const money = (cents: number) => formatUsd(cents, locale) ?? '';

    // Store coupons are grouped by store, as on the observed page ("Steam Coupons: 21").
    const groups = mode === 'shopping'
        ? Object.entries(
              coupons.reduce<Record<string, CouponCardData[]>>((acc, c) => {
                  const key = c.store?.name ?? '—';
                  (acc[key] ??= []).push(c);
                  return acc;
              }, {}),
          )
        : [['', coupons] as [string, CouponCardData[]]];

    return (
        <DashboardLayout title={t(mode === 'shopping' ? 'menu.coupons.shopping' : 'menu.coupons.platform')} bare>
            <section className="card p-0">
                <div className="flex flex-wrap items-center gap-2 border-b border-line p-3">
                    {(['get', 'mine'] as const).map((k) => (
                        <button
                            key={k}
                            type="button"
                            aria-pressed={tab === k}
                            onClick={() => setTab(k)}
                            className={'rounded-full border px-3 py-1 text-sm font-semibold ' + (tab === k ? 'border-primary bg-primary-soft text-primary' : 'border-line hover:border-primary')}
                        >
                            {t('coupon.tab.' + k)}
                            {k === 'mine' && ' (' + claims.filter((c) => c.status === 'available').length + ')'}
                        </button>
                    ))}
                    {points !== null && <span className="ms-auto text-sm text-muted">{t('coupon.points_balance', { points })}</span>}
                </div>
                <p className="border-b border-line px-4 py-2 text-xs text-muted">{t(mode === 'shopping' ? 'coupon.help.shopping' : 'coupon.help.platform')}</p>
                {errors.coupon && <p className="px-4 pt-3 text-sm text-danger">{errors.coupon}</p>}

                {tab === 'get' ? (
                    <div className="space-y-5 p-4">
                        {coupons.length === 0 && <p className="text-sm text-muted">{t('coupon.empty')}</p>}
                        {groups.map(([store, list]) => (
                            <div key={store} className="space-y-2">
                                {store !== '' && (
                                    <h2 className="font-bold">
                                        {store} <span className="text-sm font-normal text-muted">({list.length})</span>
                                    </h2>
                                )}
                                <ul className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                    {list.map((c) => (
                                        <li key={c.id}>
                                            <CouponCard coupon={c} points={points} />
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))}
                    </div>
                ) : claims.length === 0 ? (
                    <p className="p-4 text-sm text-muted">{t('coupon.no_claims')}</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-canvas text-start text-xs text-muted">
                                <tr>
                                    <th className="px-4 py-2 text-start">{t('coupon.col.coupon')}</th>
                                    {mode === 'shopping' && <th className="px-4 py-2 text-start">{t('coupon.col.store')}</th>}
                                    <th className="px-4 py-2 text-start">{t('coupon.col.min_order')}</th>
                                    <th className="px-4 py-2 text-start">{t('coupon.col.expires')}</th>
                                    <th className="px-4 py-2 text-start">{t('coupon.col.status')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {claims.map((c) => (
                                    <tr key={c.id}>
                                        <td className="px-4 py-2">
                                            <span className="font-bold text-primary">{c.label}</span> {c.name}
                                        </td>
                                        {mode === 'shopping' && <td className="px-4 py-2">{c.store ? <a href={c.store.href} className="text-primary">{c.store.name}</a> : '—'}</td>}
                                        <td className="px-4 py-2">{c.minOrder > 0 ? money(c.minOrder) : '—'}</td>
                                        <td className="px-4 py-2">{c.expiresAt ? new Date(c.expiresAt).toLocaleDateString(locale) : '—'}</td>
                                        <td className="px-4 py-2">
                                            <span className={'rounded-full px-2 py-0.5 text-xs font-semibold ' + STATUS_STYLE[c.status]}>{t('coupon.status.' + c.status)}</span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>
        </DashboardLayout>
    );
}
