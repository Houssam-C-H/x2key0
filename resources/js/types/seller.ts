export interface SellerLevel {
    code: string;
    name: string;
    stars: number;
    commissionBps: number;
    holdDays: number;
    reserveBps: number;
    maxActiveOffers: number;
    autoApprove: boolean;
}

export interface SellerService {
    id: number;
    title: string;
    href: string;
}

export type SellerStatus = 'inactive' | 'active' | 'vacation' | 'suspended' | 'banned';

export type PerLocale = Record<string, string | null>;
