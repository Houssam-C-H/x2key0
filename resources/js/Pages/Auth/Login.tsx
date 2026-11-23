import { Link, useForm } from '@inertiajs/react';
import { Field } from '@/Components/Field';
import { AuthLayout } from '@/Layouts/AuthLayout';
import { useLocalePath, useT } from '@/lib/i18n';

export default function Login({ status }: { status?: string | null }) {
    const t = useT();
    const lp = useLocalePath();
    const form = useForm({ login: '', password: '', remember: false });

    return (
        <AuthLayout title={t('auth.login.title')} subtitle={t('auth.login.subtitle')}>
            {status && <p className="mb-4 rounded-md bg-success/10 p-3 text-sm text-success">{status}</p>}
            <form
                className="flex flex-col gap-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/login', { onFinish: () => form.reset('password') });
                }}
            >
                <Field
                    label={t('auth.field.login')}
                    name="login"
                    autoComplete="username"
                    autoFocus
                    required
                    value={form.data.login}
                    onChange={(e) => form.setData('login', e.target.value)}
                    error={form.errors.login}
                />
                <Field
                    label={t('auth.field.password')}
                    name="password"
                    type="password"
                    autoComplete="current-password"
                    required
                    value={form.data.password}
                    onChange={(e) => form.setData('password', e.target.value)}
                    error={form.errors.password}
                />
                <div className="flex items-center justify-between text-sm">
                    <label className="flex items-center gap-2">
                        <input type="checkbox" checked={form.data.remember} onChange={(e) => form.setData('remember', e.target.checked)} />
                        {t('auth.field.remember')}
                    </label>
                    <Link href={lp('/forgot-password')} className="text-primary hover:underline">
                        {t('auth.login.forgot')}
                    </Link>
                </div>
                <button type="submit" className="btn-primary h-11" disabled={form.processing}>
                    {t('auth.login.submit')}
                </button>
            </form>
            <p className="mt-6 text-center text-sm text-muted">
                {t('auth.login.no_account')}{' '}
                <Link href={lp('/register')} className="font-semibold text-primary hover:underline">
                    {t('auth.register.title')}
                </Link>
            </p>
        </AuthLayout>
    );
}
