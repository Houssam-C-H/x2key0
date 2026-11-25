import { Link, useForm } from '@inertiajs/react';
import { Field, SelectField } from '@/Components/Field';
import { DashboardLayout, Panel } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';

interface Props {
    account: {
        username: string;
        name: string;
        email: string;
        publicId: string;
        locale: string;
        displayCurrency: string;
        country: string | null;
        timezone: string;
        level: string | null;
        memberSince: string;
        sellerPromoEmails: boolean;
    };
    messengers: { type: string; handle: string }[];
    timezones: string[];
}

const SOCIAL = ['google', 'facebook', 'steam', 'x', 'discord', 'tiktok', 'line'];

export default function Account({ account, messengers, timezones }: Props) {
    const t = useT();
    const { locales, currencies } = useShared();
    const form = useForm({
        name: account.name,
        locale: account.locale,
        display_currency: account.displayCurrency,
        country: account.country ?? '',
        timezone: account.timezone,
        seller_promo_emails: account.sellerPromoEmails,
        messengers: Object.fromEntries(messengers.map((m) => [m.type, m.handle])) as Record<string, string>,
    });

    return (
        <DashboardLayout title={t('menu.settings.account')} bare>
            <Panel title={t('settings.account.profile')}>
                <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Info label={t('auth.field.username')} value={`@${account.username}`} />
                    <Info label={t('account.id')} value={account.publicId} mono />
                    <Info label={t('settings.account.level')} value={account.level ?? '—'} />
                    <Info label={t('settings.account.member_since')} value={account.memberSince} />
                </dl>
            </Panel>

            <form
                className="space-y-6"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.put('/settings/account', { preserveScroll: true });
                }}
            >
                <Panel title={t('settings.account.details')}>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label={t('settings.account.nickname')} name="name" required value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} error={form.errors.name} />
                        <div className="flex flex-col gap-1">
                            <span className="font-medium">{t('auth.field.email')}</span>
                            <div className="field flex items-center justify-between bg-canvas">
                                <span className="truncate">{account.email}</span>
                                <Link href="/settings/security#email" className="text-sm text-primary hover:underline">
                                    {t('settings.change')}
                                </Link>
                            </div>
                        </div>
                        <SelectField
                            label={t('locale.language')}
                            name="locale"
                            value={form.data.locale}
                            onChange={(v) => form.setData('locale', v)}
                            options={locales.map((l) => ({ value: l.code, label: l.name }))}
                            error={form.errors.locale}
                        />
                        <SelectField
                            label={t('locale.currency')}
                            name="display_currency"
                            value={form.data.display_currency}
                            onChange={(v) => form.setData('display_currency', v)}
                            options={currencies.map((c) => ({ value: c, label: c }))}
                            error={form.errors.display_currency}
                        />
                        <Field
                            label={t('settings.account.country')}
                            name="country"
                            maxLength={2}
                            placeholder="MA"
                            value={form.data.country}
                            onChange={(e) => form.setData('country', e.target.value.toUpperCase())}
                            error={form.errors.country}
                            hint={t('settings.account.country_hint')}
                        />
                        <SelectField
                            label={t('settings.account.timezone')}
                            name="timezone"
                            value={form.data.timezone}
                            onChange={(v) => form.setData('timezone', v)}
                            options={timezones.map((tz) => ({ value: tz, label: tz }))}
                            error={form.errors.timezone}
                        />
                    </div>
                    <label className="mt-4 flex items-center gap-2">
                        <input type="checkbox" checked={form.data.seller_promo_emails} onChange={(e) => form.setData('seller_promo_emails', e.target.checked)} />
                        {t('settings.account.seller_promo_emails')}
                    </label>
                </Panel>

                <Panel title={t('settings.account.messengers')} description={t('settings.account.messengers_hint')}>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {messengers.map((m) => (
                            <Field
                                key={m.type}
                                label={t(`messenger.${m.type}`)}
                                name={`messengers.${m.type}`}
                                value={form.data.messengers[m.type] ?? ''}
                                onChange={(e) => form.setData('messengers', { ...form.data.messengers, [m.type]: e.target.value })}
                                error={form.errors[`messengers.${m.type}` as keyof typeof form.errors]}
                            />
                        ))}
                    </div>
                </Panel>

                <div className="flex justify-end">
                    <button type="submit" className="btn-primary" disabled={form.processing}>
                        {t('settings.save')}
                    </button>
                </div>
            </form>

            <Panel title={t('settings.account.data')} description={t('settings.account.data_hint')}>
                <div className="flex flex-wrap gap-3">
                    <a href="/settings/account/export" className="btn-outline-pill px-4 py-2 text-sm">
                        {t('settings.account.data_download')}
                    </a>
                    <a href="/settings/delete-account" className="px-4 py-2 text-sm text-danger hover:underline">
                        {t('menu.settings.delete')}
                    </a>
                </div>
            </Panel>

            <Panel title={t('settings.account.social')} description={t('settings.account.social_hint')}>
                <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    {SOCIAL.map((p) => (
                        <li key={p} className="flex items-center justify-between rounded-md border border-line px-3 py-2">
                            <span className="font-medium capitalize">{p === 'x' ? 'X' : p}</span>
                            <span className="text-xs text-muted">{t('settings.coming_soon')}</span>
                        </li>
                    ))}
                </ul>
            </Panel>
        </DashboardLayout>
    );
}

function Info({ label, value, mono = false }: { label: string; value: string; mono?: boolean }) {
    return (
        <div>
            <dt className="text-xs text-muted">{label}</dt>
            <dd className={`mt-0.5 font-semibold break-all ${mono ? 'font-mono text-xs' : ''}`}>{value}</dd>
        </div>
    );
}
