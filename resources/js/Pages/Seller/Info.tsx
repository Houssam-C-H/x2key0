import { useForm } from '@inertiajs/react';
import { Field, TextArea } from '@/Components/Field';
import { LevelBadge } from '@/Components/Seller/LevelBadge';
import { DashboardLayout, Panel } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';
import type { PerLocale, SellerLevel, SellerService, SellerStatus } from '@/types/seller';

interface Props {
    store: {
        name: string;
        slug: string;
        publicUrl: string;
        bio: PerLocale;
        logoUrl: string | null;
        bannerUrl: string | null;
        status: SellerStatus;
        level: SellerLevel | null;
        payoutsEnabled: boolean;
        activatedAt: string | null;
    };
    services: SellerService[];
}

/** Seller Information (spec 1.8): shop name, avatar, banner, description + operating information. */
export default function Info({ store, services }: Props) {
    const t = useT();
    const { contentLocales: locales } = useShared();
    const form = useForm<{ store_name: string; bio: Record<string, string>; logo: File | null; banner: File | null }>({
        store_name: store.name,
        bio: Object.fromEntries(locales.map((l) => [l.code, store.bio[l.code] ?? ''])),
        logo: null,
        banner: null,
    });
    const fieldError = (key: string) => (form.errors as Record<string, string | undefined>)[key];

    return (
        <DashboardLayout title={t('menu.seller.info')} bare>
            <form
                className="space-y-6"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/seller/info', { preserveScroll: true, forceFormData: true, onSuccess: () => form.setData((d) => ({ ...d, logo: null, banner: null })) });
                }}
            >
                <Panel title={t('seller.info.store')}>
                    <div className="grid gap-6 md:grid-cols-2">
                        <Field
                            label={t('seller.info.name')}
                            name="store_name"
                            required
                            minLength={3}
                            maxLength={40}
                            value={form.data.store_name}
                            onChange={(e) => form.setData('store_name', e.target.value)}
                            error={form.errors.store_name}
                            hint={t('seller.info.name_hint')}
                        />
                        <div className="flex flex-col gap-1">
                            <span className="font-medium">{t('seller.info.public_url')}</span>
                            <a href={store.publicUrl} className="field truncate text-primary" target="_blank" rel="noreferrer">
                                {store.publicUrl}
                            </a>
                        </div>

                        <ImageInput label={t('seller.info.logo')} hint={t('seller.info.logo_hint')} name="logo" current={store.logoUrl} onChange={(f) => form.setData('logo', f)} error={form.errors.logo} round />
                        <ImageInput label={t('seller.info.banner')} hint={t('seller.info.banner_hint')} name="banner" current={store.bannerUrl} onChange={(f) => form.setData('banner', f)} error={form.errors.banner} />
                    </div>
                </Panel>

                <Panel title={t('seller.info.description')} description={t('seller.info.description_help')}>
                    <div className="grid gap-4 md:grid-cols-3">
                        {locales.map((l) => (
                            <TextArea
                                key={l.code}
                                label={l.name}
                                name={`bio-${l.code}`}
                                dir={l.dir}
                                rows={6}
                                value={form.data.bio[l.code] ?? ''}
                                onChange={(v) => form.setData('bio', { ...form.data.bio, [l.code]: v })}
                                error={fieldError(`bio.${l.code}`)}
                            />
                        ))}
                    </div>
                </Panel>

                <div className="flex justify-end">
                    <button type="submit" className="btn-primary" disabled={form.processing}>
                        {t('common.save')}
                    </button>
                </div>
            </form>

            <Panel title={t('seller.info.operation')}>
                <dl className="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt className="text-xs text-muted">{t('seller.info.sale_status')}</dt>
                        <dd className="font-semibold">{t(`seller.status.${store.status}`)}</dd>
                    </div>
                    <div>
                        <dt className="text-xs text-muted">{t('seller.info.level')}</dt>
                        <dd>{store.level ? <LevelBadge level={store.level} /> : '—'}</dd>
                    </div>
                    <div>
                        <dt className="text-xs text-muted">{t('seller.info.payouts')}</dt>
                        <dd className="font-semibold">{store.payoutsEnabled ? t('common.yes') : t('seller.info.payouts_off')}</dd>
                    </div>
                    <div>
                        <dt className="text-xs text-muted">{t('seller.info.activated')}</dt>
                        <dd className="font-semibold">{store.activatedAt ? new Date(store.activatedAt).toLocaleDateString() : '—'}</dd>
                    </div>
                </dl>
                <h3 className="mb-2 mt-6 text-sm font-bold">{t('seller.info.services')}</h3>
                {services.length === 0 ? (
                    <p className="text-sm text-muted">
                        {t('seller.info.no_services')}{' '}
                        <a href="/seller/services" className="text-primary hover:underline">
                            {t('seller.services.apply')}
                        </a>
                    </p>
                ) : (
                    <ul className="flex flex-wrap gap-2">
                        {services.map((s) => (
                            <li key={s.id}>
                                <a href={s.href} className="inline-block rounded-full bg-primary-soft px-3 py-1 text-xs font-semibold text-primary hover:underline">
                                    {s.title}
                                </a>
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>
        </DashboardLayout>
    );
}

function ImageInput({ label, hint, name, current, onChange, error, round = false }: { label: string; hint: string; name: string; current: string | null; onChange: (f: File | null) => void; error?: string; round?: boolean }) {
    return (
        <label htmlFor={`f-${name}`} className="flex flex-col gap-2">
            <span className="font-medium">{label}</span>
            {current && <img src={current} alt="" className={round ? 'h-20 w-20 rounded-full object-cover' : 'h-20 w-full rounded-md object-cover'} />}
            <input id={`f-${name}`} name={name} type="file" accept="image/png,image/jpeg,image/webp" onChange={(e) => onChange(e.target.files?.[0] ?? null)} />
            {error ? <span className="text-sm text-danger">{error}</span> : <span className="text-xs text-muted">{hint}</span>}
        </label>
    );
}
