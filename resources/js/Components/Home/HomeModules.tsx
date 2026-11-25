import { Link } from '@inertiajs/react';
import { HistoryStrip } from '@/Components/Offer/HistoryStrip';
import { CommunityRatings, type CommunityRating } from '@/Components/Reviews/CommunityRatings';
import { categoryIcon, Icon } from '@/Components/Icon';
import { formatUsd } from '@/lib/money';
import { useShared, useT } from '@/lib/i18n';
import type { HistoryItem } from '@/types/offer';
import { GameArt, GameCard, type GameTileData, Rail, SectionHeading } from './HomeParts';

export interface HomeModule {
    code: string;
    title: string | null;
    data: Record<string, unknown>;
}

interface Article {
    slug: string;
    title: string;
    excerpt: string | null;
    cover: string | null;
    href: string;
    publishedAt: string | null;
}

/** Category of the games each trending module shows (icon on the generated art). */
const TRENDING_CATEGORY: Record<string, string> = {
    trending_topup: 'top_up',
    trending_giftcards: 'gift_cards',
    trending_videogames: 'video_games',
};

/** Hot Game card colours (brand guidelines §3, white text ≥ 4.5:1). */
const HOT_COLOR: Record<string, string> = {
    currency: 'var(--color-cat-currency)',
    items: 'var(--color-cat-items)',
    accounts: 'var(--color-cat-accounts)',
    gift_cards: 'var(--color-cat-gift)',
};

/** One home module by code (spec 1.3 #2–#11). The hero is drawn by the page, the footer by the layout. */
export function HomeModuleView({ module }: { module: HomeModule }) {
    const t = useT();
    const { locale, auth } = useShared();
    const money = (cents: number) => formatUsd(cents, locale) ?? '';
    const title = module.title ?? t('home.module.' + module.code);
    const d = module.data;

    switch (module.code) {
        case 'browsing_history': {
            const items = (d.items as HistoryItem[] | undefined) ?? [];
            return items.length === 0 ? null : (
                <section className="mx-auto max-w-7xl px-4 pt-10">
                    <HistoryStrip items={items} />
                </section>
            );
        }

        case 'hot_game': {
            const columns = ((d.columns as { type: string; label: string; href: string; games: { name: string; href: string; count: number }[] }[] | undefined) ?? []).filter((c) => c.games.length > 0);
            return columns.length === 0 ? null : (
                <section className="mx-auto max-w-7xl px-4 pt-14">
                    <SectionHeading title={title} subtitle={t('home.hot.subtitle')} />
                    <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {columns.map((c) => (
                            <li key={c.type} className="flex flex-col overflow-hidden rounded-2xl border border-line bg-white">
                                <Link href={c.href} className="flex items-center gap-3 px-5 py-4 text-white" style={{ backgroundColor: HOT_COLOR[c.type] ?? 'var(--color-primary)' }}>
                                    <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-white/20">
                                        <Icon name={categoryIcon[c.type] ?? 'box'} size={20} />
                                    </span>
                                    <span className="text-lg font-extrabold">{c.label}</span>
                                    <Icon name="arrow" size={18} className="ms-auto opacity-80 rtl:rotate-180" />
                                </Link>
                                <ol className="flex-1 divide-y divide-line">
                                    {c.games.map((g, i) => (
                                        <li key={g.href}>
                                            <Link href={g.href} className="flex items-center gap-3 px-5 py-3 text-sm hover:bg-canvas">
                                                <span className="w-4 text-xs font-bold text-muted">{i + 1}</span>
                                                <span className="flex-1 truncate font-medium">{g.name}</span>
                                                <span className="rounded-full bg-canvas px-2 py-0.5 text-xs text-muted">{t('home.offers', { count: g.count })}</span>
                                            </Link>
                                        </li>
                                    ))}
                                </ol>
                            </li>
                        ))}
                    </ul>
                </section>
            );
        }

        case 'trending_topup':
        case 'trending_giftcards':
        case 'trending_videogames': {
            const tiles = (d.tiles as GameTileData[] | undefined) ?? [];
            if (tiles.length === 0) {
                return null;
            }
            return (
                <section className="mx-auto max-w-7xl px-4 pt-14">
                    <SectionHeading title={title} subtitle={t('home.trending.subtitle')} more={d.more as string | null} />
                    <Rail label={title}>
                        {tiles.map((tile) => (
                            <li key={tile.href} className="w-[46%] shrink-0 snap-start sm:w-[31%] lg:w-[19%]">
                                <GameCard tile={tile} category={TRENDING_CATEGORY[module.code] ?? 'box'} money={money} />
                            </li>
                        ))}
                    </Rail>
                </section>
            );
        }

        case 'community_ratings':
            return (
                <div className="mt-14 bg-canvas">
                    <CommunityRatings items={(d.items as CommunityRating[] | undefined) ?? []} title={module.title ?? undefined} />
                </div>
            );

        case 'affiliate_banner':
            return (
                <section className="mx-auto grid max-w-7xl gap-4 px-4 pt-14 lg:grid-cols-2">
                    <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-primary to-brand-magenta p-8 text-white">
                        <Icon name="sparkle" size={120} className="absolute -end-6 -top-6 text-white/10" />
                        <p className="text-xs font-semibold uppercase tracking-widest text-white/80">{t('home.module.affiliate_banner')}</p>
                        <h2 className="mt-2 max-w-md font-display text-2xl font-extrabold">{module.title ?? t('home.affiliate.title', { rate: String(Number(d.rate ?? 0) / 100) })}</h2>
                        <p className="mt-2 max-w-md text-white/85">{t('home.affiliate.body')}</p>
                        <Link href={String(d.href ?? '/' + locale + '/affiliate')} className="mt-6 inline-flex items-center gap-2 rounded-full bg-white px-5 py-2.5 font-bold text-primary hover:bg-primary-soft">
                            {t('home.affiliate.cta')}
                            <Icon name="arrow" size={16} className="rtl:rotate-180" />
                        </Link>
                    </div>
                    <div className="relative overflow-hidden rounded-2xl bg-surface-dark p-8 text-white">
                        <Icon name="store" size={120} className="absolute -end-6 -top-6 text-white/5" />
                        <p className="text-xs font-semibold uppercase tracking-widest text-brand-lavender">{t('home.sell.kicker')}</p>
                        <h2 className="mt-2 max-w-md font-display text-2xl font-extrabold">{t('home.sell.title')}</h2>
                        <ul className="mt-3 space-y-1 text-sm text-white/80">
                            {(['one', 'two', 'three'] as const).map((k) => (
                                <li key={k} className="flex items-center gap-2">
                                    <Icon name="check" size={16} className="text-brand-lavender" />
                                    {t('home.sell.' + k)}
                                </li>
                            ))}
                        </ul>
                        <Link href={auth.user ? '/seller/onboarding' : '/' + locale + '/register'} className="mt-6 inline-flex items-center gap-2 rounded-full bg-primary px-5 py-2.5 font-bold text-white hover:bg-primary-hover">
                            {t('home.sell.cta')}
                            <Icon name="arrow" size={16} className="rtl:rotate-180" />
                        </Link>
                    </div>
                </section>
            );

        case 'new_game': {
            const links = ((d.columns as { game?: string; label: string; href: string }[][] | undefined) ?? []).flat().slice(0, 16);
            return links.length === 0 ? null : (
                <section className="mx-auto max-w-7xl px-4 pt-14">
                    <SectionHeading title={title} subtitle={t('home.new.subtitle')} />
                    <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        {links.map((l) => (
                            <li key={l.href}>
                                <Link href={l.href} className="group flex items-center gap-3 rounded-xl border border-line bg-white p-2 pe-3 transition hover:border-primary/40 hover:shadow-md">
                                    <span className="h-12 w-12 shrink-0 overflow-hidden rounded-lg">
                                        <GameArt name={l.game ?? l.label} compact />
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-sm font-semibold group-hover:text-primary">{l.game ?? l.label}</span>
                                        <span className="block truncate text-xs text-muted">{l.label}</span>
                                    </span>
                                    <span className="rounded-full bg-success/10 px-2 py-0.5 text-[10px] font-bold uppercase text-success">{t('home.new.badge')}</span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            );
        }

        case 'game_news': {
            const articles = (d.articles as Article[] | undefined) ?? [];
            return articles.length === 0 ? null : (
                <section className="mt-14 bg-surface-dark text-white">
                    <div className="mx-auto max-w-7xl px-4 py-14">
                        <SectionHeading title={title} subtitle={t('home.news.subtitle')} more={d.more as string | null} tone="dark" />
                        <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            {articles.map((a) => (
                                <li key={a.slug}>
                                    <Link href={a.href} className="group flex h-full flex-col overflow-hidden rounded-2xl bg-surface-dark-2 ring-1 ring-white/5 transition hover:ring-brand-lavender/40">
                                        <div className="aspect-[16/9] overflow-hidden">
                                            <GameArt name={a.title} image={a.cover} className="transition duration-300 group-hover:scale-105" />
                                        </div>
                                        <div className="flex flex-1 flex-col p-5">
                                            {a.publishedAt && <p className="text-xs font-semibold text-brand-lavender">{new Date(a.publishedAt).toLocaleDateString(locale, { day: 'numeric', month: 'short', year: 'numeric' })}</p>}
                                            <h3 className="mt-1 line-clamp-2 font-bold group-hover:text-brand-lavender">{a.title}</h3>
                                            {a.excerpt && <p className="mt-2 line-clamp-2 flex-1 text-sm text-white/70">{a.excerpt}</p>}
                                            <span className="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-white">
                                                {t('home.read_more')}
                                                <Icon name="arrow" size={14} className="rtl:rotate-180" />
                                            </span>
                                        </div>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </div>
                </section>
            );
        }

        case 'payment_strip': {
            const methods = (d.methods as { code: string; name: string; logo: string | null }[] | undefined) ?? [];
            return methods.length === 0 ? null : (
                <section className="border-t border-line bg-white" aria-label={title}>
                    <div className="mx-auto flex max-w-7xl flex-wrap items-center gap-x-6 gap-y-3 px-4 py-6">
                        <span className="flex items-center gap-2 text-sm font-semibold">
                            <Icon name="lock" size={18} className="text-success" />
                            {t('home.payments.title')}
                        </span>
                        <ul className="flex flex-wrap items-center gap-2">
                            {methods.map((m) => (
                                <li key={m.code} className="rounded-lg border border-line px-3 py-1.5 text-xs font-semibold text-muted">
                                    {m.logo ? <img src={m.logo} alt={m.name} className="h-5" loading="lazy" /> : m.name}
                                </li>
                            ))}
                        </ul>
                        <span className="text-xs text-muted sm:ms-auto">{t('home.payments.hint')}</span>
                    </div>
                </section>
            );
        }

        default:
            return null;
    }
}
