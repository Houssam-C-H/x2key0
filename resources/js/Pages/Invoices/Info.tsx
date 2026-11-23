import { useForm } from '@inertiajs/react';
import { Field } from '@/Components/Field';
import { DashboardLayout } from '@/Layouts/DashboardLayout';
import { useT } from '@/lib/i18n';

interface Profile {
    legal_name: string;
    tax_id: string;
    line1: string;
    line2: string;
    city: string;
    postal_code: string;
    country: string;
    email: string;
}

/** Invoice info (spec 5.16): legal details printed on new invoices (tax id stored encrypted). */
export default function InvoiceInfo({ profile }: { profile: Profile }) {
    const t = useT();
    const form = useForm<Profile>(profile);
    const field = (key: keyof Profile, required = true, extra: Record<string, unknown> = {}) => (
        <Field label={t(`invoice.field.${key}`)} name={key} required={required} value={form.data[key]} onChange={(e) => form.setData(key, key === 'country' ? e.target.value.toUpperCase() : e.target.value)} error={form.errors[key]} {...extra} />
    );

    return (
        <DashboardLayout title={t('menu.invoices.info')}>
            <p className="mb-4 text-sm text-muted">{t('invoice.info_help')}</p>
            <form
                className="grid gap-4 sm:grid-cols-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.put('/invoices/info', { preserveScroll: true });
                }}
            >
                {field('legal_name')}
                {field('tax_id', false)}
                {field('line1')}
                {field('line2', false)}
                {field('city')}
                {field('postal_code', false)}
                {field('country', true, { maxLength: 2, placeholder: 'MA' })}
                {field('email', true, { type: 'email' })}
                <div className="sm:col-span-2">
                    <button type="submit" className="btn-primary" disabled={form.processing}>
                        {t('common.save')}
                    </button>
                </div>
            </form>
        </DashboardLayout>
    );
}
