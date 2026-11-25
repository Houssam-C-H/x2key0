import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Breadcrumbs } from '@/Components/Catalog/Breadcrumbs';
import { CategoryTabs } from '@/Components/Catalog/CategoryTabs';
import { FilterSidebar, type ListingState } from '@/Components/Catalog/FilterSidebar';
import { NoticeBox } from '@/Components/Catalog/NoticeBox';
import { Pagination } from '@/Components/Catalog/Pagination';
import { ProductCard } from '@/Components/Catalog/ProductCard';
import { OfferCard } from '@/Components/Offer/OfferCard';
import { SeoHead } from '@/Components/Catalog/SeoHead';
import { Icon } from '@/Components/Icon';
import { PublicLayout } from '@/Layouts/PublicLayout';
import { useShared, useT } from '@/lib/i18n';
import { formatCount } from '@/lib/money';
import type { CategoryTab, Crumb, FilterDef, GameCategoryInfo, ProductCardData, Seo } from '@/types/catalog';
import type { OfferCardData, Paged } from '@/types/offer';

interface Props {
    gameCategory: GameCategoryInfo;
    tabs: CategoryTab[];
    breadcrumbs: Crumb[];
    filters: FilterDef[];
    query: ListingState;
    sorts: string[];
    results: { items: ProductCardData[]; total: number; page: number; lastPage: number };
    /** Offer cards for game×categories without catalog products (currency, accounts, boosting…). */
    offerResults: Paged<OfferCardData> | null;
    /** Service type chips (ADR-032) for offer listings; empty when the category type has none. */
    serviceTypes?: { code: string; name: string; count: number }[];
    activeService?: string | null;
    seo: Seo;
}

const VIEW_KEY = 'x2k_listing_view';

/** Turns the listing state into the query string the server parses (ListingQuery::fromRequest). */
function toParams(state: ListingState, page = 1, service: string | null = null): Record<string, unknown> {
    const params: Record<string, unknown> = {};
    if (service) params.service = service;
    if (state.q) params.q = state.q;
    const f: Record<string, string[]> = {};
    for (const [key, values] of Object.entries(state.facets)) {
        if (values.length === 0) continue;
        if (key === 'platform' || key === 'region') params[key] = values;
        else f[key.replace(/^attr\./, '')] = values;
    }
    if (Object.keys(f).length) params.f = f;
    const r: Record<string, Record<string, number>> = {};
    for (const [key, range] of Object.entries(state.ranges)) {
        const entry: Record<string, number> = {};
        if (range.min !== null) entry.min = range.min;
        if (range.max !== null) entry.max = range.max;
        if (Object.keys(entry).length) r[key] = entry;
    }
    if (Object.keys(r).length) params.r = r;
    const price: Record<string, number> = {};
    if (state.price.min !== null) price.min = state.price.min;
    if (state.price.max !== null) price.max = state.price.max;
    if (Object.keys(price).length) params.price = price;
    if (state.sort !== 'recommended') params.sort = state.sort;
    if (page > 1) params.page = page;
    return params;
}

export default function Listing({ gameCategory, tabs, breadcrumbs, filters, query, sorts, results, offerResults, serviceTypes = [], activeService = null, seo }: Props) {
    const t = useT();
    const { locale } = useShared();
    const [view, setView] = useState<'grid' | 'list'>('grid');
    const [filtersOpen, setFiltersOpen] = useState(false);

    useEffect(() => {
        const saved = window.localStorage.getItem(VIEW_KEY);
        if (saved === 'grid' || saved === 'list') setView(saved);
    }, []);

    const visit = (state: ListingState, page = 1, service: string | null = activeService) => {
        router.get(window.location.pathname, toParams(state, page, service) as never, { preserveScroll: page === 1, preserveState: true, replace: true });
    };

    const showPrice = offerResults !== null || results.items.some((p) => p.priceCents !== null) || query.price.min !== null || query.price.max !== null;

    return (
        <PublicLayout>
            <SeoHead seo={seo} />
            <div className="mx-auto max-w-7xl space-y-5 px-4 py-6">
                <Breadcrumbs items={breadcrumbs} />
                <h1 className="text-2xl font-extrabold">{gameCategory.title}</h1>
                <CategoryTabs tabs={tabs} />
                {gameCategory.notice && <NoticeBox text={gameCategory.notice} />}

                {serviceTypes.length > 0 && (
                    <nav aria-label={t('service_type.filter')} className="no-scrollbar -mx-4 flex gap-2 overflow-x-auto px-4 pb-1">
                        {[{ code: null as string | null, name: t('service_type.all'), count: serviceTypes.reduce((n, s) => n + s.count, 0) }, ...serviceTypes].map((s) => {
                            const on = s.code === activeService;
                            return (
                                <button
                                    key={s.code ?? 'all'}
                                    type="button"
                                    aria-pressed={on}
                                    onClick={() => visit(query, 1, s.code)}
                                    className={
                                        'inline-flex shrink-0 items-center gap-2 rounded-full border px-4 py-2 text-sm font-semibold transition ' +
                                        (on ? 'border-primary bg-primary text-white' : 'border-line bg-white hover:border-primary hover:text-primary')
                                    }
                                >
                                    {s.name}
                                    <span className={'rounded-full px-1.5 text-[11px] ' + (on ? 'bg-white/20' : 'bg-canvas text-muted')}>{formatCount(s.count, locale)}</span>
                                </button>
                            );
                        })}
                    </nav>
                )}

                <div className="flex gap-6">
                    <div className={`${filtersOpen ? 'fixed inset-0 z-40 overflow-y-auto bg-canvas p-4' : 'hidden'} w-full shrink-0 lg:static lg:block lg:w-72 lg:bg-transparent lg:p-0`}>
                        {filtersOpen && (
                            <button type="button" className="btn-primary mb-3 w-full lg:hidden" onClick={() => setFiltersOpen(false)}>
                                {t('catalog.filter.show_results', { count: offerResults?.total ?? results.total })}
                            </button>
                        )}
                        <FilterSidebar filters={filters} state={query} onChange={(s) => visit(s)} showPrice={showPrice} />
                    </div>

                    <section className="min-w-0 flex-1" aria-live="polite">
                        <div className="mb-4 flex flex-wrap items-center gap-3">
                            <button type="button" className="btn-outline-pill px-3 py-1.5 text-sm lg:hidden" onClick={() => setFiltersOpen(true)}>
                                {t('catalog.filter.title')}
                            </button>
                            <p className="text-sm text-muted">
                                {offerResults !== null ? t('offer.listing_count', { count: formatCount(offerResults.total, locale) }) : t('catalog.results', { count: formatCount(results.total, locale) })}
                            </p>
                            <div className="ms-auto flex items-center gap-2">
                                <label className="flex items-center gap-2 text-sm">
                                    <span className="text-muted">{t('catalog.sort.label')}</span>
                                    <select className="field w-auto py-1.5" value={query.sort} onChange={(e) => visit({ ...query, sort: e.target.value })}>
                                        {sorts.map((s) => (
                                            <option key={s} value={s}>
                                                {t(`catalog.sort.${s}`)}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <div className="flex rounded-md border border-line" role="group" aria-label={t('catalog.view.label')}>
                                    {(['grid', 'list'] as const).map((v) => (
                                        <button
                                            key={v}
                                            type="button"
                                            aria-pressed={view === v}
                                            title={t(`catalog.view.${v}`)}
                                            onClick={() => {
                                                setView(v);
                                                window.localStorage.setItem(VIEW_KEY, v);
                                            }}
                                            className={`px-2.5 py-1.5 text-sm ${view === v ? 'bg-primary text-white' : 'hover:bg-canvas'}`}
                                        >
                                            {v === 'grid' ? '▦' : '☰'}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        </div>

                        {offerResults !== null ? (
                            offerResults.items.length === 0 ? (
                                <div className="card flex flex-col items-center gap-3 py-16 text-center">
                                    <span className="flex h-14 w-14 items-center justify-center rounded-full bg-canvas text-2xl font-bold text-muted">!</span>
                                    <p className="font-semibold">{t('catalog.empty')}</p>
                                </div>
                            ) : (
                                <>
                                    <ul className="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-4">
                                        {offerResults.items.map((o) => (
                                            <li key={o.id}>
                                                <OfferCard offer={o} />
                                            </li>
                                        ))}
                                    </ul>
                                </>
                            )
                        ) : results.items.length === 0 ? (
                            <div className="card flex flex-col items-center gap-3 py-16 text-center">
                                <span className="flex h-14 w-14 items-center justify-center rounded-full bg-canvas text-2xl font-bold text-muted">!</span>
                                <p className="font-semibold">{t('catalog.empty')}</p>
                            </div>
                        ) : (
                            <ul className={view === 'grid' ? 'grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-4' : 'space-y-3'}>
                                {results.items.map((p) => (
                                    <li key={p.id}>
                                        <ProductCard product={p} layout={view} />
                                    </li>
                                ))}
                            </ul>
                        )}

                        <div className="mt-6">
                            {offerResults !== null ? (
                                <Pagination page={offerResults.page} lastPage={offerResults.lastPage} onPage={(p) => visit(query, p)} />
                            ) : (
                                <Pagination page={results.page} lastPage={results.lastPage} onPage={(p) => visit(query, p)} />
                            )}
                        </div>
                    </section>
                </div>

                <a href={gameCategory.gameHref} className="inline-flex items-center gap-1 text-sm text-primary hover:underline">
                    <Icon name="chevron" size={14} className="rotate-180 rtl:rotate-0" />
                    {t('catalog.back_to_game', { game: gameCategory.game })}
                </a>
            </div>
        </PublicLayout>
    );
}
