import { useForm } from '@inertiajs/react';
import { Field } from '@/Components/Field';
import { AuthLayout } from '@/Layouts/AuthLayout';
import { useT } from '@/lib/i18n';

export default function ResetPassword({ token, email }: { token: string; email: string }) {
    const t = useT();
    const form = useForm({ token, email, password: '', password_confirmation: '' });

    return (
        <AuthLayout title={t('auth.reset.title')}>
            <form
                className="flex flex-col gap-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') });
                }}
            >
                <Field
                    label={t('auth.field.email')}
                    name="email"
                    type="email"
                    autoComplete="email"
                    required
                    value={form.data.email}
                    onChange={(e) => form.setData('email', e.target.value)}
                    error={form.errors.email}
                />
                <Field
                    label={t('auth.field.new_password')}
                    name="password"
                    type="password"
                    autoComplete="new-password"
                    autoFocus
                    required
                    minLength={10}
                    value={form.data.password}
                    onChange={(e) => form.setData('password', e.target.value)}
                    error={form.errors.password}
                    hint={t('auth.field.password_hint')}
                />
                <Field
                    label={t('auth.field.password_confirmation')}
                    name="password_confirmation"
                    type="password"
                    autoComplete="new-password"
                    required
                    value={form.data.password_confirmation}
                    onChange={(e) => form.setData('password_confirmation', e.target.value)}
                />
                <button type="submit" className="btn-primary h-11" disabled={form.processing}>
                    {t('auth.reset.submit')}
                </button>
            </form>
        </AuthLayout>
    );
}
