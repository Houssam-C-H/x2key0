import { Head, Link, router } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import { SeoHead } from '@/Components/Catalog/SeoHead';
import { type HomeModule, HomeModuleView } from '@/Components/Home/HomeModules';
import { HowItWorks, TrustBar } from '@/Components/Home/HomeParts';
import { categoryIcon, Icon } from '@/Components/Icon';
import { PublicLayout } from '@/Layouts/PublicLayout';
import { useLocalePath, useShared, useT } from '@/lib/i18n';
import type { Seo } from '@/types/catalog';

interface Hero {
    offers: number;
    sellers: number;
    games: number;
    popular: { name: string; href: string }[];
}

/**
 * Home page (spec 1.3): hero with search, popular games and live figures; trust bar; the CMS modules in their order,
 * with "How it works" after Hot games.
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
                <section className="relative overflow-hidden bg-surface-dark text-white">
                    <div
                        aria-hidden="true"
                        className="pointer-events-none absolute inset-0"
                        style={{
                            background:
                                'radial-gradient(50% 70% at 12% 10%, rgb(91 61 245 / .45), transparent 65%), radial-gradient(45% 60% at 92% 20%, rgb(219 39 119 / .30), transparent 65%), radial-gradient(60% 50% at 50% 120%, rgb(91 61 245 / .25), transparent 70%)',
                        }}
                    />
                    <div aria-hidden="true" className="pointer-events-none absolute inset-0 opacity-[0.07]" style={{ backgroundImage: 'linear-gradient(rgb(255 255 255) 1px, transparent 1px), linear-gradient(90deg, rgb(255 255 255) 1px, transparent 1px)', backgroundSize: '48px 48px', maskImage: 'linear-gradient(to bottom, black, transparent 85%)' }} />

                    <div className="relative mx-auto max-w-7xl px-4 pb-14 pt-14 sm:pt-20">
                        <div className="mx-auto max-w-3xl text-center">
                            <p className="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs font-semibold text-white/80 backdrop-blur">
                                <Icon name="shield" size={14} className="text-brand-lavender" />
                                {t('home.hero_tagline')}
                            </p>
                            <h1 className="mt-5 font-display text-4xl font-extrabold leading-[1.05] tracking-tight sm:text-6xl">
                                {t('home.hero_title_a')}{' '}
                                <span className="bg-gradient-to-r from-brand-lavender to-brand-magenta bg-clip-text text-transparent">{t('home.hero_title_b')}</span>
                            </h1>
                            <p className="mx-auto mt-4 max-w-2xl text-base text-white/75 sm:text-lg">{t('home.hero_subtitle')}</p>

                            <form
                                role="search"
                                className="mx-auto mt-8 flex max-w-2xl items-center gap-2 rounded-2xl bg-white p-2 shadow-2xl shadow-primary/30"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    router.get(lp('/search'), q.trim() === '' ? {} : { q: q.trim() });
                                }}
                            >
                                <Icon name="search" size={20} className="ms-2 shrink-0 text-muted" />
                                <input
                                    type="search"
                                    value={q}
                                    onChange={(e) => setQ(e.target.value)}
                                    placeholder={t('home.search_placeholder')}
                                    aria-label={t('header.search')}
                                    className="min-w-0 flex-1 bg-transparent px-1 py-2.5 text-base text-ink outline-none placeholder:text-muted"
                                />
                                <button type="submit" className="btn-primary rounded-xl px-5 py-2.5">
                                    {t('header.search')}
                                </button>
                            </form>

                            {hero && hero.popular.length > 0 && (
                                <div className="mt-4 flex flex-wrap items-center justify-center gap-2 text-sm">
                                    <span className="text-white/60">{t('home.popular')}</span>
                                    {hero.popular.map((p) => (
                                        <Link key={p.href} href={p.href} className="rounded-full border border-white/15 bg-white/5 px-3 py-1 text-white/90 hover:border-brand-lavender hover:text-white">
                                            {p.name}
                                        </Link>
                                    ))}
                                </div>
                            )}
                        </div>

                        <ul className="mx-auto mt-12 grid max-w-5xl grid-cols-4 gap-2 sm:gap-3 md:grid-cols-8">
                            {tiles.map((c) => (
                                <li key={c.code}>
                                    <Link
                                        href={lp(`/catalog/${c.code.replaceAll('_', '-')}`)}
                                        className="group flex flex-col items-center gap-2 rounded-2xl border border-white/10 bg-white/[0.04] px-2 py-4 text-center text-xs font-semibold text-white/90 backdrop-blur transition hover:-translate-y-0.5 hover:border-brand-lavender/60 hover:bg-white/10 sm:text-sm"
                                    >
                                        <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-primary to-brand-magenta text-white shadow-lg shadow-primary/30 transition group-hover:scale-105">
                                            <Icon name={categoryIcon[c.code] ?? 'box'} size={22} />
                                        </span>
                                        <span className="leading-tight">{c.label}</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>

                        {hero && (
                            <dl className="mx-auto mt-10 grid max-w-3xl grid-cols-3 divide-x divide-white/10 rounded-2xl border border-white/10 bg-white/[0.04] py-4 text-center rtl:divide-x-reverse">
                                {([['offers', hero.offers], ['sellers', hero.sellers], ['games', hero.games]] as const).map(([k, v]) => (
                                    <div key={k}>
                                        <dt className="text-xs text-white/60">{t('home.stats.' + k)}</dt>
                                        <dd className="font-display text-2xl font-extrabold">{number(v)}</dd>
                                    </div>
                                ))}
                            </dl>
                        )}
                    </div>
                </section>
            )}

            <TrustBar />

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
