import { Link } from '@inertiajs/react';
import { useShared, useT } from '@/lib/i18n';
import { usePrice } from '@/lib/price';
import { deliveryTime } from '@/lib/offer';
import type { OfferCardData } from '@/types/offer';
import { SellerBadgeChips, SellerStars } from './SellerLine';

/**
 * Offer card (spec 1.4.1, card style 2): title on top, image area with insurance and delivery-time badges,
 * footer with the seller and the price.
 */
export function OfferCard({ offer }: { offer: OfferCardData }) {
    const t = useT();
    const { locale } = useShared();
    const displayPrice = usePrice();

    return (
        <Link href={offer.href} className="group card flex h-full flex-col overflow-hidden transition-shadow hover:shadow-md">
            {offer.serviceType && <span className="mx-3 mt-3 self-start rounded-md bg-primary-soft px-2 py-0.5 text-[11px] font-semibold text-primary">{offer.serviceType}</span>}
            <h3 className={'line-clamp-2 min-h-[2.75rem] px-3 text-sm font-semibold group-hover:text-primary ' + (offer.serviceType ? 'pt-1.5' : 'pt-3')}>{offer.title}</h3>
            <div className="relative mx-3 mt-2 aspect-[16/9] overflow-hidden rounded-md bg-gradient-to-br from-primary/80 to-surface-dark-2">
                {offer.image ? (
                    <img src={offer.image} alt="" loading="lazy" className="h-full w-full object-cover" />
                ) : (
                    <span className="absolute inset-0 flex items-center justify-center p-3 text-center text-xs font-semibold text-white/80">{offer.gameCategory}</span>
                )}
                {offer.insuranceDays > 0 && (
                    <span className="absolute start-2 top-2 rounded bg-success px-1.5 py-0.5 text-[11px] font-bold text-white" title={t('offer.insurance_days', { count: offer.insuranceDays })}>
                        🛡 {offer.insuranceDays}
                    </span>
                )}
                {offer.campaign && (
                    <span className="absolute end-2 top-2 rounded bg-danger px-1.5 py-0.5 text-[11px] font-bold text-white">{t('campaign.badge.' + offer.campaign)}</span>
                )}
                <span className="absolute bottom-2 end-2 rounded bg-black/60 px-1.5 py-0.5 text-[11px] font-semibold text-white">{deliveryTime(offer.deliveryMinutes, t)}</span>
            </div>
            <div className="mt-auto flex items-center gap-2 p-3">
                <span className="min-w-0 flex-1">
                    <span className="flex items-center gap-1">
                        <span className="truncate text-xs font-semibold">{offer.seller.name}</span>
                        <SellerBadgeChips badges={offer.seller.badges} compact />
                    </span>
                    <SellerStars stars={offer.seller.stars} />
                </span>
                <span className="text-end font-bold">
                    <span className="block text-[11px] font-normal text-muted">{t('catalog.from')}</span>
                    {displayPrice(offer.priceCents)}
                </span>
            </div>
        </Link>
    );
}
