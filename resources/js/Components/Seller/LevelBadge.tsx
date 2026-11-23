import type { SellerLevel } from '@/types/seller';

/** Seller level name with its stars (spec 3.3: seller level stars). */
export function LevelBadge({ level, light = false }: { level: SellerLevel; light?: boolean }) {
    return (
        <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ${light ? 'bg-white/15 text-white' : 'bg-primary-soft text-primary'}`}>
            <Stars count={level.stars} />
            {level.name}
        </span>
    );
}

export function Stars({ count, max = 5 }: { count: number; max?: number }) {
    return (
        <span aria-label={`${count}/${max}`} className="tracking-tight">
            {Array.from({ length: max }, (_, i) => (
                <span key={i} aria-hidden="true" className={i < count ? 'text-warning' : 'opacity-30'}>
                    ★
                </span>
            ))}
        </span>
    );
}

/** Basis points → "8%" / "7.5%". */
export function bpsToPercent(bps: number): string {
    return `${Number((bps / 100).toFixed(2))}%`;
}
