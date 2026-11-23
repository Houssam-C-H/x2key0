import { bpsToPercent } from '@/Components/Seller/LevelBadge';
import { useT } from '@/lib/i18n';
import type { OfferSeller } from '@/types/offer';

/** Seller level crowns (spec 1.4.1: crown icons = seller level). */
export function SellerStars({ stars }: { stars: number }) {
    if (stars <= 0) return null;

    return (
        <span className="text-[11px] text-warning" aria-label={`${stars}/5`}>
            {'♛'.repeat(Math.min(stars, 5))}
        </span>
    );
}

/** Seller name (link), level crowns and positive rating, as on offer rows. */
export function SellerLine({ seller }: { seller: OfferSeller }) {
    const t = useT();

    return (
        <span className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5">
            {seller.online && <span role="img" aria-label={t('chat.online')} title={t('chat.online')} className="inline-block h-2 w-2 shrink-0 rounded-full bg-success" />}
            <a href={seller.href} className="truncate font-semibold hover:text-primary">
                {seller.name}
            </a>
            <SellerStars stars={seller.stars} />
            <span className="text-xs text-muted">{seller.positiveRateBps === null ? t('offer.new_seller') : t('offer.positive', { rate: bpsToPercent(seller.positiveRateBps) })}</span>
        </span>
    );
}
