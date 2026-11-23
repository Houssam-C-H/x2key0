import { Link, router } from '@inertiajs/react';
import { Pagination } from '@/Components/Catalog/Pagination';
import { DashboardLayout } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';
import type { Paged } from '@/types/offer';

interface Log {
    id: number;
    order: string;
    orderHref: string | null;
    group: string | null;
    item: number | null;
    result: string;
    error: string | null;
    at: string;
}

/** Storage › Delivery Logs (spec 1.7 / 5.5): what auto delivery sent from Storage, and failures. */
export default function StorageLogs({ logs }: { logs: Paged<Log> }) {
    const t = useT();
    const { locale } = useShared();

    return (
        <DashboardLayout title={t('menu.storage.logs')}>
            {logs.items.length === 0 ? (
                <p className="py-10 text-center text-sm text-muted">{t('storage.logs.empty')}</p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[640px] text-sm">
                        <thead className="text-xs uppercase text-muted">
                            <tr>
                                <th className="py-2 text-start">{t('storage.import.date')}</th>
                                <th className="py-2 text-start">{t('order.col.order')}</th>
                                <th className="py-2 text-start">{t('storage.group.name')}</th>
                                <th className="py-2 text-start">#</th>
                                <th className="py-2 text-start">{t('offer.col.status')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {logs.items.map((l) => (
                                <tr key={l.id} className="border-t border-line">
                                    <td className="py-2 text-muted">{new Date(l.at).toLocaleString(locale)}</td>
                                    <td className="py-2">
                                        {l.orderHref ? (
                                            <Link href={l.orderHref} className="font-semibold text-primary hover:underline">
                                                {l.order}
                                            </Link>
                                        ) : (
                                            l.order
                                        )}
                                    </td>
                                    <td className="py-2">{l.group ?? '—'}</td>
                                    <td className="py-2 text-muted">{l.item ?? '—'}</td>
                                    <td className="py-2">
                                        <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${l.result === 'success' ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger'}`}>{t(`storage.logs.${l.result}`)}</span>
                                        {l.error && <p className="text-xs text-danger">{l.error}</p>}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            <div className="mt-4">
                <Pagination page={logs.page} lastPage={logs.lastPage} onPage={(p) => router.get('/seller/storage/logs', { page: p }, { preserveScroll: true })} />
            </div>
        </DashboardLayout>
    );
}
