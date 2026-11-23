import { Link } from '@inertiajs/react';
import { useShared, useT } from '@/lib/i18n';
import { formatUsd } from '@/lib/money';
import type { ProductCardData } from '@/types/catalog';

/** Product card (spec 1.4.1): cover, region/platform badges, title, offers, from-price, MSRP + discount. */
export function ProductCard({ product, layout = 'grid' }: { product: ProductCardData; layout?: 'grid' | 'list' }) {
    const t = useT();
    const { locale } = useShared();
    const price = formatUsd(product.priceCents, locale);
    const msrp = formatUsd(product.msrpCents, locale);

    const cover = (
        <div className={`relative overflow-hidden bg-gradient-to-br from-primary/80 to-surface-dark-2 ${layout === 'grid' ? 'aspect-[4/3]' : 'h-24 w-32 shrink-0 rounded-md'}`}>
            {product.image ? (
                <img src={product.image} alt="" loading="lazy" className="h-full w-full object-cover" />
            ) : (
                <span className="absolute inset-0 flex items-center justify-center p-3 text-center font-display text-sm font-bold text-white/90">{product.name}</span>
            )}
            <div className="absolute start-2 top-2 flex flex-wrap gap-1">
                {product.region && (
                    <span className="rounded bg-black/60 px-1.5 py-0.5 text-[11px] font-semibold text-white" title={product.region.name}>
                        {product.region.flag} {product.region.code}
                    </span>
                )}
                {product.platform && <span className="rounded bg-black/60 px-1.5 py-0.5 text-[11px] font-semibold text-white">{product.platform}</span>}
            </div>
            {product.discountPercent !== null && <span className="absolute end-2 top-2 rounded bg-danger px-1.5 py-0.5 text-xs font-bold text-white">−{product.discountPercent}%</span>}
        </div>
    );

    const body = (
        <div className={`flex flex-1 flex-col gap-1 ${layout === 'grid' ? 'p-3' : ''}`}>
            <h3 className="line-clamp-2 text-sm font-semibold group-hover:text-primary">{product.name}</h3>
            <p className="text-xs text-muted">{product.offers > 0 ? t('catalog.offers', { count: product.offers }) : t('catalog.no_offers_yet')}</p>
            <div className="mt-auto flex flex-wrap items-baseline gap-2 pt-1">
                {price ? (
                    <span className="font-bold">
                        <span className="text-xs font-normal text-muted">{t('catalog.from')} </span>
                        {price}
                    </span>
                ) : null}
                {msrp && <span className="text-xs text-muted line-through">{msrp}</span>}
            </div>
        </div>
    );

    return (
        <Link href={product.href} className={`group card flex overflow-hidden transition-shadow hover:shadow-md ${layout === 'grid' ? 'flex-col' : 'items-center gap-4 p-3'}`}>
            {cover}
            {body}
        </Link>
    );
}
