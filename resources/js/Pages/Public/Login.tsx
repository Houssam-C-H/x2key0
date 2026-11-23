import { Head } from '@inertiajs/react';
import { PublicLayout } from '@/Layouts/PublicLayout';
import { useT } from '@/lib/i18n';

/** Placeholder until Milestone 2 (Identity). */
export default function Login() {
    const t = useT();

    return (
        <PublicLayout>
            <Head title={t('login.title')} />
            <div className="mx-auto max-w-md px-4 py-16">
                <div className="card p-6">
                    <h1 className="text-xl font-bold">{t('login.title')}</h1>
                    <p className="mt-2 text-muted">{t('login.coming')}</p>
                </div>
            </div>
        </PublicLayout>
    );
}
