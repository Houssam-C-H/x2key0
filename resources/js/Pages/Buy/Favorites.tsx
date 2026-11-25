import { Link, router } from '@inertiajs/react';
import { DashboardLayout } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';
import { formatUsd } from '@/lib/money';

interface Item {
    kind: 'offer' | 'product';
    favoriteType: 'offer' | 'product';
    id: string;
    href: string;
    name?: string;
    title?: string;
    gameCategory: string;
    image: string | null;
    priceCents: number | null;
    addedAt: string;
}

/** My Favorites (spec 1.7: cards with title, game, category, price). */
export default function Favorites({ items }: { items: Item[] }) {
    const t = useT();
    const { locale } = useShared();

    const remove = (item: Item) => router.post('/favorites', { type: item.favoriteType, id: item.id }, { preserveScroll: true });

    return (
        <DashboardLayout title={t('menu.buy.favorites')}>
            <p className="mb-3 text-end text-sm">
                <Link href="/buy/price-alerts" className="font-semibold text-primary hover:underline">
                    {t('price_alert.page_title')} →
                </Link>
            </p>
            {items.length === 0 ? (
                <div className="flex flex-col items-center gap-3 py-12 text-center">
                    <span className="flex h-14 w-14 items-center justify-center rounded-full bg-canvas text-2xl text-muted">♡</span>
                    <p className="font-semibold">{t('favorites.empty')}</p>
                    <Link href={`/${locale}/games`} className="btn-primary">
                        {t('favorites.browse')}
                    </Link>
                </div>
            ) : (
                <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    {items.map((item) => (
                        <li key={`${item.favoriteType}-${item.id}`} className="card relative flex gap-3 p-3">
                            <Link href={item.href} className="flex h-20 w-24 shrink-0 items-center justify-center overflow-hidden rounded-md bg-gradient-to-br from-primary/80 to-surface-dark-2 p-1 text-center text-[11px] font-semibold text-white">
                                {item.image ? <img src={item.image} alt="" className="h-full w-full object-cover" /> : item.gameCategory}
                            </Link>
                            <div className="min-w-0 flex-1">
                                <Link href={item.href} className="line-clamp-2 text-sm font-semibold hover:text-primary">
                                    {item.title ?? item.name}
                                </Link>
                                <p className="truncate text-xs text-muted">
                                    {item.gameCategory} · {t(`favorites.kind.${item.kind}`)}
                                </p>
                                <p className="mt-1 font-bold">{formatUsd(item.priceCents, locale) ?? t('catalog.no_offers_yet')}</p>
                            </div>
                            <button type="button" className="absolute end-2 top-2 text-danger" aria-label={t('favorites.remove')} title={t('favorites.remove')} onClick={() => remove(item)}>
                                ♥
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </DashboardLayout>
    );
}
