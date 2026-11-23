import { Link, useForm } from '@inertiajs/react';
import { Field, TextArea } from '@/Components/Field';
import { bpsToPercent, LevelBadge } from '@/Components/Seller/LevelBadge';
import { DashboardLayout, Panel } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';
import type { PerLocale, SellerLevel, SellerStatus } from '@/types/seller';

interface Props {
    store: {
        name: string;
        publicUrl: string;
        status: SellerStatus;
        statusReason: string | null;
        vacationUntil: string | null;
        level: SellerLevel | null;
        notice: PerLocale;
        autoReply: { first_message: string | null; off_hours_message: string | null; hours_start: string | null; hours_end: string | null };
        languages: { code: string; level: string }[];
    };
    languageOptions: { code: string; name: string }[];
    languageLevels: string[];
    featuredMax: number;
}

const STATUS_STYLE: Record<SellerStatus, string> = {
    inactive: 'bg-canvas text-muted',
    active: 'bg-success/10 text-success',
    vacation: 'bg-warning/15 text-warning',
    suspended: 'bg-danger/10 text-danger',
    banned: 'bg-danger/10 text-danger',
};

/** Store Management › My Store: status, vacation, notice, auto-reply, languages. */
export default function Store({ store, languageOptions, languageLevels, featuredMax }: Props) {
    const t = useT();
    const { locales, errors } = useShared();
    const suspended = store.status === 'suspended';
    const form = useForm({
        vacation: store.status === 'vacation',
        vacation_until: store.vacationUntil ?? '',
        store_notice: Object.fromEntries(locales.map((l) => [l.code, store.notice[l.code] ?? ''])) as Record<string, string>,
        auto_reply: {
            first_message: store.autoReply.first_message ?? '',
            off_hours_message: store.autoReply.off_hours_message ?? '',
            hours_start: store.autoReply.hours_start ?? '',
            hours_end: store.autoReply.hours_end ?? '',
        },
        languages: store.languages,
    });
    const fieldError = (key: string) => (form.errors as Record<string, string | undefined>)[key];

    const setAutoReply = (key: keyof typeof form.data.auto_reply, value: string) => form.setData('auto_reply', { ...form.data.auto_reply, [key]: value });

    return (
        <DashboardLayout title={t('menu.seller.store.mine')} bare>
            <Panel title={store.name}>
                <div className="flex flex-wrap items-center gap-3">
                    <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${STATUS_STYLE[store.status]}`}>{t(`seller.status.${store.status}`)}</span>
                    {store.level && <LevelBadge level={store.level} />}
                    <a href={store.publicUrl} className="btn-outline-pill ms-auto" target="_blank" rel="noreferrer">
                        {t('seller.store.view_public')}
                    </a>
                    <Link href="/seller/info" className="btn-ghost">
                        {t('menu.seller.info')}
                    </Link>
                </div>
                {suspended && <p className="mt-4 rounded-md bg-danger/5 p-3 text-sm text-danger">{t('seller.store.suspended_note', { reason: store.statusReason ?? '—' })}</p>}
                {store.level && (
                    <dl className="mt-4 grid gap-4 text-sm sm:grid-cols-4">
                        <Stat label={t('seller.levels.commission')} value={bpsToPercent(store.level.commissionBps)} />
                        <Stat label={t('seller.levels.hold')} value={t('seller.levels.days', { count: store.level.holdDays })} />
                        <Stat label={t('seller.levels.reserve')} value={bpsToPercent(store.level.reserveBps)} />
                        <Stat label={t('seller.levels.max_offers')} value={String(store.level.maxActiveOffers)} />
                    </dl>
                )}
            </Panel>

            <form
                className="space-y-6"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.put('/seller/store', { preserveScroll: true });
                }}
            >
                {errors.store && <p className="rounded-md bg-danger/5 p-3 text-sm text-danger">{errors.store}</p>}

                <Panel title={t('seller.store.vacation')} description={t('seller.store.vacation_help')}>
                    <label className="flex items-center gap-2">
                        <input type="checkbox" checked={form.data.vacation} disabled={suspended} onChange={(e) => form.setData('vacation', e.target.checked)} />
                        <span>{t('seller.store.vacation_on')}</span>
                    </label>
                    {form.data.vacation && (
                        <Field
                            className="mt-3 max-w-xs"
                            label={t('seller.store.vacation_until')}
                            name="vacation_until"
                            type="date"
                            value={form.data.vacation_until}
                            onChange={(e) => form.setData('vacation_until', e.target.value)}
                            error={form.errors.vacation_until}
                            hint={t('seller.store.vacation_until_hint')}
                        />
                    )}
                </Panel>

                <Panel title={t('seller.store.notice')} description={t('seller.store.notice_help')}>
                    <div className="grid gap-4 md:grid-cols-3">
                        {locales.map((l) => (
                            <TextArea
                                key={l.code}
                                label={l.name}
                                name={`notice-${l.code}`}
                                dir={l.dir}
                                value={form.data.store_notice[l.code] ?? ''}
                                onChange={(v) => form.setData('store_notice', { ...form.data.store_notice, [l.code]: v })}
                                error={fieldError(`store_notice.${l.code}`)}
                            />
                        ))}
                    </div>
                </Panel>

                <Panel title={t('seller.store.auto_reply')} description={t('seller.store.auto_reply_help')}>
                    <div className="grid gap-4 md:grid-cols-2">
                        <TextArea label={t('seller.store.first_message')} name="first_message" value={form.data.auto_reply.first_message} onChange={(v) => setAutoReply('first_message', v)} error={fieldError('auto_reply.first_message')} />
                        <TextArea label={t('seller.store.off_hours_message')} name="off_hours_message" value={form.data.auto_reply.off_hours_message} onChange={(v) => setAutoReply('off_hours_message', v)} error={fieldError('auto_reply.off_hours_message')} />
                        <Field label={t('seller.store.hours_start')} name="hours_start" type="time" value={form.data.auto_reply.hours_start} onChange={(e) => setAutoReply('hours_start', e.target.value)} error={fieldError('auto_reply.hours_start')} />
                        <Field label={t('seller.store.hours_end')} name="hours_end" type="time" value={form.data.auto_reply.hours_end} onChange={(e) => setAutoReply('hours_end', e.target.value)} error={fieldError('auto_reply.hours_end')} />
                    </div>
                </Panel>

                <Panel title={t('seller.store.languages')} description={t('seller.store.languages_help')}>
                    <ul className="space-y-2">
                        {form.data.languages.map((lang, i) => (
                            <li key={i} className="flex flex-wrap items-center gap-2">
                                <select
                                    aria-label={t('seller.store.language')}
                                    className="field max-w-[12rem]"
                                    value={lang.code}
                                    onChange={(e) => form.setData('languages', form.data.languages.map((l, j) => (j === i ? { ...l, code: e.target.value } : l)))}
                                >
                                    {languageOptions.map((o) => (
                                        <option key={o.code} value={o.code}>
                                            {o.name}
                                        </option>
                                    ))}
                                </select>
                                <select
                                    aria-label={t('seller.store.language_level')}
                                    className="field max-w-[10rem]"
                                    value={lang.level}
                                    onChange={(e) => form.setData('languages', form.data.languages.map((l, j) => (j === i ? { ...l, level: e.target.value } : l)))}
                                >
                                    {languageLevels.map((lv) => (
                                        <option key={lv} value={lv}>
                                            {t(`seller.language_level.${lv}`)}
                                        </option>
                                    ))}
                                </select>
                                <button type="button" className="btn-ghost text-danger" onClick={() => form.setData('languages', form.data.languages.filter((_, j) => j !== i))}>
                                    {t('common.remove')}
                                </button>
                                {fieldError(`languages.${i}.code`) && <span className="text-sm text-danger">{fieldError(`languages.${i}.code`)}</span>}
                            </li>
                        ))}
                    </ul>
                    {form.data.languages.length < 8 && (
                        <button
                            type="button"
                            className="btn-outline-pill mt-3"
                            onClick={() => {
                                const used = new Set(form.data.languages.map((l) => l.code));
                                const next = languageOptions.find((o) => !used.has(o.code));
                                if (next) form.setData('languages', [...form.data.languages, { code: next.code, level: languageLevels[0] ?? 'native' }]);
                            }}
                        >
                            {t('seller.store.add_language')}
                        </button>
                    )}
                </Panel>

                <Panel title={t('seller.store.featured')}>
                    <p className="text-sm text-muted">{t('seller.store.featured_soon', { count: featuredMax })}</p>
                </Panel>

                <div className="flex justify-end">
                    <button type="submit" className="btn-primary" disabled={form.processing || suspended}>
                        {t('common.save')}
                    </button>
                </div>
            </form>
        </DashboardLayout>
    );
}

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="text-xs text-muted">{label}</dt>
            <dd className="font-semibold">{value}</dd>
        </div>
    );
}
