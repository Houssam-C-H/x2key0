import { Link, useForm } from '@inertiajs/react';
import { Field } from '@/Components/Field';
import { AuthLayout } from '@/Layouts/AuthLayout';
import { useLocalePath, useT } from '@/lib/i18n';

export default function ForgotPassword({ status }: { status?: string | null }) {
    const t = useT();
    const lp = useLocalePath();
    const form = useForm({ email: '' });

    return (
        <AuthLayout title={t('auth.forgot.title')} subtitle={t('auth.forgot.subtitle')}>
            {status && <p className="mb-4 rounded-md bg-success/10 p-3 text-sm text-success">{status}</p>}
            <form
                className="flex flex-col gap-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/forgot-password');
                }}
            >
                <Field
                    label={t('auth.field.email')}
                    name="email"
                    type="email"
                    autoComplete="email"
                    autoFocus
                    required
                    value={form.data.email}
                    onChange={(e) => form.setData('email', e.target.value)}
                    error={form.errors.email}
                />
                <button type="submit" className="btn-primary h-11" disabled={form.processing}>
                    {t('auth.forgot.submit')}
                </button>
            </form>
            <p className="mt-6 text-center text-sm">
                <Link href={lp('/login')} className="text-primary hover:underline">
                    {t('auth.back_to_login')}
                </Link>
            </p>
        </AuthLayout>
    );
}
