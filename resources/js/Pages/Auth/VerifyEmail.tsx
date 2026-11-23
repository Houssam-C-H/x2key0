import { Link, useForm } from '@inertiajs/react';
import { AuthLayout } from '@/Layouts/AuthLayout';
import { useT } from '@/lib/i18n';

export default function VerifyEmail({ status }: { status?: string | null }) {
    const t = useT();
    const form = useForm({});

    return (
        <AuthLayout title={t('auth.verify.title')} subtitle={t('auth.verify.subtitle')}>
            {status === 'verification-link-sent' && <p className="mb-4 rounded-md bg-success/10 p-3 text-sm text-success">{t('auth.verify.sent')}</p>}
            <div className="flex flex-col gap-3">
                <button type="button" className="btn-primary h-11" disabled={form.processing} onClick={() => form.post('/email/verification-notification')}>
                    {t('auth.verify.resend')}
                </button>
                <Link href="/dashboard" className="btn-ghost">
                    {t('auth.verify.later')}
                </Link>
            </div>
        </AuthLayout>
    );
}
