import { Link, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { DashboardLayout, Panel } from '@/Layouts/DashboardLayout';
import { useT } from '@/lib/i18n';

interface Application {
    id: number;
    gameCategoryId: number;
    title: string;
    href: string;
    status: 'pending' | 'passed' | 'refused';
    rejectReason: string | null;
    createdAt: string;
    auditedAt: string | null;
}

interface GameOption {
    id: number;
    name: string;
    categories: { id: number; label: string; applied: boolean }[];
}

interface Props {
    applications: Application[];
    games: GameOption[];
    autoApprove: boolean;
}

const STATUS_STYLE: Record<Application['status'], string> = {
    pending: 'bg-warning/15 text-warning',
    passed: 'bg-success/10 text-success',
    refused: 'bg-danger/10 text-danger',
};

/** Service Application Status (spec 1.8 / 6.9): apply per game×category, see decisions, reapply. */
export default function Services({ applications, games, autoApprove }: Props) {
    const t = useT();
    const [gameId, setGameId] = useState<string>('');
    const form = useForm({ game_category_id: '' });
    const categories = useMemo(() => games.find((g) => String(g.id) === gameId)?.categories ?? [], [games, gameId]);

    const reapply = (gameCategoryId: number) => router.post('/seller/services', { game_category_id: gameCategoryId }, { preserveScroll: true });

    return (
        <DashboardLayout title={t('menu.seller.store.services')} bare>
            <Panel title={t('seller.services.apply')} description={t(autoApprove ? 'seller.services.auto_help' : 'seller.services.help')}>
                <form
                    className="grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/seller/services', { preserveScroll: true, onSuccess: () => form.reset() });
                    }}
                >
                    <label className="flex flex-col gap-1">
                        <span className="font-medium">{t('seller.services.game')}</span>
                        <select
                            className="field"
                            value={gameId}
                            onChange={(e) => {
                                setGameId(e.target.value);
                                form.setData('game_category_id', '');
                            }}
                        >
                            <option value="">{t('seller.services.choose_game')}</option>
                            {games.map((g) => (
                                <option key={g.id} value={g.id}>
                                    {g.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="flex flex-col gap-1">
                        <span className="font-medium">{t('seller.services.category')}</span>
                        <select className="field" value={form.data.game_category_id} disabled={categories.length === 0} onChange={(e) => form.setData('game_category_id', e.target.value)}>
                            <option value="">{t('seller.services.choose_category')}</option>
                            {categories.map((c) => (
                                <option key={c.id} value={c.id} disabled={c.applied}>
                                    {c.label}
                                    {c.applied ? ` — ${t('seller.services.already')}` : ''}
                                </option>
                            ))}
                        </select>
                    </label>
                    <button type="submit" className="btn-primary" disabled={form.processing || form.data.game_category_id === ''}>
                        {t('seller.services.submit')}
                    </button>
                    {form.errors.game_category_id && <p className="text-sm text-danger sm:col-span-3">{form.errors.game_category_id}</p>}
                </form>
            </Panel>

            <Panel title={t('seller.services.list')}>
                {applications.length === 0 ? (
                    <p className="py-6 text-center text-sm text-muted">{t('common.no_results')}</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="text-xs uppercase text-muted">
                                <tr>
                                    <th className="py-2 text-start">{t('seller.services.name')}</th>
                                    <th className="py-2 text-start">{t('seller.services.status')}</th>
                                    <th className="py-2 text-start">{t('seller.services.notice')}</th>
                                    <th className="py-2 text-start">{t('seller.services.created')}</th>
                                    <th className="py-2 text-start">{t('seller.services.audited')}</th>
                                    <th className="py-2 text-start">{t('seller.services.action')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {applications.map((a) => (
                                    <tr key={a.id} className="border-t border-line align-top">
                                        <td className="py-2 font-semibold">
                                            <a href={a.href} className="hover:text-primary">
                                                {a.title}
                                            </a>
                                        </td>
                                        <td className="py-2">
                                            <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${STATUS_STYLE[a.status]}`}>{t(`seller.services.status.${a.status}`)}</span>
                                        </td>
                                        <td className="py-2 text-danger">{a.rejectReason ?? ''}</td>
                                        <td className="py-2 text-muted">{new Date(a.createdAt).toLocaleDateString()}</td>
                                        <td className="py-2 text-muted">{a.auditedAt ? new Date(a.auditedAt).toLocaleDateString() : '—'}</td>
                                        <td className="py-2">
                                            {a.status === 'refused' && (
                                                <button type="button" className="btn-outline-pill" disabled={form.processing} onClick={() => reapply(a.gameCategoryId)}>
                                                    {t('seller.services.reapply')}
                                                </button>
                                            )}
                                            {a.status === 'passed' && (
                                                <Link href="/seller/offers/create" className="btn-outline-pill">
                                                    {t('seller.services.publish')}
                                                </Link>
                                            )}
                                        </td>
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
