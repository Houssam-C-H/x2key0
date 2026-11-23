import { GameTile } from '@/Components/Catalog/GameTile';
import { ProductCard } from '@/Components/Catalog/ProductCard';
import { SeoHead } from '@/Components/Catalog/SeoHead';
import { PublicLayout } from '@/Layouts/PublicLayout';
import { useT } from '@/lib/i18n';
import type { GameCard, ProductCardData, Seo } from '@/types/catalog';

interface Props {
    q: string;
    type: string | null;
    results: { games: GameCard[]; products: ProductCardData[] };
    seo: Seo;
}

/** Header search results: matching games (with category links) and products. */
export default function Search({ q, results, seo }: Props) {
    const t = useT();
    const empty = results.games.length === 0 && results.products.length === 0;

    return (
        <PublicLayout>
            <SeoHead seo={seo} />
            <div className="mx-auto max-w-7xl space-y-8 px-4 py-8">
                <h1 className="text-2xl font-extrabold">{q ? t('catalog.search.for', { q }) : t('catalog.search.title')}</h1>

                {q.length > 0 && q.length < 2 && <p className="text-muted">{t('catalog.search.too_short')}</p>}

                {empty && q.length >= 2 && (
                    <div className="card p-8 text-center">
                        <p className="font-semibold">{t('catalog.search.none')}</p>
                        <p className="mt-1 text-sm text-muted">{t('catalog.search.none_hint')}</p>
                    </div>
                )}

                {results.games.length > 0 && (
                    <section>
                        <h2 className="mb-3 text-lg font-bold">{t('catalog.search.games')}</h2>
                        <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {results.games.map((g) => (
                                <li key={g.slug}>
                                    <GameTile game={g} />
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {results.products.length > 0 && (
                    <section>
                        <h2 className="mb-3 text-lg font-bold">{t('catalog.search.products')}</h2>
                        <ul className="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-4">
                            {results.products.map((p) => (
                                <li key={p.id}>
                                    <ProductCard product={p} />
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </PublicLayout>
    );
}
