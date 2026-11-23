import { router } from '@inertiajs/react';
import { ItemTable, type StorageItemRow } from '@/Components/Storage/ItemTable';
import { DashboardLayout } from '@/Layouts/DashboardLayout';
import { useT } from '@/lib/i18n';
import type { Paged } from '@/types/offer';

interface Props {
    items: Paged<StorageItemRow>;
    groups: { id: number; name: string; type: string }[];
    filters: { group: number | null; status: string | null };
    statuses: string[];
    readOnly: boolean;
}

/** Storage List (spec 1.7): items per group and status, masked; reveal, mark invalid, move to the Recycle Station. */
export default function StorageItems({ items, groups, filters, statuses, readOnly }: Props) {
    const t = useT();

    const visit = (patch: Partial<Props['filters']>, page = 1) => {
        const next = { ...filters, ...patch };
        router.get('/seller/storage/items', { group: next.group ?? undefined, status: next.status ?? undefined, page: page > 1 ? page : undefined }, { preserveState: true, preserveScroll: true, replace: true });
    };

    return (
        <DashboardLayout title={t('menu.storage.items')}>
            <div className="mb-4 flex flex-wrap gap-3">
                <label className="flex flex-col gap-1 text-sm">
                    <span className="font-medium">{t('storage.group.name')}</span>
                    <select className="field" value={filters.group ?? ''} onChange={(e) => visit({ group: e.target.value === '' ? null : Number(e.target.value) })}>
                        <option value="">{t('storage.items.all_groups')}</option>
                        {groups.map((g) => (
                            <option key={g.id} value={g.id}>
                                {g.name}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="flex flex-col gap-1 text-sm">
                    <span className="font-medium">{t('offer.col.status')}</span>
                    <select className="field" value={filters.status ?? ''} onChange={(e) => visit({ status: e.target.value === '' ? null : e.target.value })}>
                        <option value="">{t('storage.items.all_statuses')}</option>
                        {statuses.map((s) => (
                            <option key={s} value={s}>
                                {t(`storage.status.${s}`)}
                            </option>
                        ))}
                    </select>
                </label>
                <p className="ms-auto self-end text-sm text-muted">{t('storage.items.total', { count: items.total })}</p>
            </div>
            <ItemTable items={items} operations={['invalid', 'recycle']} onPage={(p) => visit({}, p)} readOnly={readOnly} />
        </DashboardLayout>
    );
}
