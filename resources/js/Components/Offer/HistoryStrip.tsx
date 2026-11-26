import { Link, router } from '@inertiajs/react';
import { useShared, useT } from '@/lib/i18n';
import { usePrice } from '@/lib/price';
import type { HistoryItem } from '@/types/offer';

/** Browsing History strip (spec 1.3 #2 / 1.5): cards with × per card and "Remove all". */
export function HistoryStrip({ items }: { items: HistoryItem[] }) {
    const t = useT();
    const { locale } = useShared();
    const displayPrice = usePrice();

    if (items.length === 0) return null;

    const remove = (id: number) => router.delete(`/history/${id}`, { preserveScroll: true, preserveState: true });

    return (
        <section aria-labelledby="history-title" className="space-y-3">
            <div className="flex items-center gap-3">
                <h2 id="history-title" className="text-lg font-bold">
                    {t('history.title')}
                </h2>
                <button type="button" className="btn-outline-pill ms-auto px-3 py-1 text-xs" onClick={() => router.delete('/history', { preserveScroll: true, preserveState: true })}>
                    {t('history.remove_all')} 🗑
                </button>
            </div>
            <ul className="flex snap-x gap-3 overflow-x-auto pb-2">
                {items.map((item) => (
                    <li key={item.historyId} className="card relative w-44 shrink-0 snap-start overflow-hidden">
                        <button type="button" onClick={() => remove(item.historyId)} aria-label={t('common.remove')} className="absolute end-1 top-1 z-10 rounded-full bg-black/50 px-1.5 text-xs text-white hover:bg-black/70">
                            ×
                        </button>
                        <Link href={item.href} className="block">
                            <div className="flex aspect-[4/3] items-center justify-center bg-gradient-to-br from-primary/80 to-surface-dark-2 p-2 text-center text-xs font-semibold text-white/90">
                                {item.image ? <img src={item.image} alt="" loading="lazy" className="h-full w-full object-cover" /> : item.kind === 'product' ? item.name : item.title}
                            </div>
                            <div className="space-y-1 p-2">
                                <p className="line-clamp-2 text-xs font-semibold">{item.kind === 'product' ? item.name : item.title}</p>
                                {item.kind === 'offer' && <p className="truncate text-[11px] text-muted">{item.seller.name}</p>}
                                <p className="text-sm font-bold">{displayPrice(item.priceCents) ?? '—'}</p>
                            </div>
                        </Link>
                    </li>
                ))}
            </ul>
        </section>
    );
}
