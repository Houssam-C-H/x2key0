import { Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { SeoHead } from '@/Components/Catalog/SeoHead';
import { categoryIcon, Icon } from '@/Components/Icon';
import { PublicLayout } from '@/Layouts/PublicLayout';
import { useShared, useT } from '@/lib/i18n';
import { formatCount } from '@/lib/money';
import type { Seo } from '@/types/catalog';

interface Props {
    type: { code: string; name: string };
    types: { code: string; name: string; href: string; active: boolean }[];
    games: { name: string; label: string; href: string; count: number; letter: string; hot: boolean }[];
    seo: Seo;
}

/** Category hub (spec 1.4.2): all games of one category type, type tabs, A–Z filter, trending first. */
export default function CategoryHub({ type, types, games, seo }: Props) {
    const t = useT();
    const { locale } = useShared();
    const [letter, setLetter] = useState<string | null>(null);

    const letters = useMemo(() => Array.from(new Set(games.map((g) => g.letter))).sort((a, b) => (a === '#' ? 1 : b === '#' ? -1 : a.localeCompare(b))), [games]);
    const trending = games.filter((g) => g.hot);
    const shown = letter === null ? games : games.filter((g) => g.letter === letter);

    return (
        <PublicLayout>
            <SeoHead seo={seo} />
            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8">
                <nav aria-label={t('megamenu.title')} className="-mx-4 overflow-x-auto px-4">
                    <ul className="flex gap-2">
                        {types.map((ct) => (
                            <li key={ct.code}>
                                <Link
                                    href={ct.href}
                                    aria-current={ct.active ? 'page' : undefined}
                                    className={`flex items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-semibold whitespace-nowrap ${ct.active ? 'bg-primary text-white' : 'bg-white hover:text-primary'}`}
                                >
                                    <Icon name={categoryIcon[ct.code] ?? 'box'} size={14} />
                                    {ct.name}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </nav>

                <div>
                    <h1 className="text-2xl font-extrabold">{type.name}</h1>
                    <p className="text-muted">{t('catalog.results', { count: formatCount(games.length, locale) })}</p>
                </div>

                {trending.length > 0 && (
                    <section>
                        <h2 className="mb-3 font-bold">{t('catalog.trending')}</h2>
                        <ul className="flex flex-wrap gap-2">
                            {trending.map((g) => (
                                <li key={g.href}>
                                    <Link href={g.href} className="rounded-full border border-primary px-3 py-1.5 text-sm font-semibold text-primary hover:bg-primary-soft">
                                        {g.name}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <div role="tablist" className="flex flex-wrap gap-1">
                    <button type="button" role="tab" aria-selected={letter === null} onClick={() => setLetter(null)} className={`h-9 rounded-md px-3 text-sm font-bold ${letter === null ? 'bg-primary text-white' : 'bg-white'}`}>
                        {t('catalog.all')}
                    </button>
                    {letters.map((l) => (
                        <button key={l} type="button" role="tab" aria-selected={letter === l} onClick={() => setLetter(l)} className={`h-9 min-w-9 rounded-md px-2 text-sm font-bold ${letter === l ? 'bg-primary text-white' : 'bg-white'}`}>
                            {l}
                        </button>
                    ))}
                </div>

                <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    {shown.map((g) => (
                        <li key={g.href}>
                            <Link href={g.href} className="card flex h-full flex-col gap-1 p-4 hover:shadow-md">
                                <span className="font-semibold">{g.name}</span>
                                <span className="text-xs text-muted">{g.label}</span>
                                <span className="mt-auto text-xs font-semibold text-primary">{t('catalog.listings', { count: formatCount(g.count, locale) })}</span>
                            </Link>
                        </li>
                    ))}
                </ul>
            </div>
        </PublicLayout>
    );
}
