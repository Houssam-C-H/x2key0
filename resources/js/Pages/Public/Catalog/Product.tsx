import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { Breadcrumbs } from '@/Components/Catalog/Breadcrumbs';
import { NoticeBox } from '@/Components/Catalog/NoticeBox';
import { SeoHead } from '@/Components/Catalog/SeoHead';
import { BuyBox } from '@/Components/Offer/BuyBox';
import { FavoriteButton } from '@/Components/Offer/FavoriteButton';
import { HistoryStrip } from '@/Components/Offer/HistoryStrip';
import { OffersTable } from '@/Components/Offer/OffersTable';
import { PublicLayout } from '@/Layouts/PublicLayout';
import { useShared, useT } from '@/lib/i18n';
import { formatUsd } from '@/lib/money';
import type { Crumb, GameCategoryInfo, ProductCardData, Seo } from '@/types/catalog';
import type { HistoryItem, OfferRow } from '@/types/offer';

interface Props {
    product: ProductCardData & { description: string; attributes: { label: string; value: string }[] };
    gameCategory: GameCategoryInfo;
    gameCategoryHref: string;
    offers: OfferRow[];
    deliveryMethods: string[];
    isFavorite: boolean;
    history: HistoryItem[];
    breadcrumbs: Crumb[];
    seo: Seo;
}

/**
 * Catalog product page (spec 1.5): buy box with the recommended offer, "Other sellers" table, description,
 * Browsing History strip.
 */
export default function Product({ product, gameCategory, gameCategoryHref, offers, deliveryMethods, isFavorite, history, breadcrumbs, seo }: Props) {
    const t = useT();
    const { locale } = useShared();
    const [selectedId, setSelectedId] = useState<string | null>(offers[0]?.id ?? null);
    const selected = offers.find((o) => o.id === selectedId) ?? offers[0] ?? null;
    const others = offers.filter((o) => o.id !== selected?.id);
    const otherFrom = others.length > 0 ? Math.min(...others.map((o) => o.priceCents)) : null;
    const msrp = formatUsd(product.msrpCents, locale);

    return (
        <PublicLayout>
            <SeoHead seo={seo} />
            <div className="mx-auto max-w-7xl space-y-6 px-4 py-6">
                <Breadcrumbs items={breadcrumbs} />

                <div className="grid gap-6 lg:grid-cols-[1fr_380px]">
                    <div className="space-y-6">
                        <div className="card flex flex-col gap-5 p-5 sm:flex-row">
                            <div className="flex aspect-[4/3] w-full shrink-0 items-center justify-center rounded-md bg-gradient-to-br from-primary to-surface-dark-2 p-4 text-center font-display text-lg font-bold text-white sm:w-64">
                                {product.image ? <img src={product.image} alt="" className="h-full w-full rounded-md object-cover" /> : product.name}
                            </div>
                            <div className="flex-1 space-y-3">
                                <div className="flex items-start gap-3">
                                    <h1 className="flex-1 text-2xl font-extrabold">{product.name}</h1>
                                    <FavoriteButton type="product" id={product.id} active={isFavorite} />
                                </div>
                                <div className="flex flex-wrap gap-2 text-xs">
                                    {product.region && (
                                        <span className="rounded-full bg-canvas px-2.5 py-1 font-semibold">
                                            {product.region.flag} {product.region.name}
                                        </span>
                                    )}
                                    {product.platform && <span className="rounded-full bg-canvas px-2.5 py-1 font-semibold">{product.platform}</span>}
                                    <Link href={gameCategoryHref} className="rounded-full bg-primary-soft px-2.5 py-1 font-semibold text-primary">
                                        {gameCategory.title}
                                    </Link>
                                </div>
                                {product.attributes.length > 0 && (
                                    <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                                        {product.attributes.map((a) => (
                                            <div key={a.label}>
                                                <dt className="text-xs text-muted">{a.label}</dt>
                                                <dd className="font-medium">{a.value}</dd>
                                            </div>
                                        ))}
                                    </dl>
                                )}
                            </div>
                        </div>

                        {gameCategory.notice && <NoticeBox text={gameCategory.notice} />}

                        {offers.length > 0 && <OffersTable offers={offers} methods={deliveryMethods} selectedId={selected?.id ?? null} onSelect={setSelectedId} />}

                        <section className="card p-5">
                            <h2 className="mb-3 text-lg font-bold">{t('catalog.product.description')}</h2>
                            <p className="whitespace-pre-line text-ink/85">{product.description}</p>
                        </section>
                    </div>

                    <aside className="card h-fit p-5 lg:sticky lg:top-20" aria-label={t('catalog.product.buy_box')}>
                        {selected ? (
                            <BuyBox key={selected.id} offer={selected} msrpCents={product.msrpCents} otherCount={others.length} otherFromCents={otherFrom} recommended={selected.id === offers[0]?.id} />
                        ) : (
                            <div className="space-y-4">
                                <p className="font-semibold">{t('catalog.no_offers_yet')}</p>
                                <p className="text-sm text-muted">{t('catalog.product.no_offers_hint')}</p>
                                {msrp && (
                                    <p className="text-sm text-muted">
                                        {t('catalog.product.msrp')}: <span className="line-through">{msrp}</span>
                                    </p>
                                )}
                                <button type="button" className="btn-primary h-12 w-full" disabled>
                                    {t('catalog.product.buy_now')}
                                </button>
                            </div>
                        )}
                    </aside>
                </div>

                <p className="text-sm">
                    {t('catalog.product.more_in')}{' '}
                    <Link href={gameCategoryHref} className="font-semibold text-primary hover:underline">
                        {gameCategory.title}
                    </Link>
                </p>

                <HistoryStrip items={history} />
            </div>
        </PublicLayout>
    );
}
