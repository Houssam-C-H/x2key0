import { router } from '@inertiajs/react';
import { formatUsd } from '@/lib/money';
import { useShared, useT } from '@/lib/i18n';

export interface CouponCardData {
    id: string;
    issuer: 'platform' | 'seller';
    label: string;
    name: string;
    code: string | null;
    store: { name: string; href: string } | null;
    scope: string | null;
    minOrder: number;
    maxDiscount: number | null;
    pointsCost: number | null;
    left: number | null;
    validTo: string | null;
    claimed: boolean;
}

/** A claimable coupon: discount, conditions, and Claim / Redeem with points (spec 14.1). */
export function CouponCard({ coupon, canClaim = true, points = null }: { coupon: CouponCardData; canClaim?: boolean; points?: number | null }) {
    const t = useT();
    const { locale } = useShared();
    const money = (cents: number) => formatUsd(cents, locale) ?? '';
    const needsPoints = coupon.pointsCost !== null && coupon.pointsCost > 0;
    const affordable = !needsPoints || (points ?? 0) >= (coupon.pointsCost ?? 0);

    return (
        <div className="flex h-full gap-3 rounded-lg border border-dashed border-primary/50 bg-primary-soft/40 p-3">
            <div className="flex w-20 shrink-0 flex-col items-center justify-center rounded-md bg-primary px-2 py-3 text-center text-white">
                <span className="text-lg font-extrabold leading-tight">{coupon.label}</span>
                <span className="text-[10px] uppercase tracking-wide">{t('coupon.off')}</span>
            </div>
            <div className="min-w-0 flex-1 space-y-1 text-sm">
                <p className="truncate font-semibold">{coupon.name}</p>
                {coupon.store && (
                    <a href={coupon.store.href} className="block truncate text-xs text-primary">
                        {coupon.store.name}
                    </a>
                )}
                {coupon.scope && <p className="truncate text-xs text-muted">{coupon.scope}</p>}
                <p className="text-xs text-muted">
                    {coupon.minOrder > 0 ? t('coupon.min_order', { amount: money(coupon.minOrder) }) : t('coupon.no_minimum')}
                    {coupon.maxDiscount !== null && ' · ' + t('coupon.max_discount', { amount: money(coupon.maxDiscount) })}
                </p>
                {coupon.validTo && <p className="text-xs text-muted">{t('coupon.valid_to', { date: new Date(coupon.validTo).toLocaleDateString(locale) })}</p>}
                {coupon.left !== null && <p className="text-xs text-muted">{t('coupon.left', { count: coupon.left })}</p>}
                {canClaim && (
                    <div className="pt-1">
                        {coupon.claimed ? (
                            <span className="text-xs font-semibold text-success">{t('coupon.claimed_badge')}</span>
                        ) : (
                            <button
                                type="button"
                                className="btn-primary px-3 py-1 text-xs"
                                disabled={!affordable}
                                onClick={() => router.post('/coupons/' + coupon.id + (needsPoints ? '/redeem' : '/claim'), {}, { preserveScroll: true })}
                            >
                                {needsPoints ? t('coupon.redeem_points', { points: coupon.pointsCost ?? 0 }) : t('coupon.claim')}
                            </button>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}
