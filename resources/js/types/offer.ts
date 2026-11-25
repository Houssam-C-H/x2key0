export interface OfferSeller {
    name: string;
    href: string;
    stars: number;
    levelName: string | null;
    positiveRateBps: number | null;
    logoUrl: string | null;
    online: boolean;
}

/** Buy box / "Other sellers" row (spec 1.5). */
export interface OfferRow {
    id: string;
    title: string;
    href: string;
    seller: OfferSeller;
    /** Running campaign type when the offer was approved for it (spec 14.2). */
    campaign: string | null;
    deliveryMethod: string;
    deliveryMinutes: number;
    auto: boolean;
    stock: number;
    stockLabel: 'adequate' | 'in_stock' | 'out_of_stock';
    minQty: number;
    maxQty: number | null;
    integerMultiple: boolean;
    unitLabel: string | null;
    insuranceDays: number;
    priceCents: number;
    tiers: { minQty: number; priceCents: number }[];
    favorited: boolean;
    serviceType: string | null;
}

/** Offer card (spec 1.4.1 card style 2). */
export interface OfferCardData {
    id: string;
    title: string;
    href: string;
    gameCategory: string;
    serviceType: string | null;
    campaign: string | null;
    image: string | null;
    seller: OfferSeller;
    deliveryMinutes: number;
    insuranceDays: number;
    priceCents: number;
    unitLabel: string | null;
}

export interface Paged<T> {
    items: T[];
    total: number;
    page: number;
    lastPage: number;
}

export type HistoryItem = ({ kind: 'product'; historyId: number } & import('./catalog').ProductCardData) | ({ kind: 'offer'; historyId: number } & OfferCardData);
