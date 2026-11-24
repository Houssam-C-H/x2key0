import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { DashboardLayout, Panel } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';

interface Row {
    id: string;
    code: string | null;
    label: string;
    scope: string | null;
    used: number;
    claimed: number;
    limit: number | null;
    validTo: string | null;
    live: boolean;
    status: string;
}

interface Props {
    private: boolean;
    coupons: Row[];
    services: { id: number; title: string }[];
    maxPercent: number;
    maxCodes: number;
}

/** My Coupon Codes (spec 1.8): public store codes or private "Recommend" codes; add in batch, extend, end. */
export default function SellerCoupons({ private: isPrivate, coupons, services, maxPercent, maxCodes }: Props) {
    const t = useT();
    const { locale, errors } = useShared();
    const [selected, setSelected] = useState<string[]>([]);
    const [extending, setExtending] = useState<string | null>(null);
    const [extendTo, setExtendTo] = useState('');
    const inMonth = new Date(Date.now() + 30 * 864e5).toISOString().slice(0, 10);
    const form = useForm({ codes: '', percent: '10', game_category_id: '', use_limit: '', expires_at: inMonth, is_public: !isPrivate });

    const add = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/seller/coupons', { preserveScroll: true, onSuccess: () => form.reset('codes') });
    };
    const toggle = (id: string) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));

    return (
        <DashboardLayout title={t(isPrivate ? 'menu.seller.store.coupons_private' : 'menu.seller.store.coupons')} bare>
            <Panel title={t('coupon.seller.add')} description={t(isPrivate ? 'coupon.seller.help_private' : 'coupon.seller.help_public')}>
                <form onSubmit={add} className="grid gap-3 md:grid-cols-2">
                    <label className="grid gap-1 text-sm md:col-span-2">
                        <span className="font-medium">{t('coupon.seller.codes', { max: maxCodes })}</span>
                        <textarea className="field min-h-20 font-mono uppercase" value={form.data.codes} onChange={(e) => form.setData('codes', e.target.value)} placeholder="SPRING10" />
                        {errors.codes && <span className="text-sm text-danger">{errors.codes}</span>}
                    </label>
                    <label className="grid gap-1 text-sm">
                        <span className="font-medium">{t('coupon.seller.percent', { max: maxPercent })}</span>
                        <input className="field" type="number" min={1} max={maxPercent} step="0.5" value={form.data.percent} onChange={(e) => form.setData('percent', e.target.value)} />
                    </label>
                    <label className="grid gap-1 text-sm">
                        <span className="font-medium">{t('coupon.seller.scope')}</span>
                        <select className="field" value={form.data.game_category_id} onChange={(e) => form.setData('game_category_id', e.target.value)}>
                            <option value="">{t('coupon.seller.all_store')}</option>
                            {services.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.title}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="grid gap-1 text-sm">
                        <span className="font-medium">{t('coupon.seller.use_limit')}</span>
                        <input className="field" type="number" min={1} value={form.data.use_limit} onChange={(e) => form.setData('use_limit', e.target.value)} placeholder="∞" />
                    </label>
                    <label className="grid gap-1 text-sm">
                        <span className="font-medium">{t('coupon.seller.expires')}</span>
                        <input className="field" type="date" value={form.data.expires_at} onChange={(e) => form.setData('expires_at', e.target.value)} />
                        {errors.expires_at && <span className="text-sm text-danger">{errors.expires_at}</span>}
                    </label>
                    <div className="md:col-span-2">
                        <button type="submit" className="btn-primary" disabled={form.processing || form.data.codes.trim() === ''}>
                            {t('coupon.seller.create')}
                        </button>
                    </div>
                </form>
            </Panel>

            <Panel title={t('coupon.seller.list')}>
                <div className="mb-3 flex gap-2">
                    <button
                        type="button"
                        className="btn-outline-pill px-3 py-1 text-sm"
                        disabled={selected.length === 0}
                        onClick={() => router.post('/seller/coupons/end', { ids: selected }, { preserveScroll: true, onSuccess: () => setSelected([]) })}
                    >
                        {t('coupon.seller.end_selected', { count: selected.length })}
                    </button>
                </div>
                {coupons.length === 0 ? (
                    <p className="text-sm text-muted">{t('coupon.seller.empty')}</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-canvas text-xs text-muted">
                                <tr>
                                    <th className="px-3 py-2" />
                                    <th className="px-3 py-2 text-start">{t('coupon.col.code')}</th>
                                    <th className="px-3 py-2 text-start">{t('coupon.col.scope')}</th>
                                    <th className="px-3 py-2 text-start">{t('coupon.col.use_times')}</th>
                                    <th className="px-3 py-2 text-start">{t('coupon.col.expires')}</th>
                                    <th className="px-3 py-2 text-start">{t('coupon.col.status')}</th>
                                    <th className="px-3 py-2" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {coupons.map((c) => (
                                    <tr key={c.id}>
                                        <td className="px-3 py-2">
                                            <input type="checkbox" checked={selected.includes(c.id)} onChange={() => toggle(c.id)} aria-label={c.code ?? c.id} />
                                        </td>
                                        <td className="px-3 py-2">
                                            <span className="rounded bg-primary-soft px-2 py-0.5 text-xs font-bold text-primary">{t('coupon.seller.badge', { label: c.label })}</span>{' '}
                                            <span className="font-mono">{c.code}</span>
                                        </td>
                                        <td className="px-3 py-2">{c.scope ?? t('coupon.seller.all_store')}</td>
                                        <td className="px-3 py-2">
                                            {c.used} / {c.limit ?? '∞'}
                                            <span className="block text-xs text-muted">{t('coupon.seller.claimed', { count: c.claimed })}</span>
                                        </td>
                                        <td className="px-3 py-2">{c.validTo ? new Date(c.validTo).toLocaleDateString(locale) : '—'}</td>
                                        <td className="px-3 py-2">{c.live ? <span className="text-success">{t('coupon.seller.live')}</span> : <span className="text-muted">{t('coupon.seller.expired')}</span>}</td>
                                        <td className="px-3 py-2 text-end">
                                            {extending === c.id ? (
                                                <span className="inline-flex gap-1">
                                                    <input className="field py-1" type="date" value={extendTo} onChange={(e) => setExtendTo(e.target.value)} aria-label={t('coupon.seller.expires')} />
                                                    <button
                                                        type="button"
                                                        className="btn-primary px-2 py-1 text-xs"
                                                        disabled={extendTo === ''}
                                                        onClick={() => router.post('/seller/coupons/' + c.id + '/extend', { expires_at: extendTo }, { preserveScroll: true, onSuccess: () => setExtending(null) })}
                                                    >
                                                        {t('common.save')}
                                                    </button>
                                                </span>
                                            ) : (
                                                <button type="button" className="text-xs text-primary" onClick={() => { setExtending(c.id); setExtendTo(inMonth); }}>
                                                    {t('coupon.seller.extend')}
                                                </button>
                                            )}
                                        </td>
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
