import { Head, router } from '@inertiajs/react';
import { useShared, useT } from '@/lib/i18n';
import { formatUsd } from '@/lib/money';

interface Props {
    id: string;
    amount: number;
    currency: string;
    description: string;
    status: string;
    returnUrl: string;
}

/** Hosted page of the test PSP (dev/test only): choose what the "bank" answers. */
export default function FakePsp({ id, amount, description, status, returnUrl }: Props) {
    const t = useT();
    const { locale } = useShared();
    const done = status !== 'pending';
    const send = (outcome: 'pay' | 'fail' | 'cancel') => router.post(`/fake-psp/${id}/${outcome}`);

    return (
        <div className="flex min-h-screen items-center justify-center bg-surface-dark px-4">
            <Head title={t('fake_psp.title')} />
            <div className="w-full max-w-sm space-y-5 rounded-xl bg-white p-6 shadow-xl">
                <p className="rounded bg-warning/15 px-2 py-1 text-center text-xs font-bold uppercase text-warning">{t('fake_psp.banner')}</p>
                <div className="text-center">
                    <p className="text-sm text-muted">{description}</p>
                    <p className="text-3xl font-extrabold">{formatUsd(amount, locale)}</p>
                </div>
                {done ? (
                    <a href={returnUrl} className="btn-primary block w-full text-center">
                        {t('fake_psp.back')}
                    </a>
                ) : (
                    <div className="space-y-2">
                        <button type="button" className="btn-primary h-11 w-full" onClick={() => send('pay')}>
                            {t('fake_psp.pay')}
                        </button>
                        <button type="button" className="btn-outline-pill h-11 w-full text-danger" onClick={() => send('fail')}>
                            {t('fake_psp.fail')}
                        </button>
                        <button type="button" className="btn-ghost h-11 w-full" onClick={() => send('cancel')}>
                            {t('fake_psp.cancel')}
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}
