import { Link, useForm } from '@inertiajs/react';
import { SocialButtons } from '@/Components/Auth/SocialButtons';
import { Field } from '@/Components/Field';
import { AuthLayout } from '@/Layouts/AuthLayout';
import { useLocalePath, useT } from '@/lib/i18n';

export default function Login({ status, socialProviders = [] }: { status?: string | null; socialProviders?: string[] }) {
    const t = useT();
    const lp = useLocalePath();
    const form = useForm({ login: '', password: '', remember: false });

    return (
        <AuthLayout
            title={t('auth.login.heading')}
            accent={t('auth.login.heading_accent')}
            subtitle={t('auth.login.lead')}
            panelTitle={t('auth.login.panel_title')}
            panelText={t('auth.login.panel_text')}
        >
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
                    placeholder={t('auth.login.login_placeholder')}
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
                    placeholder={t('auth.login.password_placeholder')}
                    required
                    value={form.data.password}
                    onChange={(e) => form.setData('password', e.target.value)}
                    error={form.errors.password}
                />
                <div className="flex items-center justify-between text-sm">
                    <label className="flex items-center gap-2">
                        <input type="checkbox" className="accent-primary" checked={form.data.remember} onChange={(e) => form.setData('remember', e.target.checked)} />
                        {t('auth.field.remember')}
                    </label>
                    <Link href={lp('/forgot-password')} className="font-medium text-primary hover:underline">
                        {t('auth.login.forgot')}
                    </Link>
                </div>
                <button type="submit" className="btn-primary h-12 rounded-xl text-base" disabled={form.processing}>
                    {t('auth.login.submit')}
                </button>
            </form>
            <SocialButtons providers={socialProviders} />
            <p className="mt-6 text-center text-sm text-muted">
                {t('auth.login.no_account')}{' '}
                <Link href={lp('/register')} className="font-semibold text-primary hover:underline">
                    {t('auth.login.sign_up')}
                </Link>
            </p>
        </AuthLayout>
    );
}
