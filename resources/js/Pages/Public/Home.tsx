import { Head, Link, router } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import { LogoMark } from '@/Components/Brand/Logo';
import { SeoHead } from '@/Components/Catalog/SeoHead';
import { type HomeModule, HomeModuleView } from '@/Components/Home/HomeModules';
import { CATEGORY_TINT, GameArt, HowItWorks, SectionHeading, TrustBar } from '@/Components/Home/HomeParts';
import { categoryIcon, Icon } from '@/Components/Icon';
import { PublicLayout } from '@/Layouts/PublicLayout';
import { useLocalePath, useShared, useT } from '@/lib/i18n';
import type { Seo } from '@/types/catalog';

interface Hero {
    offers: number;
    sellers: number;
    games: number;
    popular: { name: string; href: string; icon?: string | null }[];
    types?: Record<string, number>;
}

/**
 * Home page (spec 1.3): banner hero with search, popular games and live figures; category cards; trust bar;
 * the CMS modules in their order, with "How it works" after Hot games.
 */
export default function Home({ modules = [], hero, seo }: { modules?: HomeModule[]; hero?: Hero; seo?: Seo }) {
    const t = useT();
    const { categoryTypes, locale } = useShared();
    const lp = useLocalePath();
    const tiles = categoryTypes.filter((c) => c.hero);
    const [q, setQ] = useState('');
    const showHero = modules.length === 0 || modules.some((m) => m.code === 'hero');
    const number = (n: number) => new Intl.NumberFormat(locale, { notation: n >= 10000 ? 'compact' : 'standard' }).format(n);

    return (
        <PublicLayout>
            {seo ? <SeoHead seo={seo} /> : <Head title={t('home.hero_tagline')} />}

            {showHero && (
                <section className="mx-auto max-w-7xl px-4 pt-6">
                    <div
                        className="relative overflow-hidden rounded-2xl text-white"
                        style={{ backgroundImage: 'linear-gradient(100deg, var(--color-surface-dark) 0%, color-mix(in srgb, var(--color-brand-deep) 70%, var(--color-surface-dark)) 55%, var(--color-primary) 130%)' }}
                    >
                        <div aria-hidden="true" className="pointer-events-none absolute -end-16 top-1/2 hidden -translate-y-1/2 lg:block">
                            <div className="relative">
                                <div className="absolute inset-0 rounded-full blur-3xl" style={{ background: 'radial-gradient(circle, color-mix(in srgb, var(--color-primary) 55%, transparent), transparent 70%)' }} />
                                <LogoMark size={420} tone="dark" className="relative opacity-[0.16]" />
                            </div>
                        </div>

                        <div className="relative max-w-2xl px-6 py-10 sm:px-12 sm:py-14">
                            <p className="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-white/90">
                                <Icon name="shield" size={14} className="text-brand-glow" />
                                {t('home.hero_tagline')}
                            </p>
                            <h1 className="mt-5 font-display text-4xl font-bold leading-[1.1] tracking-tight sm:text-5xl lg:text-6xl">
                                {t('home.hero_title_a')} <span className="text-brand-glow">{t('home.hero_title_b')}</span>
                            </h1>
                            <p className="mt-4 max-w-xl text-sm uppercase tracking-wide text-white/80 sm:text-base">{t('home.hero_subtitle')}</p>

                            <form
                                role="search"
                                className="mt-8 flex max-w-xl items-stretch overflow-hidden rounded-xl bg-white shadow-xl"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    router.get(lp('/search'), q.trim() === '' ? {} : { q: q.trim() });
                                }}
                            >
                                <Icon name="search" size={18} className="ms-4 shrink-0 self-center text-muted" />
                                <input
                                    type="search"
                                    value={q}
                                    onChange={(e) => setQ(e.target.value)}
                                    placeholder={t('home.search_placeholder')}
                                    aria-label={t('header.search')}
                                    className="min-w-0 flex-1 bg-transparent px-3 py-3.5 text-sm text-ink outline-none placeholder:text-muted"
                                />
                                <button type="submit" className="bg-primary px-6 text-sm font-semibold text-white hover:bg-primary-hover">
                                    {t('header.search')}
                                </button>
                            </form>

                            {hero && hero.popular.length > 0 && (
                                <div className="mt-4 flex flex-wrap items-center gap-2 text-xs">
                                    <span className="text-white/70">{t('home.popular')}</span>
                                    {hero.popular.map((p) => (
                                        <Link key={p.href} href={p.href} className="inline-flex items-center gap-1.5 rounded-full bg-white/10 py-1 pe-3 ps-1 text-white/90 hover:bg-white hover:text-ink">
                                            <span className="h-5 w-5 overflow-hidden rounded-full">
                                                <GameArt name={p.name} image={p.icon} compact />
                                            </span>
                                            {p.name}
                                        </Link>
                                    ))}
                                </div>
                            )}

                            {hero && (
                                <dl className="mt-8 flex flex-wrap gap-x-10 gap-y-3">
                                    {([['offers', hero.offers], ['sellers', hero.sellers], ['games', hero.games]] as const).map(([k, v]) => (
                                        <div key={k} className="flex flex-col-reverse">
                                            <dt className="text-xs text-white/70">{t('home.stats.' + k)}</dt>
                                            <dd className="font-display text-2xl font-bold">{number(v)}</dd>
                                        </div>
                                    ))}
                                </dl>
                            )}
                        </div>
                    </div>
                </section>
            )}

            {tiles.length > 0 && (
                <section className="mx-auto max-w-7xl px-4 pt-12">
                    <SectionHeading title={t('home.categories.title')} />
                    <ul className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
                        {tiles.map((c) => {
                            const tint = CATEGORY_TINT[c.code] ?? 'var(--color-primary)';
                            const count = hero?.types?.[c.code] ?? 0;
                            return (
                                <li key={c.code}>
                                    <Link
                                        href={lp(`/catalog/${c.code.replaceAll('_', '-')}`)}
                                        className="group flex h-full flex-col items-center gap-2 rounded-xl border border-transparent px-2 py-5 text-center transition hover:-translate-y-0.5 hover:border-primary hover:shadow-md"
                                        style={{ backgroundColor: `color-mix(in srgb, ${tint} 9%, white)` }}
                                    >
                                        <span className="flex h-12 w-12 items-center justify-center rounded-xl bg-white shadow-sm transition group-hover:scale-105" style={{ color: tint }}>
                                            <Icon name={categoryIcon[c.code] ?? 'box'} size={24} />
                                        </span>
                                        <span className="text-sm font-semibold leading-tight">{c.label}</span>
                                        <span className="rounded-full bg-white px-2.5 py-0.5 text-[11px] text-muted shadow-sm">
                                            <span className="font-semibold text-primary">{number(count)}</span> {t('home.categories.offers')}
                                        </span>
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </section>
            )}

            <div className="pt-12">
                <TrustBar />
            </div>

            <div className="-mb-16">
                {modules
                    .filter((m) => m.code !== 'hero')
                    .map((m) => (
                        <Fragment key={m.code}>
                            <HomeModuleView module={m} />
                            {m.code === 'hot_game' && <HowItWorks />}
                        </Fragment>
                    ))}
            </div>
        </PublicLayout>
    );
}
