import { Link, useForm } from '@inertiajs/react';
import { Field, SelectField } from '@/Components/Field';
import { DashboardLayout } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';

interface Item {
    type: string;
    status: 'none' | 'pending' | 'verified' | 'rejected';
    milestone: string;
    available: boolean;
    blockedBy: string[];
    rejectedReason: string | null;
    verifiedAt: string | null;
}

const STATUS_STYLE: Record<Item['status'], string> = {
    none: 'bg-canvas text-muted',
    pending: 'bg-warning/15 text-warning',
    verified: 'bg-success/10 text-success',
    rejected: 'bg-danger/10 text-danger',
};

export default function Certification({ items }: { items: Item[] }) {
    const t = useT();

    return (
        <DashboardLayout title={t('menu.settings.certification')}>
            <p className="mb-6 text-sm text-muted">{t('certification.intro')}</p>
            <ol className="space-y-4">
                {items.map((item, i) => (
                    <li key={item.type} className="rounded-md border border-line p-4">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div className="flex items-center gap-3">
                                <span className="flex h-8 w-8 items-center justify-center rounded-full bg-primary-soft font-bold text-primary">{i + 1}</span>
                                <div>
                                    <p className="font-semibold">{t(`certification.type.${item.type}`)}</p>
                                    <p className="text-xs text-muted">{t(`certification.desc.${item.type}`)}</p>
                                </div>
                            </div>
                            <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${STATUS_STYLE[item.status]}`}>{t(`certification.status.${item.status}`)}</span>
                        </div>
                        <ItemAction item={item} />
                    </li>
                ))}
            </ol>
        </DashboardLayout>
    );
}

function ItemAction({ item }: { item: Item }) {
    const t = useT();

    if (item.status === 'verified') {
        return item.verifiedAt ? <p className="mt-3 text-xs text-muted">{t('certification.verified_on', { date: new Date(item.verifiedAt).toLocaleDateString() })}</p> : null;
    }

    if (!item.available) {
        return <p className="mt-3 text-sm text-muted">{t('placeholder.body', { milestone: item.milestone })}</p>;
    }

    if (item.blockedBy.length > 0) {
        return (
            <p className="mt-3 text-sm text-muted">
                {t('certification.requires', { type: item.blockedBy.map((b) => t(`certification.type.${b}`)).join(', ') })}
            </p>
        );
    }

    if (item.status === 'pending') {
        return <p className="mt-3 text-sm">{t('certification.pending_note')}</p>;
    }

    switch (item.type) {
        case 'email':
            return (
                <Link href="/email/verify" className="btn-outline-pill mt-3">
                    {t('certification.action.email')}
                </Link>
            );
        case 'phone':
            return (
                <Link href="/settings/security#phone" className="btn-outline-pill mt-3">
                    {t('certification.action.phone')}
                </Link>
            );
        case 'identity':
            return <IdentityForm rejectedReason={item.rejectedReason} />;
        case 'store':
            return (
                <Link href="/seller/onboarding" className="btn-outline-pill mt-3">
                    {t('certification.action.store')}
                </Link>
            );
        default:
            return null;
    }
}

function IdentityForm({ rejectedReason }: { rejectedReason: string | null }) {
    const t = useT();
    const { errors } = useShared();
    const form = useForm({ first_name: '', last_name: '', date_of_birth: '', country: '', document_type: 'passport' });

    return (
        <form
            className="mt-4 grid gap-4 sm:grid-cols-2"
            onSubmit={(e) => {
                e.preventDefault();
                form.post('/settings/certification/identity', { preserveScroll: true });
            }}
        >
            {rejectedReason && <p className="rounded-md bg-danger/5 p-3 text-sm text-danger sm:col-span-2">{t('certification.rejected_reason', { reason: rejectedReason })}</p>}
            {errors.identity && <p className="rounded-md bg-danger/5 p-3 text-sm text-danger sm:col-span-2">{errors.identity}</p>}
            <Field label={t('certification.field.first_name')} name="first_name" autoComplete="given-name" required value={form.data.first_name} onChange={(e) => form.setData('first_name', e.target.value)} error={form.errors.first_name} />
            <Field label={t('certification.field.last_name')} name="last_name" autoComplete="family-name" required value={form.data.last_name} onChange={(e) => form.setData('last_name', e.target.value)} error={form.errors.last_name} />
            <Field label={t('certification.field.date_of_birth')} name="date_of_birth" type="date" autoComplete="bday" required value={form.data.date_of_birth} onChange={(e) => form.setData('date_of_birth', e.target.value)} error={form.errors.date_of_birth} />
            <Field label={t('settings.account.country')} name="country" maxLength={2} required placeholder="MA" value={form.data.country} onChange={(e) => form.setData('country', e.target.value.toUpperCase())} error={form.errors.country} />
            <SelectField
                label={t('certification.field.document_type')}
                name="document_type"
                value={form.data.document_type}
                onChange={(v) => form.setData('document_type', v)}
                options={['passport', 'national_id', 'driving_licence'].map((d) => ({ value: d, label: t(`certification.document.${d}`) }))}
                error={form.errors.document_type}
            />
            <div className="flex items-end">
                <button type="submit" className="btn-primary" disabled={form.processing}>
                    {t('certification.action.identity')}
                </button>
            </div>
        </form>
    );
}
