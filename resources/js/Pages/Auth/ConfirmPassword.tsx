import { useForm } from '@inertiajs/react';
import { Field } from '@/Components/Field';
import { AuthLayout } from '@/Layouts/AuthLayout';
import { useT } from '@/lib/i18n';

export default function ConfirmPassword() {
    const t = useT();
    const form = useForm({ password: '' });

    return (
        <AuthLayout title={t('auth.confirm.title')} subtitle={t('auth.confirm.subtitle')}>
            <form
                className="flex flex-col gap-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/user/confirm-password', { onFinish: () => form.reset() });
                }}
            >
                <Field
                    label={t('auth.field.password')}
                    name="password"
                    type="password"
                    autoComplete="current-password"
                    autoFocus
                    required
                    value={form.data.password}
                    onChange={(e) => form.setData('password', e.target.value)}
                    error={form.errors.password}
                />
                <button type="submit" className="btn-primary h-11" disabled={form.processing}>
                    {t('auth.confirm.submit')}
                </button>
            </form>
        </AuthLayout>
    );
}
