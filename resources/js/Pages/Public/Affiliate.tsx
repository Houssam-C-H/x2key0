import { Link, router } from '@inertiajs/react';
import { SeoHead } from '@/Components/Catalog/SeoHead';
import { PublicLayout } from '@/Layouts/PublicLayout';
import { formatUsd } from '@/lib/money';
import { useShared, useT } from '@/lib/i18n';
import type { Seo } from '@/types/catalog';

interface Props {
    rates: { direct: number; level2: number; days: number; transferMin: number };
    enrolled: boolean;
    seo: Seo;
}

const pct = (bps: number) => (bps / 100).toFixed(bps % 100 === 0 ? 0 : 2);

/** Public affiliate landing (spec Part 15, Part 20 `/affiliate`): what you earn, 4 steps, FAQ, join. */
export default function AffiliateLanding({ rates, enrolled, seo }: Props) {
    const t = useT();
    const { auth, locale } = useShared();
    const faq = ['who', 'when', 'what', 'self'] as const;

    return (
        <PublicLayout>
            <SeoHead seo={seo} />
            <section className="bg-gradient-to-br from-primary to-surface-dark-2 text-white">
                <div className="mx-auto max-w-5xl space-y-4 px-4 py-14 text-center">
                    <h1 className="text-4xl font-extrabold">{t('affiliate.landing.headline', { rate: pct(rates.direct) })}</h1>
                    <p className="mx-auto max-w-2xl text-white/80">{t('affiliate.rule', { direct: pct(rates.direct), level2: pct(rates.level2), days: rates.days })}</p>
                    {enrolled ? (
                        <Link href="/affiliate/manage" className="btn-primary inline-block bg-white text-primary">
                            {t('affiliate.landing.open')}
                        </Link>
                    ) : auth.user ? (
                        <button type="button" className="btn-primary bg-white text-primary" onClick={() => router.post('/affiliate/enrol')}>
                            {t('affiliate.join.cta')}
                        </button>
                    ) : (
                        <Link href={'/' + locale + '/register'} className="btn-primary inline-block bg-white text-primary">
                            {t('affiliate.landing.register')}
                        </Link>
                    )}
                </div>
            </section>
            <section className="mx-auto max-w-5xl px-4 py-10">
                <ol className="grid gap-4 md:grid-cols-4">
                    {(['step1', 'step2', 'step3', 'step4'] as const).map((s, i) => (
                        <li key={s} className="card p-5">
                            <span className="text-3xl font-extrabold text-primary">{i + 1}</span>
                            <p className="mt-2 text-sm">{t('affiliate.landing.' + s)}</p>
                        </li>
                    ))}
                </ol>
                <h2 className="mt-10 text-xl font-bold">{t('affiliate.landing.faq')}</h2>
                <dl className="mt-3 space-y-3">
                    {faq.map((q) => (
                        <div key={q} className="card p-4">
                            <dt className="font-semibold">{t('affiliate.faq.' + q + '.q')}</dt>
                            <dd className="mt-1 text-sm text-muted">{t('affiliate.faq.' + q + '.a', { days: rates.days, amount: formatUsd(rates.transferMin, locale) ?? '' })}</dd>
                        </div>
                    ))}
                </dl>
            </section>
        </PublicLayout>
    );
}
