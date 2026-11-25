import { Link } from '@inertiajs/react';
import { Breadcrumbs } from '@/Components/Catalog/Breadcrumbs';
import { SeoHead } from '@/Components/Catalog/SeoHead';
import { GameArt } from '@/Components/Home/HomeParts';
import { categoryIcon, Icon } from '@/Components/Icon';
import { PublicLayout } from '@/Layouts/PublicLayout';
import { useShared, useT } from '@/lib/i18n';
import { formatCount } from '@/lib/money';
import type { CategoryTab, Crumb, Seo } from '@/types/catalog';

interface Props {
    game: { name: string; slug: string; publisher: string | null; icon?: string | null; banner?: string | null };
    tabs: CategoryTab[];
    breadcrumbs: Crumb[];
    seo: Seo;
}

/** Game hub: every category of one game with counts. */
export default function GameHub({ game, tabs, breadcrumbs, seo }: Props) {
    const t = useT();
    const { locale } = useShared();

    return (
        <PublicLayout>
            <SeoHead seo={seo} />
            <section
                className="relative bg-surface-dark bg-cover bg-center text-white"
                style={game.banner ? { backgroundImage: `linear-gradient(90deg, var(--color-surface-dark) 20%, color-mix(in srgb, var(--color-surface-dark) 40%, transparent)), url(${JSON.stringify(game.banner)})` } : undefined}
            >
                <div className="relative mx-auto max-w-7xl space-y-4 px-4 py-10">
                    <div className="[&_a]:text-white/70 [&_span]:text-white/90">
                        <Breadcrumbs items={breadcrumbs} />
                    </div>
                    <div className="flex items-center gap-4">
                        <span className="h-16 w-16 shrink-0 overflow-hidden rounded-xl ring-2 ring-white/20">
                            <GameArt name={game.name} image={game.icon} compact />
                        </span>
                        <div>
                            <h1 className="text-3xl font-extrabold">{game.name}</h1>
                            {game.publisher && <p className="text-sm text-white/60">{game.publisher}</p>}
                        </div>
                    </div>
                </div>
            </section>
            <div className="mx-auto max-w-7xl px-4 py-8">
                <h2 className="mb-4 text-lg font-bold">{t('catalog.game_hub.choose', { game: game.name })}</h2>
                <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {tabs.map((tab) => (
                        <li key={tab.code}>
                            <Link href={tab.href} className="card flex items-center gap-4 p-5 transition-shadow hover:shadow-md">
                                <span className="flex h-12 w-12 items-center justify-center rounded-lg bg-primary-soft text-primary">
                                    <Icon name={categoryIcon[tab.code] ?? 'box'} size={24} />
                                </span>
                                <span className="flex-1">
                                    <span className="block font-bold">
                                        {game.name} {tab.label}
                                    </span>
                                    <span className="text-sm text-muted">{t('catalog.listings', { count: formatCount(tab.count, locale) })}</span>
                                </span>
                                <Icon name="chevron" size={18} className="text-muted rtl:-scale-x-100" />
                            </Link>
                        </li>
                    ))}
                </ul>
            </div>
        </PublicLayout>
    );
}
