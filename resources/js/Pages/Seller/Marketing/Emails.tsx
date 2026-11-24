import { router } from '@inertiajs/react';
import { useState } from 'react';
import { DashboardLayout, Panel } from '@/Layouts/DashboardLayout';
import { formatUsd } from '@/lib/money';
import { useShared, useT } from '@/lib/i18n';

interface Props {
    types: string[];
    offers: { id: string; title: string }[];
    coupons: { id: string; code: string; label: string }[];
    range: { from: string; to: string };
    preview: { count: number; unitMillicents: number; total: number; dailyLeft: number; monthlyLeft: number };
    maxOffers: number;
}

/**
 * Marketing Email (spec 14.3): 1 mail type → 2 offers + optional private coupon → 3 past buyers (date range) →
 * 4 preview & cost → Send. Billed the next day from the Available balance.
 */
export default function MarketingEmails({ types, offers, coupons, range, preview, maxOffers }: Props) {
    const t = useT();
    const { locale, errors } = useShared();
    const [type, setType] = useState(types[0] ?? 'new_product');
    const [picked, setPicked] = useState<string[]>([]);
    const [coupon, setCoupon] = useState('');
    const [from, setFrom] = useState(range.from);
    const [to, setTo] = useState(range.to);
    const [confirming, setConfirming] = useState(false);
    const [sending, setSending] = useState(false);
    const money = (cents: number) => formatUsd(cents, locale) ?? '';
    const overCap = preview.count > preview.dailyLeft || preview.count > preview.monthlyLeft;

    const refresh = (f: string, tt: string) => router.get('/seller/marketing/emails', { from: f, to: tt }, { preserveState: true, preserveScroll: true, replace: true, only: ['preview', 'range'] });
    const toggle = (id: string) => setPicked((p) => (p.includes(id) ? p.filter((x) => x !== id) : p.length >= maxOffers ? p : [...p, id]));
    const send = () => {
        setSending(true);
        router.post('/seller/marketing/emails', { type, offers: picked, coupon: coupon || null, from, to }, { preserveScroll: true, onFinish: () => { setSending(false); setConfirming(false); } });
    };

    return (
        <DashboardLayout title={t('menu.seller.marketing.emails')} bare>
            <Panel title={t('marketing.step.type')}>
                <div className="grid gap-2 sm:grid-cols-2">
                    {types.map((k) => (
                        <label key={k} className={'flex items-center gap-2 rounded-md border px-3 py-2 text-sm ' + (type === k ? 'border-primary bg-primary-soft' : 'border-line')}>
                            <input type="radio" name="type" checked={type === k} onChange={() => setType(k)} />
                            {t('marketing.type.' + k)}
                        </label>
                    ))}
                </div>
            </Panel>

            <Panel title={t('marketing.step.offers')} description={t('marketing.offers_help', { max: maxOffers })}>
                <ul className="max-h-64 space-y-1 overflow-y-auto rounded-md border border-line p-2">
                    {offers.length === 0 && <li className="text-sm text-muted">{t('campaign.no_offers')}</li>}
                    {offers.map((o) => (
                        <li key={o.id}>
                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={picked.includes(o.id)} onChange={() => toggle(o.id)} />
                                {o.title}
                            </label>
                        </li>
                    ))}
                </ul>
                <label className="mt-3 grid max-w-sm gap-1 text-sm">
                    <span className="font-medium">{t('marketing.coupon')}</span>
                    <select className="field" value={coupon} onChange={(e) => setCoupon(e.target.value)}>
                        <option value="">{t('marketing.no_coupon')}</option>
                        {coupons.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.code} ({c.label})
                            </option>
                        ))}
                    </select>
                </label>
            </Panel>

            <Panel title={t('marketing.step.buyers')} description={t('marketing.buyers_help')}>
                <div className="flex flex-wrap items-end gap-3">
                    <label className="grid gap-1 text-sm">
                        <span className="font-medium">{t('marketing.from')}</span>
                        <input className="field" type="date" value={from} onChange={(e) => { setFrom(e.target.value); refresh(e.target.value, to); }} />
                    </label>
                    <label className="grid gap-1 text-sm">
                        <span className="font-medium">{t('marketing.to')}</span>
                        <input className="field" type="date" value={to} onChange={(e) => { setTo(e.target.value); refresh(from, e.target.value); }} />
                    </label>
                </div>
            </Panel>

            <Panel title={t('marketing.step.preview')}>
                <dl className="grid gap-2 text-sm sm:grid-cols-2">
                    <div className="flex justify-between gap-4"><dt className="text-muted">{t('marketing.recipients')}</dt><dd className="font-semibold">{preview.count}</dd></div>
                    <div className="flex justify-between gap-4"><dt className="text-muted">{t('marketing.unit_price')}</dt><dd>USD {(preview.unitMillicents / 100000).toFixed(3)}</dd></div>
                    <div className="flex justify-between gap-4"><dt className="text-muted">{t('marketing.total')}</dt><dd className="font-semibold">{money(preview.total)}</dd></div>
                    <div className="flex justify-between gap-4"><dt className="text-muted">{t('marketing.caps')}</dt><dd>{t('marketing.caps_left', { day: preview.dailyLeft, month: preview.monthlyLeft })}</dd></div>
                </dl>
                <p className="mt-2 text-xs text-muted">{t('marketing.billing_note')}</p>
                {overCap && <p className="mt-2 text-sm text-danger">{t('marketing.error.cap')}</p>}
                {errors.send && <p className="mt-2 text-sm text-danger">{errors.send}</p>}
                {confirming ? (
                    <div className="mt-3 flex flex-wrap items-center gap-2 rounded-md bg-warning-soft p-3 text-sm">
                        <span>{t('marketing.confirm', { count: preview.count, total: money(preview.total) })}</span>
                        <button type="button" className="btn-primary" onClick={send} disabled={sending}>
                            {t('marketing.send')}
                        </button>
                        <button type="button" className="btn-outline-pill" onClick={() => setConfirming(false)}>
                            {t('common.cancel')}
                        </button>
                    </div>
                ) : (
                    <button type="button" className="btn-primary mt-3" disabled={picked.length === 0 || preview.count === 0 || overCap} onClick={() => setConfirming(true)}>
                        {t('marketing.review')}
                    </button>
                )}
            </Panel>
        </DashboardLayout>
    );
}
