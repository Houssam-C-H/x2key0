import { useForm } from '@inertiajs/react';
import { TextArea } from '@/Components/Field';
import { DashboardLayout, Panel } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';
import { formatUsd } from '@/lib/money';

interface Props {
    order: { publicId: string; number: string; status: string; title: string; quantity: number; kept: number; delivered: number; unitPrice: number; currency: string; paidCredits: boolean };
    role: 'buyer' | 'seller';
    reasons: { value: string; needsEvidence: boolean }[];
    resolutions: string[];
    maxFiles: number;
}

/** Open a dispute from an order (spec Part 10): reason, quantity, wanted resolution, description, evidence. */
export default function DisputeCreate({ order, role, reasons, resolutions, maxFiles }: Props) {
    const t = useT();
    const { locale, errors } = useShared();
    const form = useForm<{ reason: string; resolution: string; qty: number; description: string; to_credits: boolean; files: File[] }>({
        reason: reasons[0]?.value ?? '',
        resolution: resolutions[0] ?? 'refund_full',
        qty: order.kept,
        description: '',
        to_credits: false,
        files: [],
    });
    const needsEvidence = reasons.find((r) => r.value === form.data.reason)?.needsEvidence ?? false;
    const fieldError = (key: string) => (form.errors as Record<string, string | undefined>)[key];

    return (
        <DashboardLayout title={t('dispute.create.title', { number: order.number })} bare>
            <Panel title={order.title} description={t('dispute.create.intro')}>
                <dl className="grid gap-4 text-sm sm:grid-cols-4">
                    <div>
                        <dt className="text-xs text-muted">{t('dispute.col.order')}</dt>
                        <dd className="font-semibold">{order.number}</dd>
                    </div>
                    <div>
                        <dt className="text-xs text-muted">{t('dispute.create.units')}</dt>
                        <dd className="font-semibold">{t('dispute.create.units_value', { kept: order.kept, quantity: order.quantity })}</dd>
                    </div>
                    <div>
                        <dt className="text-xs text-muted">{t('dispute.create.unit_price')}</dt>
                        <dd className="font-semibold">{formatUsd(order.unitPrice, locale)}</dd>
                    </div>
                    <div>
                        <dt className="text-xs text-muted">{t('dispute.create.status')}</dt>
                        <dd className="font-semibold">{t(`order.status.${order.status}`)}</dd>
                    </div>
                </dl>
            </Panel>

            <form
                className="space-y-6"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(`/orders/${order.publicId}/disputes`, { forceFormData: true });
                }}
            >
                <Panel title={t('dispute.create.details')}>
                    {errors.dispute && <p className="mb-4 rounded-md bg-danger/5 p-3 text-sm text-danger">{errors.dispute}</p>}
                    <div className="grid gap-4 sm:grid-cols-3">
                        <label className="flex flex-col gap-1">
                            <span className="font-medium">{t('dispute.col.reason')}</span>
                            <select className="field" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)}>
                                {reasons.map((r) => (
                                    <option key={r.value} value={r.value}>
                                        {t(`dispute.reason.${r.value}`)}
                                    </option>
                                ))}
                            </select>
                            {form.errors.reason && <span className="text-sm text-danger">{form.errors.reason}</span>}
                        </label>
                        <label className="flex flex-col gap-1">
                            <span className="font-medium">{t('dispute.create.resolution')}</span>
                            <select className="field" value={form.data.resolution} onChange={(e) => form.setData('resolution', e.target.value)}>
                                {resolutions.map((r) => (
                                    <option key={r} value={r}>
                                        {t(`dispute.outcome.${r}`)}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="flex flex-col gap-1">
                            <span className="font-medium">{t('dispute.create.qty')}</span>
                            <input type="number" className="field" min={1} max={order.kept} value={form.data.qty} onChange={(e) => form.setData('qty', Number(e.target.value))} />
                            {form.errors.qty && <span className="text-sm text-danger">{form.errors.qty}</span>}
                        </label>
                    </div>
                    <div className="mt-4">
                        <TextArea label={t('dispute.create.description')} name="description" rows={5} value={form.data.description} onChange={(v) => form.setData('description', v)} error={form.errors.description} />
                    </div>
                    {role === 'buyer' && form.data.resolution.startsWith('refund') && (
                        <label className="mt-3 flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={form.data.to_credits} onChange={(e) => form.setData('to_credits', e.target.checked)} />
                            {t('order.refund_to_credits')}
                        </label>
                    )}
                </Panel>

                <Panel title={t('dispute.evidence.title')} description={t(needsEvidence ? 'dispute.evidence.required' : 'dispute.evidence.help', { count: maxFiles })}>
                    <input
                        type="file"
                        multiple
                        accept="image/*,application/pdf,video/mp4,video/quicktime,text/plain"
                        aria-label={t('dispute.evidence.title')}
                        onChange={(e) => form.setData('files', Array.from(e.target.files ?? []).slice(0, maxFiles))}
                    />
                    {(fieldError('files') ?? fieldError('files.0')) && <p className="mt-2 text-sm text-danger">{fieldError('files') ?? fieldError('files.0')}</p>}
                </Panel>

                <div className="flex justify-end">
                    <button type="submit" className="btn-primary" disabled={form.processing || (needsEvidence && form.data.files.length === 0)}>
                        {t('dispute.create.submit')}
                    </button>
                </div>
            </form>
        </DashboardLayout>
    );
}
