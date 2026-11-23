import { useState } from 'react';
import { GameTile } from '@/Components/Catalog/GameTile';
import { SeoHead } from '@/Components/Catalog/SeoHead';
import { PublicLayout } from '@/Layouts/PublicLayout';
import { useT } from '@/lib/i18n';
import type { GameCard, Seo } from '@/types/catalog';

interface Props {
    index: { letters: string[]; groups: Record<string, GameCard[]>; hot: GameCard[] };
    seo: Seo;
}

/** Game Index (spec 1.4.4): HOT and A–Z / # tabs, each game with its category links and counts. */
export default function GameIndex({ index, seo }: Props) {
    const t = useT();
    const [tab, setTab] = useState<string>('HOT');
    const [filter, setFilter] = useState('');

    const games = (tab === 'HOT' ? index.hot : (index.groups[tab] ?? [])).filter((g) => g.name.toLowerCase().includes(filter.toLowerCase()));
    const tabs = ['HOT', ...index.letters];

    return (
        <PublicLayout>
            <SeoHead seo={seo} />
            <div className="mx-auto max-w-7xl space-y-5 px-4 py-8">
                <div>
                    <h1 className="text-2xl font-extrabold">{t('catalog.game_index.title')}</h1>
                    <p className="mt-1 text-muted">{t('catalog.game_index.intro')}</p>
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    <div role="tablist" aria-label={t('catalog.game_index.title')} className="flex flex-wrap gap-1">
                        {tabs.map((l) => (
                            <button
                                key={l}
                                type="button"
                                role="tab"
                                aria-selected={tab === l}
                                onClick={() => setTab(l)}
                                className={`h-9 min-w-9 rounded-md px-2 text-sm font-bold ${tab === l ? 'bg-primary text-white' : 'bg-white hover:bg-primary-soft'}`}
                            >
                                {l}
                            </button>
                        ))}
                    </div>
                    <input type="search" className="field ms-auto max-w-xs" placeholder={t('catalog.game_index.filter')} value={filter} onChange={(e) => setFilter(e.target.value)} aria-label={t('catalog.game_index.filter')} />
                </div>

                {games.length === 0 ? (
                    <p className="card p-8 text-center text-muted">{t('catalog.empty')}</p>
                ) : (
                    <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {games.map((g) => (
                            <li key={g.slug}>
                                <GameTile game={g} />
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </PublicLayout>
    );
}
