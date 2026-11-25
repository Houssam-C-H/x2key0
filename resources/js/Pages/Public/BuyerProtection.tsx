import { Link } from '@inertiajs/react';
import { SeoHead } from '@/Components/Catalog/SeoHead';
import { SectionHeading } from '@/Components/Home/HomeParts';
import { Icon } from '@/Components/Icon';
import { PublicLayout } from '@/Layouts/PublicLayout';
import { useLocalePath, useShared, useT } from '@/lib/i18n';
import type { Seo } from '@/types/catalog';

interface Props {
    confirmationHours: number;
    serviceConfirmationHours: number;
    seo: Seo;
}

/** Buyer protection: money held until delivery, what is covered, how to open a claim. */
export default function BuyerProtection({ confirmationHours, serviceConfirmationHours, seo }: Props) {
    const t = useT();
    const lp = useLocalePath();
    const { auth } = useShared();
    const claimHref = auth.user ? '/buy/claim' : lp('/login');
    const days = (h: number) => Math.round(h / 24);

    const steps = [
        { icon: 'wallet', key: 'pay' },
        { icon: 'lock', key: 'held' },
        { icon: 'box', key: 'deliver' },
        { icon: 'check', key: 'confirm' },
    ];
    const covered = ['not_delivered', 'not_as_described', 'invalid', 'recovered'];

    return (
        <PublicLayout>
            <SeoHead seo={seo} />

            <section className="mx-auto max-w-7xl px-4 pt-6">
                <div
                    className="relative overflow-hidden rounded-2xl px-6 py-12 text-white sm:px-12"
                    style={{ backgroundImage: 'linear-gradient(100deg, var(--color-surface-dark) 0%, color-mix(in srgb, var(--color-brand-deep) 70%, var(--color-surface-dark)) 60%, var(--color-primary) 140%)' }}
                >
                    <Icon name="shield" size={220} className="pointer-events-none absolute -end-6 top-1/2 hidden -translate-y-1/2 text-white/10 md:block" />
                    <p className="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold">
                        <Icon name="shield" size={14} className="text-brand-glow" />
                        {t('protection.kicker')}
                    </p>
                    <h1 className="mt-4 max-w-2xl font-display text-3xl font-bold leading-tight sm:text-5xl">
                        {t('protection.hero_a')} <span className="text-brand-glow">{t('protection.hero_b')}</span>
                    </h1>
                    <p className="mt-4 max-w-xl text-white/80">{t('protection.lead', { days: days(confirmationHours) })}</p>
                    <div className="mt-6 flex flex-wrap gap-3">
                        <Link href={claimHref} className="rounded-xl bg-white px-5 py-3 font-semibold text-ink hover:bg-primary-soft">
                            {t('protection.claim_cta')}
                        </Link>
                        <Link href={lp('/help')} className="rounded-xl px-5 py-3 font-semibold text-white ring-1 ring-white/30 hover:bg-white/10">
                            {t('protection.help_cta')}
                        </Link>
                    </div>
                </div>
            </section>

            <section className="mx-auto max-w-7xl px-4 pt-14">
                <SectionHeading title={t('protection.how_title')} subtitle={t('protection.how_subtitle')} />
                <ol className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {steps.map((s, i) => (
                        <li key={s.key} className="relative rounded-2xl border border-line bg-white p-5">
                            <span className="absolute end-4 top-3 font-display text-4xl font-extrabold text-primary/10">{i + 1}</span>
                            <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary text-white">
                                <Icon name={s.icon} size={20} />
                            </span>
                            <h3 className="mt-3 font-bold">{t('protection.step.' + s.key)}</h3>
                            <p className="mt-1 text-sm text-muted">
                                {t('protection.step.' + s.key + '_text', { days: days(confirmationHours), service_days: days(serviceConfirmationHours) })}
                            </p>
                        </li>
                    ))}
                </ol>
            </section>

            <section className="mx-auto grid max-w-7xl gap-6 px-4 pt-14 lg:grid-cols-2">
                <div className="rounded-2xl border border-line bg-white p-6">
                    <h2 className="font-display text-xl font-bold">{t('protection.covered_title')}</h2>
                    <ul className="mt-4 space-y-3">
                        {covered.map((k) => (
                            <li key={k} className="flex gap-3">
                                <span className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-success/10 text-success">
                                    <Icon name="check" size={14} />
                                </span>
                                <span>
                                    <span className="block font-semibold">{t('protection.covered.' + k)}</span>
                                    <span className="block text-sm text-muted">{t('protection.covered.' + k + '_text')}</span>
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
                <div className="rounded-2xl border border-line bg-white p-6">
                    <h2 className="font-display text-xl font-bold">{t('protection.rules_title')}</h2>
                    <ul className="mt-4 list-disc space-y-2 ps-5 text-sm text-ink/80">
                        {['chat', 'evidence', 'early', 'outside'].map((k) => (
                            <li key={k}>{t('protection.rules.' + k, { days: days(confirmationHours) })}</li>
                        ))}
                    </ul>
                    <Link href={claimHref} className="btn-primary mt-6 inline-flex">
                        {t('protection.claim_cta')}
                    </Link>
                </div>
            </section>
        </PublicLayout>
    );
}
