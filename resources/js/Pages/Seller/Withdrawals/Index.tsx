import { Link, router, useForm } from '@inertiajs/react';
import { useMemo } from 'react';
import { DashboardLayout, Panel } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';
import { formatUsd } from '@/lib/money';

interface Method {
    id: number;
    label: string;
    type: string;
    currency: string;
    isDefault: boolean;
    feeBps: number;
    feeFixed: number;
    min: number;
}

interface Props {
    balances: { pending: number; available: number; reserve: number; frozen: number; deposit: number; in_transit: number; currency: string };
    blockers: string[];
    methods: Method[];
    minimum: number;
    minimumFee: number;
    range: '3m' | 'all';
    records: {
        data: { id: string; number: string; account: string; amount: number; fee: number; payout: number; currency: string; status: string; remarks: string | null; requestedAt: string; paidAt: string | null; canCancel: boolean }[];
        page: number;
        lastPage: number;
    };
    idempotencyKey: string;
}

const STATUS_STYLE: Record<string, string> = {
    requested: 'bg-warning/15 text-warning',
    approved: 'bg-primary-soft text-primary',
    processing: 'bg-primary-soft text-primary',
    paid: 'bg-success/10 text-success',
    rejected: 'bg-danger/10 text-danger',
    failed: 'bg-danger/10 text-danger',
    cancelled: 'bg-canvas text-muted',
};

/** Withdrawal Management (spec 1.8): balances, 4-step withdraw (balance → account → amount → confirm), records. */
export default function Withdrawals({ balances, blockers, methods, minimum, minimumFee, range, records, idempotencyKey }: Props) {
    const t = useT();
    const { locale, errors } = useShared();
    const usd = (c: number) => formatUsd(c, locale) ?? '';
    const form = useForm({ method: String(methods.find((m) => m.isDefault)?.id ?? methods[0]?.id ?? ''), amount: '', idempotency_key: idempotencyKey });
    const method = methods.find((m) => String(m.id) === form.data.method);
    const cents = Math.floor(Number(form.data.amount || '0') * 100);
    const preview = useMemo(() => {
        if (!method || cents <= 0) return null;
        const fee = Math.max(minimumFee, Math.round((cents * method.feeBps) / 10000) + method.feeFixed);
        return { fee, payout: cents - fee, min: Math.max(minimum, method.min) };
    }, [method, cents, minimum, minimumFee]);
    const blocked = blockers.length > 0;

    return (
        <DashboardLayout title={t('menu.seller.withdrawals.records')} bare>
            <Panel title={t('payout.balance.title')}>
                <dl className="grid gap-4 text-sm sm:grid-cols-3 lg:grid-cols-6">
                    <Stat label={t('payout.balance.available')} value={usd(balances.available)} strong />
                    <Stat label={t('payout.balance.pending')} value={usd(balances.pending)} />
                    <Stat label={t('payout.balance.reserve')} value={usd(balances.reserve)} />
                    <Stat label={t('payout.balance.frozen')} value={usd(balances.frozen)} />
                    <Stat label={t('payout.balance.deposit')} value={usd(balances.deposit)} />
                    <Stat label={t('payout.balance.in_transit')} value={usd(balances.in_transit)} />
                </dl>
            </Panel>

            <Panel title={t('payout.withdraw.title')} description={t('payout.withdraw.steps')}>
                {blocked ? (
                    <ul className="space-y-2 text-sm">
                        {blockers.map((b) => (
                            <li key={b} className="flex flex-wrap items-center gap-2 rounded-md bg-warning/10 p-3">
                                <span className="flex-1">{t(`payout.blocked.${b}`)}</span>
                                {b === 'identity' && <Link href="/settings/certification" className="btn-outline-pill px-3 py-1">{t('payout.blocked.go')}</Link>}
                                {b === 'two_factor' && <Link href="/settings/security" className="btn-outline-pill px-3 py-1">{t('payout.blocked.go')}</Link>}
                            </li>
                        ))}
                    </ul>
                ) : methods.length === 0 ? (
                    <p className="text-sm">
                        {t('payout.withdraw.no_method')}{' '}
                        <Link href="/seller/withdrawals/methods" className="text-primary hover:underline">
                            {t('payout.method.add')}
                        </Link>
                    </p>
                ) : (
                    <form
                        className="grid gap-4 sm:grid-cols-[1fr_200px_auto] sm:items-end"
                        onSubmit={(e) => {
                            e.preventDefault();
                            if (preview && window.confirm(t('payout.withdraw.confirm', { amount: usd(cents), payout: usd(preview.payout), account: method?.label ?? '' }))) {
                                form.post('/seller/withdrawals', { preserveScroll: true });
                            }
                        }}
                    >
                        <label className="flex flex-col gap-1">
                            <span className="font-medium">{t('payout.withdraw.to')}</span>
                            <select className="field" value={form.data.method} onChange={(e) => form.setData('method', e.target.value)}>
                                {methods.map((m) => (
                                    <option key={m.id} value={m.id}>
                                        {m.label} · {t('payout.method.fee', { percent: m.feeBps / 100, fixed: usd(m.feeFixed) })}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="flex flex-col gap-1">
                            <span className="font-medium">{t('payout.withdraw.amount')}</span>
                            <input type="number" step="0.01" min="0" className="field" value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} />
                        </label>
                        <button type="submit" className="btn-primary" disabled={form.processing || !preview || cents < preview.min || cents > balances.available || preview.payout <= 0}>
                            {t('payout.withdraw.submit')}
                        </button>
                        <div className="text-sm text-muted sm:col-span-3">
                            {preview
                                ? t('payout.withdraw.preview', { fee: usd(preview.fee), payout: usd(preview.payout), min: usd(preview.min) })
                                : t('payout.withdraw.hint', { min: usd(minimum), available: usd(balances.available) })}
                        </div>
                        {(form.errors.amount ?? errors.amount) && <p className="text-sm text-danger sm:col-span-3">{form.errors.amount ?? errors.amount}</p>}
                    </form>
                )}
            </Panel>

            <Panel title={t('payout.records.title')}>
                <div className="mb-3 flex gap-2 text-sm">
                    {(['3m', 'all'] as const).map((r) => (
                        <Link key={r} href={r === 'all' ? '/seller/withdrawals?range=all' : '/seller/withdrawals'} className={`rounded-full px-3 py-1 ${range === r ? 'bg-primary text-white' : 'bg-canvas'}`}>
                            {t(`payout.records.range_${r}`)}
                        </Link>
                    ))}
                </div>
                {errors.withdrawal && <p className="mb-3 text-sm text-danger">{errors.withdrawal}</p>}
                {records.data.length === 0 ? (
                    <p className="py-8 text-center text-sm text-muted">{t('common.no_results')}</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="text-xs uppercase text-muted">
                                <tr>
                                    <th className="py-2 text-start">{t('payout.records.number')}</th>
                                    <th className="py-2 text-start">{t('payout.records.account')}</th>
                                    <th className="py-2 text-end">{t('payout.records.amount')}</th>
                                    <th className="py-2 text-end">{t('payout.records.payout')}</th>
                                    <th className="py-2 text-start">{t('payout.records.date')}</th>
                                    <th className="py-2 text-start">{t('payout.records.paid')}</th>
                                    <th className="py-2 text-start">{t('payout.records.status')}</th>
                                    <th className="py-2 text-start">{t('payout.records.remarks')}</th>
                                    <th className="py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {records.data.map((r) => (
                                    <tr key={r.id} className="border-t border-line">
                                        <td className="py-2 font-mono">{r.number}</td>
                                        <td className="py-2">{r.account}</td>
                                        <td className="py-2 text-end">{usd(r.amount)}</td>
                                        <td className="py-2 text-end">{usd(r.payout)}</td>
                                        <td className="py-2 text-muted">{new Date(r.requestedAt).toLocaleDateString(locale)}</td>
                                        <td className="py-2 text-muted">{r.paidAt ? new Date(r.paidAt).toLocaleDateString(locale) : '—'}</td>
                                        <td className="py-2">
                                            <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${STATUS_STYLE[r.status] ?? ''}`}>{t(`payout.status.${r.status}`)}</span>
                                        </td>
                                        <td className="py-2 text-muted">{r.remarks ?? ''}</td>
                                        <td className="py-2 text-end">
                                            {r.canCancel && (
                                                <button type="button" className="btn-ghost px-2 py-1 text-danger" onClick={() => router.post(`/seller/withdrawals/${r.id}/cancel`, {}, { preserveScroll: true })}>
                                                    {t('payout.records.cancel')}
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

function Stat({ label, value, strong = false }: { label: string; value: string; strong?: boolean }) {
    return (
        <div>
            <dt className="text-xs text-muted">{label}</dt>
            <dd className={strong ? 'text-xl font-bold text-primary' : 'font-semibold'}>{value}</dd>
        </div>
    );
}
