import { useShared, useT } from '@/lib/i18n';
import { pctOf } from './ReviewParts';

export interface CommunityRating {
    id: number;
    body: string;
    buyer: string;
    initial: string;
    stars: number;
    store: { name: string; href: string };
    positiveRateBps: number | null;
    createdAt: string;
}

/** Community ratings (spec 1.3 #7): review cards with stars, the seller's positive %, text, avatar, date, user. */
export function CommunityRatings({ items }: { items: CommunityRating[] }) {
    const t = useT();
    const { locale } = useShared();
    if (items.length === 0) {
        return null;
    }

    return (
        <section className="mx-auto max-w-7xl px-4 py-12" aria-labelledby="community-title">
            <h2 id="community-title" className="mb-5 text-2xl font-extrabold">
                {t('review.community.title')}
            </h2>
            <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {items.map((r) => (
                    <li key={r.id} className="card flex h-full flex-col gap-3 p-5">
                        <div className="flex items-center justify-between text-sm">
                            <span className="text-warning" role="img" aria-label={r.stars + '/5'}>
                                {'★'.repeat(r.stars)}
                                <span className="text-line">{'★'.repeat(5 - r.stars)}</span>
                            </span>
                            <span className="text-xs font-semibold text-success">{t('review.community.positive', { rate: pctOf(r.positiveRateBps) })}</span>
                        </div>
                        <p className="line-clamp-5 flex-1 text-sm">“{r.body}”</p>
                        <div className="flex items-center gap-2 text-xs text-muted">
                            <span aria-hidden className="flex h-7 w-7 items-center justify-center rounded-full bg-primary font-bold text-white">
                                {r.initial}
                            </span>
                            <span>
                                <span className="block font-semibold text-ink">{r.buyer}</span>
                                <a href={r.store.href} className="text-primary">
                                    {r.store.name}
                                </a>{' '}
                                · {new Date(r.createdAt).toLocaleDateString(locale)}
                            </span>
                        </div>
                    </li>
                ))}
            </ul>
        </section>
    );
}
