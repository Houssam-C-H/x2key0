import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { TextArea } from '@/Components/Field';
import { DashboardLayout, Panel } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';

interface Row {
    id: string;
    rule: string;
    code: string;
    penalty: string;
    points: number;
    details: string | null;
    order: { number: string; href: string } | null;
    status: 'open' | 'appealed' | 'upheld' | 'revoked';
    canAppeal: boolean;
    appealDueAt: string;
    appealText: string | null;
    decisionNote: string | null;
    expiresAt: string;
    createdAt: string;
}

interface Props {
    counts: { open: number; appealed: number; processed: number };
    activePoints: number;
    thresholds: { suspend: number; ban: number };
    filters: { status: string | null; rule: string | null };
    rules: { code: string; name: string }[];
    rows: Row[];
}

const STATUS_STYLE: Record<Row['status'], string> = {
    open: 'bg-warning/15 text-warning',
    appealed: 'bg-primary-soft text-primary',
    upheld: 'bg-danger/10 text-danger',
    revoked: 'bg-success/10 text-success',
};

/** Violation Center (spec 1.8 / 17.2): counters, filters, active points vs thresholds, appeal. */
export default function Violations({ counts, activePoints, thresholds, filters, rules, rows }: Props) {
    const t = useT();
    const { locale, errors } = useShared();
    const [appealing, setAppealing] = useState<string | null>(null);
    const appeal = useForm({ text: '' });
    const go = (params: Record<string, string | null>) =>
        router.get('/seller/violations', Object.fromEntries(Object.entries({ ...filters, ...params }).filter(([, v]) => v !== null && v !== '')), { preserveScroll: true });

    return (
        <DashboardLayout title={t('menu.seller.violations')} bare>
            <Panel title={t('violation.summary')}>
                <dl className="grid gap-4 text-sm sm:grid-cols-4">
                    <Stat label={t('violation.counter.open')} value={counts.open} />
                    <Stat label={t('violation.counter.appealed')} value={counts.appealed} />
                    <Stat label={t('violation.counter.processed')} value={counts.processed} />
                    <div>
                        <dt className="text-xs text-muted">{t('violation.active_points')}</dt>
                        <dd className={`text-xl font-bold ${activePoints >= thresholds.suspend ? 'text-danger' : ''}`}>{activePoints}</dd>
                        <dd className="text-xs text-muted">{t('violation.thresholds', { suspend: thresholds.suspend, ban: thresholds.ban })}</dd>
                    </div>
                </dl>
            </Panel>

            <Panel title={t('violation.list')}>
                <div className="mb-4 flex flex-wrap gap-3">
                    <select className="field w-56" aria-label={t('violation.filter.rule')} value={filters.rule ?? ''} onChange={(e) => go({ rule: e.target.value || null })}>
                        <option value="">{t('violation.filter.all_rules')}</option>
                        {rules.map((r) => (
                            <option key={r.code} value={r.code}>
                                {r.name}
                            </option>
                        ))}
                    </select>
                    <select className="field w-48" aria-label={t('violation.filter.status')} value={filters.status ?? ''} onChange={(e) => go({ status: e.target.value || null })}>
                        <option value="">{t('violation.filter.all_statuses')}</option>
                        {(['open', 'appealed', 'upheld', 'revoked'] as const).map((s) => (
                            <option key={s} value={s}>
                                {t(`violation.status.${s}`)}
                            </option>
                        ))}
                    </select>
                </div>
                {errors.appeal && <p className="mb-3 rounded-md bg-danger/5 p-3 text-sm text-danger">{errors.appeal}</p>}
                {rows.length === 0 ? (
                    <p className="py-8 text-center text-sm text-muted">{t('violation.none')}</p>
                ) : (
                    <ul className="divide-y divide-line">
                        {rows.map((v) => (
                            <li key={v.id} className="py-4">
                                <div className="flex flex-wrap items-center gap-3">
                                    <span className="font-semibold">{v.rule}</span>
                                    <span className="font-mono text-xs text-muted">#{v.id.slice(-8).toUpperCase()}</span>
                                    <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${STATUS_STYLE[v.status]}`}>{t(`violation.status.${v.status}`)}</span>
                                    <span className="text-sm">{t('violation.points', { count: v.points })}</span>
                                    <span className="text-sm text-muted">{t(`violation.penalty.${v.penalty}`)}</span>
                                    <span className="ms-auto text-xs text-muted">{new Date(v.createdAt).toLocaleDateString(locale)}</span>
                                </div>
                                {v.details && <p className="mt-1 text-sm text-muted">{v.details}</p>}
                                {v.order && (
                                    <p className="mt-1 text-sm">
                                        {t('dispute.col.order')}:{' '}
                                        <Link href={v.order.href} className="text-primary hover:underline">
                                            {v.order.number}
                                        </Link>
                                    </p>
                                )}
                                {v.appealText && <p className="mt-1 text-sm">{t('violation.your_appeal', { text: v.appealText })}</p>}
                                {v.decisionNote && v.status !== 'open' && <p className="mt-1 text-sm text-muted">{t('violation.decision', { note: v.decisionNote })}</p>}
                                <p className="mt-1 text-xs text-muted">{t('violation.expires', { date: new Date(v.expiresAt).toLocaleDateString(locale) })}</p>
                                {v.canAppeal &&
                                    (appealing === v.id ? (
                                        <form
                                            className="mt-3 space-y-2"
                                            onSubmit={(e) => {
                                                e.preventDefault();
                                                appeal.post(`/seller/violations/${v.id}/appeal`, { preserveScroll: true, onSuccess: () => setAppealing(null) });
                                            }}
                                        >
                                            <TextArea label={t('violation.appeal_text')} name={`appeal-${v.id}`} value={appeal.data.text} onChange={(x) => appeal.setData('text', x)} error={appeal.errors.text} rows={3} />
                                            <button type="submit" className="btn-primary" disabled={appeal.processing}>
                                                {t('violation.appeal_submit')}
                                            </button>
                                        </form>
                                    ) : (
                                        <button type="button" className="btn-outline-pill mt-3 px-3 py-1" onClick={() => setAppealing(v.id)}>
                                            {t('violation.appeal', { date: new Date(v.appealDueAt).toLocaleDateString(locale) })}
                                        </button>
                                    ))}
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>
        </DashboardLayout>
    );
}

function Stat({ label, value }: { label: string; value: number }) {
    return (
        <div>
            <dt className="text-xs text-muted">{label}</dt>
            <dd className="text-xl font-bold">{value}</dd>
        </div>
    );
}
