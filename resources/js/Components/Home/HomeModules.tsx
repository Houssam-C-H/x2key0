import { Link } from '@inertiajs/react';
import { HistoryStrip } from '@/Components/Offer/HistoryStrip';
import { CommunityRatings, type CommunityRating } from '@/Components/Reviews/CommunityRatings';
import { useShared, useT } from '@/lib/i18n';
import type { HistoryItem } from '@/types/offer';

export interface HomeModule {
    code: string;
    title: string | null;
    data: Record<string, unknown>;
}

interface Tile {
    name: string;
    label: string;
    href: string;
    count: number;
    image: string | null;
}

interface Article {
    slug: string;
    title: string;
    excerpt: string | null;
    cover: string | null;
    href: string;
    publishedAt: string | null;
}

function Heading({ title, more }: { title: string; more?: string | null }) {
    const t = useT();

    return (
        <div className="mb-5 flex items-end justify-between gap-4">
            <h2 className="font-display text-2xl font-extrabold">{title}</h2>
            {more && (
                <Link href={more} className="btn-outline-pill shrink-0 px-3 py-1 text-sm">
                    {t('home.discover_all')} ›
                </Link>
            )}
        </div>
    );
}

/** Hot Game card colours (spec 1.3 #3), from the category palette. */
const HOT_COLOR: Record<string, string> = {
    currency: 'var(--color-cat-currency)',
    items: 'var(--color-cat-items)',
    accounts: 'var(--color-cat-accounts)',
    gift_cards: 'var(--color-cat-gift)',
};

function TileCard({ tile, large = false }: { tile: Tile; large?: boolean }) {
    const t = useT();

    return (
        <Link href={tile.href} className={'group relative block overflow-hidden rounded-lg bg-gradient-to-br from-primary/80 to-surface-dark-2 ' + (large ? 'aspect-[4/5] sm:row-span-2' : 'aspect-[4/3]')}>
            {tile.image && <img src={tile.image} alt="" loading="lazy" className="absolute inset-0 h-full w-full object-cover transition group-hover:scale-105" />}
            <span className="absolute end-2 top-2 rounded-full bg-black/60 px-2 py-0.5 text-[11px] font-semibold text-white">{t('home.offers', { count: tile.count })}</span>
            <span className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/80 to-transparent p-3 text-sm font-bold text-white">{tile.label}</span>
        </Link>
    );
}

/** One home module by code (spec 1.3 #2–#11). Hero and footer are rendered by the page and the layout. */
export function HomeModuleView({ module }: { module: HomeModule }) {
    const t = useT();
    const { locale } = useShared();
    const title = module.title ?? t('home.module.' + module.code);
    const d = module.data;

    switch (module.code) {
        case 'browsing_history': {
            const items = (d.items as HistoryItem[] | undefined) ?? [];
            return items.length === 0 ? null : (
                <section className="mx-auto max-w-7xl px-4 py-8">
                    <HistoryStrip items={items} />
                </section>
            );
        }
        case 'hot_game': {
            const columns = (d.columns as { type: string; label: string; href: string; games: { name: string; href: string; count: number; icon: string | null }[] }[] | undefined) ?? [];
            return columns.length === 0 ? null : (
                <section className="mx-auto max-w-7xl px-4 py-10">
                    <Heading title={title} />
                    <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {columns.map((c) => (
                            <li key={c.type} className="rounded-xl p-5 text-white" style={{ backgroundColor: HOT_COLOR[c.type] ?? 'var(--color-primary)' }}>
                                <h3 className="mb-3 text-lg font-extrabold">{c.label}</h3>
                                <ol className="space-y-2 text-sm">
                                    {c.games.map((g) => (
                                        <li key={g.href}>
                                            <Link href={g.href} className="flex items-center justify-between gap-2 hover:underline">
                                                <span className="truncate">{g.name}</span>
                                                <span className="text-white/70">({g.count})</span>
                                            </Link>
                                        </li>
                                    ))}
                                </ol>
                                <Link href={c.href} className="mt-4 inline-block rounded-full bg-white px-4 py-1.5 text-sm font-semibold text-ink">
                                    {t('home.view_more')}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            );
        }
        case 'trending_topup':
        case 'trending_giftcards':
        case 'trending_videogames': {
            const tiles = (d.tiles as Tile[] | undefined) ?? [];
            if (tiles.length === 0) {
                return null;
            }
            const band = module.code === 'trending_topup';
            return (
                <section className={band ? 'bg-gradient-to-br from-primary to-surface-dark-2 text-white' : ''}>
                    <div className="mx-auto max-w-7xl px-4 py-10">
                        <Heading title={title} more={d.more as string | null} />
                        <ul className={band ? 'grid grid-cols-2 gap-3 sm:grid-cols-5' : 'flex snap-x gap-3 overflow-x-auto pb-2'}>
                            {tiles.map((tile, i) => (
                                <li key={tile.href} className={band ? (i === 0 ? 'col-span-2 sm:col-span-1 sm:row-span-2' : '') : 'w-56 shrink-0 snap-start'}>
                                    <TileCard tile={tile} large={band && i === 0} />
                                </li>
                            ))}
                        </ul>
                    </div>
                </section>
            );
        }
        case 'community_ratings':
            return <CommunityRatings items={(d.items as CommunityRating[] | undefined) ?? []} title={module.title ?? undefined} />;
        case 'affiliate_banner':
            return (
                <section className="mx-auto max-w-7xl px-4 py-10">
                    <div className="flex flex-col items-start gap-4 rounded-2xl bg-gradient-to-r from-primary to-surface-dark-2 p-8 text-white sm:flex-row sm:items-center">
                        <div className="flex-1">
                            <h2 className="text-2xl font-extrabold">{module.title ?? t('home.affiliate.title', { rate: String(Number(d.rate ?? 0) / 100) })}</h2>
                            <p className="mt-1 text-white/80">{t('home.affiliate.body')}</p>
                        </div>
                        <Link href={String(d.href ?? '/' + locale + '/affiliate')} className="rounded-full bg-danger px-6 py-3 font-bold text-white hover:opacity-90">
                            {t('home.affiliate.cta')}
                        </Link>
                    </div>
                </section>
            );
        case 'new_game': {
            const columns = (d.columns as { label: string; href: string }[][] | undefined) ?? [];
            return columns.length === 0 ? null : (
                <section className="mx-auto max-w-7xl px-4 py-10">
                    <Heading title={title} />
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {columns.map((links, i) => (
                            <ul key={i} className="card space-y-1.5 p-4 text-sm">
                                {links.map((l) => (
                                    <li key={l.href}>
                                        <Link href={l.href} className="block truncate hover:text-primary">
                                            {l.label}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        ))}
                    </div>
                </section>
            );
        }
        case 'game_news': {
            const articles = (d.articles as Article[] | undefined) ?? [];
            return articles.length === 0 ? null : (
                <section className="bg-surface-dark text-white">
                    <div className="mx-auto max-w-7xl px-4 py-10">
                        <Heading title={title} more={d.more as string | null} />
                        <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            {articles.map((a) => (
                                <li key={a.slug} className="flex flex-col rounded-xl bg-surface-dark-2 p-5">
                                    <h3 className="line-clamp-2 font-bold">{a.title}</h3>
                                    {a.publishedAt && <p className="mt-1 text-xs font-semibold text-danger">{new Date(a.publishedAt).toLocaleDateString(locale)}</p>}
                                    {a.excerpt && <p className="mt-2 line-clamp-3 flex-1 text-sm text-white/70">{a.excerpt}</p>}
                                    <Link href={a.href} className="mt-4 self-start rounded-full border border-white/40 px-3 py-1 text-sm hover:bg-white/10">
                                        {t('home.view_more')} ›
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
                <section className="mx-auto max-w-7xl px-4 py-8" aria-label={title}>
                    <ul className="flex flex-wrap items-center justify-center gap-3">
                        {methods.map((m) => (
                            <li key={m.code} className="rounded-md border border-line bg-white px-3 py-2 text-xs font-semibold text-muted">
                                {m.logo ? <img src={m.logo} alt={m.name} className="h-6" loading="lazy" /> : m.name}
                            </li>
                        ))}
                    </ul>
                </section>
            );
        }
        default:
            return null;
    }
}
