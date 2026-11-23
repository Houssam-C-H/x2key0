import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Field } from '@/Components/Field';
import { AuthLayout } from '@/Layouts/AuthLayout';
import { useT } from '@/lib/i18n';

export default function TwoFactorChallenge() {
    const t = useT();
    const [recovery, setRecovery] = useState(false);
    const form = useForm({ code: '', recovery_code: '' });

    return (
        <AuthLayout title={t('auth.two_factor.title')} subtitle={recovery ? t('auth.two_factor.recovery_subtitle') : t('auth.two_factor.subtitle')}>
            <form
                className="flex flex-col gap-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/two-factor-challenge');
                }}
            >
                {recovery ? (
                    <Field
                        label={t('auth.two_factor.recovery_code')}
                        name="recovery_code"
                        autoComplete="one-time-code"
                        autoFocus
                        value={form.data.recovery_code}
                        onChange={(e) => form.setData('recovery_code', e.target.value)}
                        error={form.errors.recovery_code}
                    />
                ) : (
                    <Field
                        label={t('auth.two_factor.code')}
                        name="code"
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        autoFocus
                        maxLength={6}
                        value={form.data.code}
                        onChange={(e) => form.setData('code', e.target.value)}
                        error={form.errors.code}
                    />
                )}
                <button type="submit" className="btn-primary h-11" disabled={form.processing}>
                    {t('auth.two_factor.submit')}
                </button>
                <button
                    type="button"
                    className="text-sm text-primary hover:underline"
                    onClick={() => {
                        setRecovery((v) => !v);
                        form.reset();
                        form.clearErrors();
                    }}
                >
                    {recovery ? t('auth.two_factor.use_code') : t('auth.two_factor.use_recovery')}
                </button>
            </form>
        </AuthLayout>
    );
}
