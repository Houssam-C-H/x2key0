import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { RATING_STYLE, ReviewCard, type Rating, type ReviewRow, ScoreTable, type ScoreTableData } from '@/Components/Reviews/ReviewParts';
import { DashboardLayout, Panel } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';

interface Pending {
    orderId: string;
    number: string;
    item: string;
    store: string;
    completedAt: string | null;
    deadline: string | null;
}

interface Props {
    tab: 'pending' | 'commented';
    rating: Rating | null;
    pending: Pending[];
    comments: ReviewRow[];
    scores: ScoreTableData;
    open: string | null;
    maxLength: number;
}

interface FormData {
    rating: Rating;
    score_delivery: number | null;
    score_service: number | null;
    score_description: number | null;
    body: string;
}

/** Purchase Order Comment (spec 1.7 / Part 13): To comment · Commented, feedback score table, leave / edit a comment. */
export default function BuyComments({ tab, rating, pending, comments, scores, open, maxLength }: Props) {
    const t = useT();
    const { locale } = useShared();
    const [writing, setWriting] = useState<string | null>(open);
    const [editing, setEditing] = useState<number | null>(null);

    return (
        <DashboardLayout title={t('menu.buy.comments')} bare>
            <Panel title={t('review.score_table')}>
                <ScoreTable scores={scores} />
            </Panel>

            <section className="card p-0">
                <div className="flex flex-wrap gap-2 border-b border-line p-3">
                    {(['pending', 'commented'] as const).map((k) => (
                        <Link
                            key={k}
                            href={'/buy/comments' + (k === 'commented' ? '?tab=commented' : '')}
                            preserveScroll
                            className={'rounded-full border px-3 py-1 text-sm font-semibold ' + (tab === k ? 'border-primary bg-primary-soft text-primary' : 'border-line')}
                        >
                            {t('review.tab.' + k)} ({k === 'pending' ? pending.length : comments.length})
                        </Link>
                    ))}
                    {tab === 'commented' && (
                        <div className="ms-auto flex gap-1">
                            {([null, 'positive', 'neutral', 'negative'] as const).map((r) => (
                                <button
                                    key={r ?? 'all'}
                                    type="button"
                                    aria-pressed={rating === r}
                                    onClick={() => router.get('/buy/comments', { tab: 'commented', rating: r ?? undefined }, { preserveScroll: true, preserveState: true })}
                                    className={'rounded-full px-2.5 py-0.5 text-xs font-semibold ' + (rating === r ? 'bg-ink text-white' : 'bg-canvas')}
                                >
                                    {r === null ? t('announcements.filter.all') : t('review.rating.' + r)}
                                </button>
                            ))}
                        </div>
                    )}
                </div>

                <div className="space-y-3 p-4">
                    {tab === 'pending' &&
                        (pending.length === 0 ? (
                            <p className="text-sm text-muted">{t('review.none_pending')}</p>
                        ) : (
                            pending.map((o) => (
                                <div key={o.orderId} className="rounded-lg border border-line p-4">
                                    <div className="flex flex-wrap items-center gap-2 text-sm">
                                        <span className="font-semibold">{o.item}</span>
                                        <span className="text-muted">· {o.store} · {o.number}</span>
                                        {o.deadline && <span className="text-xs text-muted">{t('review.deadline', { date: new Date(o.deadline).toLocaleDateString(locale) })}</span>}
                                        {writing !== o.orderId && (
                                            <button type="button" className="btn-primary ms-auto px-3 py-1 text-sm" onClick={() => setWriting(o.orderId)}>
                                                {t('review.leave')}
                                            </button>
                                        )}
                                    </div>
                                    {writing === o.orderId && <CommentForm action={'/buy/orders/' + o.orderId + '/comment'} method="post" maxLength={maxLength} onDone={() => setWriting(null)} />}
                                </div>
                            ))
                        ))}

                    {tab === 'commented' &&
                        (comments.length === 0 ? (
                            <p className="text-sm text-muted">{t('review.none_given')}</p>
                        ) : (
                            comments.map((c) => (
                                <ReviewCard key={c.id} review={c}>
                                    {c.hidden && <p className="text-xs text-danger">{t('review.hidden_note')}</p>}
                                    {c.editable &&
                                        (editing === c.id ? (
                                            <CommentForm
                                                action={'/buy/comments/' + c.id}
                                                method="put"
                                                maxLength={maxLength}
                                                initial={{ rating: c.rating, score_delivery: c.scores.delivery, score_service: c.scores.service, score_description: c.scores.description, body: c.body ?? '' }}
                                                onDone={() => setEditing(null)}
                                            />
                                        ) : (
                                            <button type="button" className="text-xs text-primary" onClick={() => setEditing(c.id)}>
                                                {t('review.edit')}
                                            </button>
                                        ))}
                                </ReviewCard>
                            ))
                        ))}
                </div>
            </section>
        </DashboardLayout>
    );
}

function CommentForm({ action, method, maxLength, initial, onDone }: { action: string; method: 'post' | 'put'; maxLength: number; initial?: FormData; onDone: () => void }) {
    const t = useT();
    const { errors } = useShared();
    const form = useForm<FormData>(initial ?? { rating: 'positive', score_delivery: null, score_service: null, score_description: null, body: '' });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form[method](action, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="mt-3 space-y-3">
            <fieldset className="flex flex-wrap gap-2">
                <legend className="mb-1 text-sm font-medium">{t('review.your_rating')}</legend>
                {(['positive', 'neutral', 'negative'] as const).map((r) => (
                    <label key={r} className={'cursor-pointer rounded-full border px-3 py-1 text-sm font-semibold ' + (form.data.rating === r ? RATING_STYLE[r] + ' border-current' : 'border-line')}>
                        <input type="radio" name="rating" className="sr-only" checked={form.data.rating === r} onChange={() => form.setData('rating', r)} />
                        {t('review.rating.' + r)}
                    </label>
                ))}
            </fieldset>
            <div className="grid gap-2 sm:grid-cols-3">
                {(['score_delivery', 'score_service', 'score_description'] as const).map((k) => (
                    <label key={k} className="grid gap-1 text-sm">
                        <span className="font-medium">{t('review.score.' + k.replace('score_', ''))}</span>
                        <select className="field" value={form.data[k] ?? ''} onChange={(e) => form.setData(k, e.target.value === '' ? null : Number(e.target.value))}>
                            <option value="">—</option>
                            {[5, 4, 3, 2, 1].map((n) => (
                                <option key={n} value={n}>
                                    {'★'.repeat(n)}
                                </option>
                            ))}
                        </select>
                    </label>
                ))}
            </div>
            <label className="grid gap-1 text-sm">
                <span className="font-medium">{t('review.text')}</span>
                <textarea className="field min-h-24" maxLength={maxLength} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} placeholder={t('review.text_help')} />
            </label>
            {errors.comment && <p className="text-sm text-danger">{errors.comment}</p>}
            <div className="flex gap-2">
                <button type="submit" className="btn-primary" disabled={form.processing}>
                    {t('review.submit')}
                </button>
                <button type="button" className="btn-outline-pill" onClick={onDone}>
                    {t('common.cancel')}
                </button>
            </div>
        </form>
    );
}
