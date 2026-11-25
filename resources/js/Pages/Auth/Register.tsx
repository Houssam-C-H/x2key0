import { Link, useForm } from '@inertiajs/react';
import { Field } from '@/Components/Field';
import { AuthLayout } from '@/Layouts/AuthLayout';
import { useLocalePath, useShared, useT } from '@/lib/i18n';

export default function Register({ referralCode }: { referralCode: string | null }) {
    const t = useT();
    const lp = useLocalePath();
    const { locale } = useShared();
    const form = useForm({
        username: '',
        email: '',
        password: '',
        password_confirmation: '',
        referral_code: referralCode ?? '',
        terms: false,
    });

    return (
        <AuthLayout
            title={t('auth.register.heading')}
            accent={t('auth.register.heading_accent')}
            subtitle={t('auth.register.subtitle')}
            panelTitle={t('auth.register.panel_title')}
            panelText={t('auth.register.panel_text')}
        >
            <form
                className="flex flex-col gap-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/register', { onFinish: () => form.reset('password', 'password_confirmation') });
                }}
            >
                <Field
                    label={t('auth.field.username')}
                    name="username"
                    autoComplete="username"
                    autoFocus
                    required
                    minLength={3}
                    maxLength={32}
                    pattern="[A-Za-z0-9_]+"
                    value={form.data.username}
                    onChange={(e) => form.setData('username', e.target.value)}
                    error={form.errors.username}
                    hint={t('auth.field.username_hint')}
                />
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
                    label={t('auth.field.password')}
                    name="password"
                    type="password"
                    autoComplete="new-password"
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
                <Field
                    label={t('auth.field.referral_code')}
                    name="referral_code"
                    value={form.data.referral_code}
                    onChange={(e) => form.setData('referral_code', e.target.value)}
                    error={form.errors.referral_code}
                />
                <label className="flex items-start gap-2 text-sm">
                    <input type="checkbox" className="mt-1" checked={form.data.terms} onChange={(e) => form.setData('terms', e.target.checked)} required />
                    <span>
                        {t('auth.register.terms_prefix')}{' '}
                        <a href={`/${locale}/page/terms`} className="text-primary hover:underline">
                            {t('footer.terms')}
                        </a>{' '}
                        &amp;{' '}
                        <a href={`/${locale}/page/privacy`} className="text-primary hover:underline">
                            {t('footer.privacy')}
                        </a>
                    </span>
                </label>
                {form.errors.terms && <span className="text-sm text-danger">{form.errors.terms}</span>}
                <button type="submit" className="btn-primary h-11" disabled={form.processing}>
                    {t('auth.register.submit')}
                </button>
            </form>
            <p className="mt-6 text-center text-sm text-muted">
                {t('auth.register.have_account')}{' '}
                <Link href={lp('/login')} className="font-semibold text-primary hover:underline">
                    {t('auth.login.title')}
                </Link>
            </p>
        </AuthLayout>
    );
}
