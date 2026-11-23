import { router } from '@inertiajs/react';
import { ItemTable, type StorageItemRow } from '@/Components/Storage/ItemTable';
import { DashboardLayout } from '@/Layouts/DashboardLayout';
import { useT } from '@/lib/i18n';
import type { Paged } from '@/types/offer';

interface Props {
    items: Paged<StorageItemRow>;
    groups: { id: number; name: string; type: string }[];
    filters: { group: number | null; status: string | null };
    readOnly: boolean;
}

/** Recycle Station (spec 1.7 / 5.5): restore recycled items to stock, or delete them for good. */
export default function StorageRecycle({ items, groups, filters, readOnly }: Props) {
    const t = useT();

    const visit = (group: number | null, page = 1) =>
        router.get('/seller/storage/recycle', { group: group ?? undefined, page: page > 1 ? page : undefined }, { preserveState: true, preserveScroll: true, replace: true });

    return (
        <DashboardLayout title={t('menu.storage.recycle')}>
            <p className="mb-4 text-sm text-muted">{t('storage.recycle.help')}</p>
            <label className="mb-4 flex max-w-xs flex-col gap-1 text-sm">
                <span className="font-medium">{t('storage.group.name')}</span>
                <select className="field" value={filters.group ?? ''} onChange={(e) => visit(e.target.value === '' ? null : Number(e.target.value))}>
                    <option value="">{t('storage.items.all_groups')}</option>
                    {groups.map((g) => (
                        <option key={g.id} value={g.id}>
                            {g.name}
                        </option>
                    ))}
                </select>
            </label>
            <ItemTable items={items} operations={['restore', 'purge']} onPage={(p) => visit(filters.group, p)} readOnly={readOnly} />
        </DashboardLayout>
    );
}
