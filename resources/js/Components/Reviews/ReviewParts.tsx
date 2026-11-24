import { useShared, useT } from '@/lib/i18n';

export type Rating = 'positive' | 'neutral' | 'negative';

export interface ScoreTableData {
    periods: Record<'7d' | '30d' | 'all', Record<Rating, number>>;
    positiveRateBps: number | null;
}

export interface ReviewRow {
    id: number;
    rating: Rating;
    scores: { delivery: number | null; service: number | null; description: number | null };
    body: string | null;
    reply: string | null;
    repliedAt: string | null;
    createdAt: string;
    edited: boolean;
    hidden: boolean;
    buyer: string | null;
    order: string | null;
    orderId: string | null;
    item: string;
    store: { name: string; href: string } | null;
    editable?: boolean;
}

export const RATING_STYLE: Record<Rating, string> = {
    positive: 'bg-success/10 text-success',
    neutral: 'bg-canvas text-muted',
    negative: 'bg-danger/10 text-danger',
};

export const pctOf = (bps: number | null) => (bps === null ? '—' : (bps / 100).toFixed(1).replace(/\.0$/, '') + ' %');

/** Feedback score table (spec 1.7 / 1.8): 7 days · 30 days · all × positive · neutral · negative, plus % positive. */
export function ScoreTable({ scores }: { scores: ScoreTableData }) {
    const t = useT();

    return (
        <div className="overflow-x-auto">
            <table className="w-full max-w-xl text-sm">
                <thead className="bg-canvas text-xs text-muted">
                    <tr>
                        <th className="px-3 py-2 text-start" />
                        {(['7d', '30d', 'all'] as const).map((p) => (
                            <th key={p} className="px-3 py-2 text-end">
                                {t('review.period.' + p)}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="divide-y divide-line">
                    {(['positive', 'neutral', 'negative'] as const).map((r) => (
                        <tr key={r}>
                            <th scope="row" className="px-3 py-2 text-start font-medium">
                                {t('review.rating.' + r)}
                            </th>
                            {(['7d', '30d', 'all'] as const).map((p) => (
                                <td key={p} className="px-3 py-2 text-end">
                                    {scores.periods[p][r]}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
            <p className="mt-2 text-sm">
                {t('review.positive_rate')}: <strong>{pctOf(scores.positiveRateBps)}</strong>
            </p>
        </div>
    );
}

export function Stars({ value, label }: { value: number | null; label?: string }) {
    if (value === null) {
        return null;
    }
    const full = Math.round(value);

    return (
        <span className="text-warning" role="img" aria-label={(label ? label + ': ' : '') + value + '/5'}>
            {'★'.repeat(full)}
            <span className="text-line">{'★'.repeat(5 - full)}</span>
        </span>
    );
}

/** One comment with sub-scores, text and the seller's reply. */
export function ReviewCard({ review, children }: { review: ReviewRow; children?: React.ReactNode }) {
    const t = useT();
    const { locale } = useShared();

    return (
        <article className="space-y-2 rounded-lg border border-line p-4 text-sm">
            <header className="flex flex-wrap items-center gap-2">
                <span className={'rounded-full px-2 py-0.5 text-xs font-semibold ' + RATING_STYLE[review.rating]}>{t('review.rating.' + review.rating)}</span>
                {review.buyer && <span className="font-semibold">{review.buyer}</span>}
                {review.store && (
                    <a href={review.store.href} className="font-semibold text-primary">
                        {review.store.name}
                    </a>
                )}
                <span className="text-xs text-muted">
                    {new Date(review.createdAt).toLocaleDateString(locale)}
                    {review.edited && ' · ' + t('review.edited')}
                    {review.order && ' · ' + review.order}
                </span>
            </header>
            {review.item && <p className="text-xs text-muted">{review.item}</p>}
            <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
                {review.scores.delivery !== null && <span>{t('review.score.delivery')} <Stars value={review.scores.delivery} /></span>}
                {review.scores.service !== null && <span>{t('review.score.service')} <Stars value={review.scores.service} /></span>}
                {review.scores.description !== null && <span>{t('review.score.description')} <Stars value={review.scores.description} /></span>}
            </div>
            {review.body && <p className="whitespace-pre-line">{review.body}</p>}
            {review.reply && (
                <div className="rounded-md bg-canvas p-3">
                    <p className="text-xs font-semibold text-muted">{t('review.seller_reply')}</p>
                    <p className="whitespace-pre-line">{review.reply}</p>
                </div>
            )}
            {children}
        </article>
    );
}
