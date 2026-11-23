import { useForm } from '@inertiajs/react';
import { Field } from '@/Components/Field';
import { DashboardLayout } from '@/Layouts/DashboardLayout';
import { useT } from '@/lib/i18n';

/** "Enter Privacy Password" gate in front of Storage and other sensitive areas. */
export default function PrivacyVerify({ return: returnTo }: { return: string }) {
    const t = useT();
    const form = useForm({ privacy_password: '', return: returnTo });

    return (
        <DashboardLayout title={t('security.privacy.verify_title')}>
            <form
                className="flex max-w-sm flex-col gap-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/security/privacy-verify', { onFinish: () => form.reset('privacy_password') });
                }}
            >
                <p className="text-sm text-muted">{t('security.privacy.verify_hint')}</p>
                <Field
                    label={t('security.privacy.title')}
                    name="privacy_password"
                    type="password"
                    autoFocus
                    required
                    value={form.data.privacy_password}
                    onChange={(e) => form.setData('privacy_password', e.target.value)}
                    error={form.errors.privacy_password}
                />
                <button type="submit" className="btn-primary" disabled={form.processing}>
                    {t('security.privacy.verify_submit')}
                </button>
            </form>
        </DashboardLayout>
    );
}
