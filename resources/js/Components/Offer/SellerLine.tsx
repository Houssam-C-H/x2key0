import { Icon } from '@/Components/Icon';
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

/** Trust badges (SellerBadges): ID verified, top rated, fast delivery, years on X2KEY. */
export function SellerBadgeChips({ badges = [], years = 0, sales = 0, compact = false }: { badges?: string[]; years?: number; sales?: number; compact?: boolean }) {
    const t = useT();
    if (badges.length === 0 && (compact || sales === 0)) return null;

    const label: Record<string, string> = {
        verified: t('seller.badge.verified'),
        top_rated: t('seller.badge.top_rated'),
        fast: t('seller.badge.fast'),
        years: t('seller.badge.years', { count: years }),
    };
    const icon: Record<string, string> = { verified: 'shield', top_rated: 'star', fast: 'bolt', years: 'clock' };

    return (
        <span className="flex flex-wrap items-center gap-1">
            {badges.map((b) => (
                <span key={b} title={label[b]} className={'inline-flex items-center gap-1 rounded-md bg-success/10 font-semibold text-success ' + (compact ? 'px-1 py-0.5 text-[10px]' : 'px-1.5 py-0.5 text-[11px]')}>
                    <Icon name={icon[b] ?? 'check'} size={compact ? 11 : 12} />
                    {!compact && label[b]}
                </span>
            ))}
            {!compact && sales > 0 && <span className="text-[11px] text-muted">{t('seller.badge.sales', { count: sales })}</span>}
        </span>
    );
}

/** Seller name (link), level crowns and positive rating, as on offer rows. */
export function SellerLine({ seller, withBadges = true }: { seller: OfferSeller; withBadges?: boolean }) {
    const t = useT();

    return (
        <span className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5">
            {seller.online && <span role="img" aria-label={t('chat.online')} title={t('chat.online')} className="inline-block h-2 w-2 shrink-0 rounded-full bg-success" />}
            <a href={seller.href} className="truncate font-semibold hover:text-primary">
                {seller.name}
            </a>
            <SellerStars stars={seller.stars} />
            <span className="text-xs text-muted">{seller.positiveRateBps === null ? t('offer.new_seller') : t('offer.positive', { rate: bpsToPercent(seller.positiveRateBps) })}</span>
            {withBadges && <SellerBadgeChips badges={seller.badges} years={seller.years} sales={seller.sales} />}
        </span>
    );
}
