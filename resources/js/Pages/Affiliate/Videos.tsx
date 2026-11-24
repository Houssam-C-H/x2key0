import { useForm } from '@inertiajs/react';
import { DashboardLayout, Panel } from '@/Layouts/DashboardLayout';
import { formatUsd } from '@/lib/money';
import { useShared, useT } from '@/lib/i18n';

interface VideoRow {
    id: number;
    platform: string;
    url: string;
    views: number;
    status: 'submitted' | 'approved' | 'rejected';
    reward: number;
    note: string | null;
    createdAt: string;
}

const STYLE: Record<VideoRow['status'], string> = {
    submitted: 'bg-warning-soft text-ink',
    approved: 'bg-success/10 text-success',
    rejected: 'bg-danger/10 text-danger',
};

/** Promotional video records (spec Part 15): submit a video link; staff review it and set the bonus. */
export default function AffiliateVideos({ records }: { records: VideoRow[] }) {
    const t = useT();
    const { locale, errors } = useShared();
    const form = useForm({ url: '', views: '' });

    return (
        <DashboardLayout title={t('menu.affiliate.videos')} bare>
            <Panel title={t('affiliate.videos.submit')} description={t('affiliate.videos.help')}>
                <form
                    className="flex flex-wrap gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/affiliate/videos', { preserveScroll: true, onSuccess: () => form.reset() });
                    }}
                >
                    <input className="field min-w-64 flex-1" type="url" value={form.data.url} onChange={(e) => form.setData('url', e.target.value)} placeholder="https://www.youtube.com/watch?v=…" aria-label={t('affiliate.videos.link')} />
                    <input className="field w-32" type="number" min={0} value={form.data.views} onChange={(e) => form.setData('views', e.target.value)} placeholder={t('affiliate.videos.views')} aria-label={t('affiliate.videos.views')} />
                    <button type="submit" className="btn-primary" disabled={form.processing || form.data.url.trim() === ''}>
                        {t('affiliate.videos.send')}
                    </button>
                </form>
                {errors.url && <p className="mt-1 text-sm text-danger">{errors.url}</p>}
            </Panel>
            <Panel title={t('affiliate.videos.records')}>
                {records.length === 0 ? (
                    <p className="text-sm text-muted">{t('affiliate.videos.empty')}</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-canvas text-xs text-muted">
                                <tr>
                                    <th className="px-3 py-2 text-start">{t('affiliate.videos.link')}</th>
                                    <th className="px-3 py-2 text-end">{t('affiliate.videos.views')}</th>
                                    <th className="px-3 py-2 text-end">{t('affiliate.videos.bonus')}</th>
                                    <th className="px-3 py-2 text-start">{t('coupon.col.status')}</th>
                                    <th className="px-3 py-2 text-start">{t('marketing.col.created')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {records.map((r) => (
                                    <tr key={r.id}>
                                        <td className="max-w-xs truncate px-3 py-2">
                                            <a href={r.url} target="_blank" rel="noopener noreferrer nofollow" className="text-primary">
                                                {r.url}
                                            </a>
                                            <span className="block text-xs text-muted">{r.platform}</span>
                                        </td>
                                        <td className="px-3 py-2 text-end">{r.views.toLocaleString(locale)}</td>
                                        <td className="px-3 py-2 text-end">{r.status === 'approved' ? formatUsd(r.reward, locale) : '—'}</td>
                                        <td className="px-3 py-2">
                                            <span className={'rounded-full px-2 py-0.5 text-xs font-semibold ' + STYLE[r.status]}>{t('affiliate.videos.status.' + r.status)}</span>
                                            {r.note && <span className="block text-xs text-muted">{r.note}</span>}
                                        </td>
                                        <td className="px-3 py-2">{new Date(r.createdAt).toLocaleDateString(locale)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Panel>
        </DashboardLayout>
    );
}
