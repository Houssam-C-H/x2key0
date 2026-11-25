import { router, useForm } from '@inertiajs/react';
import { DashboardLayout, Panel } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';

type Factor = 'email' | 'privacy' | 'authenticator';

interface Props {
    scheduled: { at: string | null } | null;
    blocked: string[] | null;
    blockersNow: string[];
    factors: Record<Factor, boolean>;
    coolOffDays: number;
}

/**
 * Delete Account (spec 1.7 / 6.8): irreversible notice, agreement, two verification factors (e-mail code, Privacy
 * Password, authenticator), then a cool-off during which the request can be cancelled.
 */
export default function DeleteAccount({ scheduled, blocked, blockersNow, factors, coolOffDays }: Props) {
    const t = useT();
    const { locale, errors } = useShared();
    const form = useForm({ agree: false, reason: '', email: '', privacy: '', authenticator: '' });
    const filled = (['email', 'privacy', 'authenticator'] as const).filter((f) => form.data[f].trim() !== '').length;

    if (scheduled) {
        return (
            <DashboardLayout title={t('menu.settings.delete')} bare>
                <Panel title={t('account.delete.scheduled_title')} description={t('account.delete.scheduled_help', { date: scheduled.at ? new Date(scheduled.at).toLocaleDateString(locale) : '' })}>
                    <button type="button" className="btn-primary" onClick={() => router.post('/settings/delete-account/cancel')}>
                        {t('account.delete.cancel')}
                    </button>
                </Panel>
            </DashboardLayout>
        );
    }

    return (
        <DashboardLayout title={t('menu.settings.delete')} bare>
            <Panel title={t('account.delete.title')} description={t('account.delete.notice', { days: coolOffDays })}>
                {(blocked ?? blockersNow).length > 0 && (
                    <div role="alert" className="mb-4 rounded-md bg-danger/10 p-3 text-sm text-danger">
                        <p className="font-semibold">{t('account.delete.blockers_title')}</p>
                        <ul className="mt-1 list-disc ps-5">
                            {(blocked ?? blockersNow).map((b) => (
                                <li key={b}>{b}</li>
                            ))}
                        </ul>
                    </div>
                )}
                <form
                    className="space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/settings/delete-account', { preserveScroll: true });
                    }}
                >
                    <label className="grid gap-1 text-sm">
                        <span className="font-medium">{t('account.delete.reason')}</span>
                        <textarea className="field" maxLength={500} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                    </label>
                    <fieldset className="space-y-3">
                        <legend className="text-sm font-medium">{t('account.delete.factors')}</legend>
                        <div className="flex flex-wrap items-end gap-2">
                            <label className="grid gap-1 text-sm">
                                <span>{t('account.delete.factor.email')}</span>
                                <input className="field w-40" inputMode="numeric" autoComplete="one-time-code" maxLength={6} value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                            </label>
                            <button type="button" className="btn-outline-pill px-3 py-1 text-sm" onClick={() => router.post('/settings/delete-account/code', {}, { preserveScroll: true })}>
                                {t('account.delete.send_code')}
                            </button>
                        </div>
                        {factors.privacy && (
                            <label className="grid max-w-xs gap-1 text-sm">
                                <span>{t('account.delete.factor.privacy')}</span>
                                <input className="field" type="password" autoComplete="off" value={form.data.privacy} onChange={(e) => form.setData('privacy', e.target.value)} />
                            </label>
                        )}
                        {factors.authenticator && (
                            <label className="grid max-w-xs gap-1 text-sm">
                                <span>{t('account.delete.factor.authenticator')}</span>
                                <input className="field" inputMode="numeric" autoComplete="one-time-code" maxLength={6} value={form.data.authenticator} onChange={(e) => form.setData('authenticator', e.target.value)} />
                            </label>
                        )}
                        <p className="text-xs text-muted">{t('account.delete.factors_help')}</p>
                    </fieldset>
                    <label className="flex items-start gap-2 text-sm">
                        <input type="checkbox" checked={form.data.agree} onChange={(e) => form.setData('agree', e.target.checked)} />
                        <span>{t('account.delete.agree')}</span>
                    </label>
                    {(errors.factors || errors.agree) && <p className="text-sm text-danger">{errors.factors ?? errors.agree}</p>}
                    <button type="submit" className="btn-primary bg-danger" disabled={!form.data.agree || filled !== 2 || form.processing}>
                        {t('account.delete.confirm')}
                    </button>
                </form>
            </Panel>
        </DashboardLayout>
    );
}
