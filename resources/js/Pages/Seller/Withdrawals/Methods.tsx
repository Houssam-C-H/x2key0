import { router, useForm } from '@inertiajs/react';
import { Field } from '@/Components/Field';
import { DashboardLayout, Panel } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';
import { formatUsd } from '@/lib/money';

interface Props {
    methods: { id: number; label: string; type: string; currency: string; isDefault: boolean }[];
    catalog: { code: string; name: string; currencies: string[]; fields: string[]; feeBps: number; feeFixed: number; min: number }[];
    max: number;
}

/** Withdrawal Account (spec 1.8): saved payout methods (masked), default, remove, add new account. */
export default function PayoutMethods({ methods, catalog, max }: Props) {
    const t = useT();
    const { locale } = useShared();
    const form = useForm<{ type: string; currency: string; details: Record<string, string> }>({ type: catalog[0]?.code ?? '', currency: catalog[0]?.currencies[0] ?? 'USD', details: {} });
    const type = catalog.find((c) => c.code === form.data.type);
    const fieldError = (key: string) => (form.errors as Record<string, string | undefined>)[key];

    return (
        <DashboardLayout title={t('menu.seller.withdrawals.methods')} bare>
            <Panel title={t('payout.method.saved')}>
                {methods.length === 0 ? (
                    <p className="text-sm text-muted">{t('payout.method.none')}</p>
                ) : (
                    <ul className="divide-y divide-line">
                        {methods.map((m) => (
                            <li key={m.id} className="flex flex-wrap items-center gap-3 py-3 text-sm">
                                <span className="font-semibold">{m.label}</span>
                                <span className="text-muted">{m.currency}</span>
                                {m.isDefault && <span className="rounded-full bg-primary-soft px-2 py-0.5 text-xs text-primary">{t('payout.method.default')}</span>}
                                <span className="ms-auto flex gap-2">
                                    {!m.isDefault && (
                                        <button type="button" className="btn-ghost px-2 py-1" onClick={() => router.post(`/seller/withdrawals/methods/${m.id}/default`, {}, { preserveScroll: true })}>
                                            {t('payout.method.make_default')}
                                        </button>
                                    )}
                                    <button type="button" className="btn-ghost px-2 py-1 text-danger" onClick={() => window.confirm(t('payout.method.confirm_remove')) && router.delete(`/seller/withdrawals/methods/${m.id}`, { preserveScroll: true })}>
                                        {t('common.remove')}
                                    </button>
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>

            {methods.length < max && (
                <Panel title={t('payout.method.add')} description={t('payout.method.add_help')}>
                    <form
                        className="space-y-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/seller/withdrawals/methods', { preserveScroll: true, onSuccess: () => form.setData('details', {}) });
                        }}
                    >
                        <div className="grid gap-4 sm:grid-cols-2">
                            <label className="flex flex-col gap-1">
                                <span className="font-medium">{t('payout.method.type')}</span>
                                <select className="field" value={form.data.type} onChange={(e) => form.setData((d) => ({ ...d, type: e.target.value, details: {}, currency: catalog.find((c) => c.code === e.target.value)?.currencies[0] ?? 'USD' }))}>
                                    {catalog.map((c) => (
                                        <option key={c.code} value={c.code}>
                                            {c.name} · {t('payout.method.fee', { percent: c.feeBps / 100, fixed: formatUsd(c.feeFixed, locale) ?? '' })}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="flex flex-col gap-1">
                                <span className="font-medium">{t('payout.method.currency')}</span>
                                <select className="field" value={form.data.currency} onChange={(e) => form.setData('currency', e.target.value)}>
                                    {(type?.currencies ?? []).map((c) => (
                                        <option key={c} value={c}>
                                            {c}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            {(type?.fields ?? []).map((f) => (
                                <Field key={f} label={t(`payout.field.${f}`)} name={`detail-${f}`} required value={form.data.details[f] ?? ''} onChange={(e) => form.setData('details', { ...form.data.details, [f]: e.target.value })} error={fieldError(`details.${f}`)} />
                            ))}
                        </div>
                        {form.errors.details && <p className="text-sm text-danger">{form.errors.details}</p>}
                        {type && <p className="text-xs text-muted">{t('payout.method.minimum', { amount: formatUsd(type.min, locale) ?? '' })}</p>}
                        <button type="submit" className="btn-primary" disabled={form.processing}>
                            {t('payout.method.save')}
                        </button>
                    </form>
                </Panel>
            )}
        </DashboardLayout>
    );
}
