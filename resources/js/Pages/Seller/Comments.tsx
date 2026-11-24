import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { pctOf, type Rating, ReviewCard, type ReviewRow, ScoreTable, type ScoreTableData, Stars } from '@/Components/Reviews/ReviewParts';
import { DashboardLayout, Panel } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';

interface Props {
    rating: Rating | null;
    comments: ReviewRow[];
    scores: ScoreTableData;
    subScores: { delivery: number | null; service: number | null; description: number | null; overall: number | null };
    positive90: number | null;
    maxLength: number;
}

/** Sell Order Comments (spec 1.8): score table, % positive, transaction level and sub-scores, filter, reply once. */
export default function SellerComments({ rating, comments, scores, subScores, positive90, maxLength }: Props) {
    const t = useT();
    const [replying, setReplying] = useState<number | null>(null);

    return (
        <DashboardLayout title={t('menu.seller.store.comments')} bare>
            <section className="grid gap-4 lg:grid-cols-2">
                <Panel title={t('review.score_table')}>
                    <ScoreTable scores={scores} />
                    <p className="mt-1 text-sm">
                        {t('review.positive_rate_90')}: <strong>{pctOf(positive90)}</strong>
                    </p>
                </Panel>
                <Panel title={t('review.transaction_level')}>
                    <p className="text-3xl font-extrabold">{subScores.overall ?? '—'}</p>
                    <dl className="mt-3 space-y-1 text-sm">
                        {(['delivery', 'service', 'description'] as const).map((k) => (
                            <div key={k} className="flex items-center justify-between gap-4">
                                <dt className="text-muted">{t('review.score.' + k)}</dt>
                                <dd>
                                    <Stars value={subScores[k]} /> {subScores[k] ?? '—'}
                                </dd>
                            </div>
                        ))}
                    </dl>
                </Panel>
            </section>

            <Panel title={t('menu.seller.store.comments')}>
                <div className="mb-3 flex gap-1">
                    {([null, 'positive', 'neutral', 'negative'] as const).map((r) => (
                        <button
                            key={r ?? 'all'}
                            type="button"
                            aria-pressed={rating === r}
                            onClick={() => router.get('/seller/comments', { rating: r ?? undefined }, { preserveScroll: true, preserveState: true })}
                            className={'rounded-full px-2.5 py-0.5 text-xs font-semibold ' + (rating === r ? 'bg-ink text-white' : 'bg-canvas')}
                        >
                            {r === null ? t('announcements.filter.all') : t('review.rating.' + r)}
                        </button>
                    ))}
                </div>
                {comments.length === 0 ? (
                    <p className="text-sm text-muted">{t('review.none_received')}</p>
                ) : (
                    <div className="space-y-3">
                        {comments.map((c) => (
                            <ReviewCard key={c.id} review={c}>
                                {c.reply === null &&
                                    (replying === c.id ? (
                                        <ReplyForm id={c.id} maxLength={maxLength} onDone={() => setReplying(null)} />
                                    ) : (
                                        <button type="button" className="text-xs text-primary" onClick={() => setReplying(c.id)}>
                                            {t('review.reply')}
                                        </button>
                                    ))}
                            </ReviewCard>
                        ))}
                    </div>
                )}
            </Panel>
        </DashboardLayout>
    );
}

function ReplyForm({ id, maxLength, onDone }: { id: number; maxLength: number; onDone: () => void }) {
    const t = useT();
    const { errors } = useShared();
    const form = useForm({ reply: '' });

    return (
        <form
            className="space-y-2"
            onSubmit={(e) => {
                e.preventDefault();
                form.post('/seller/comments/' + id + '/reply', { preserveScroll: true, onSuccess: onDone });
            }}
        >
            <textarea className="field min-h-20 w-full" maxLength={maxLength} value={form.data.reply} onChange={(e) => form.setData('reply', e.target.value)} aria-label={t('review.reply')} placeholder={t('review.reply_help')} />
            {errors.reply && <p className="text-sm text-danger">{errors.reply}</p>}
            <div className="flex gap-2">
                <button type="submit" className="btn-primary px-3 py-1 text-sm" disabled={form.processing || form.data.reply.trim() === ''}>
                    {t('review.send_reply')}
                </button>
                <button type="button" className="btn-outline-pill px-3 py-1 text-sm" onClick={onDone}>
                    {t('common.cancel')}
                </button>
            </div>
        </form>
    );
}
