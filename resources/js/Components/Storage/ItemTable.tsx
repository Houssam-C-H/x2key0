import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Pagination } from '@/Components/Catalog/Pagination';
import { useShared, useT } from '@/lib/i18n';
import { xsrfToken } from '@/lib/offer';
import type { Paged } from '@/types/offer';

export interface StorageItemRow {
    id: number;
    group: string;
    status: string;
    flagged: boolean;
    revealable: boolean;
    batch: number | null;
    createdAt: string;
    recycledAt: string | null;
}

interface Secret {
    type: string;
    value: string | null;
    account: Record<string, string | null> | null;
}

const STATUS_STYLE: Record<string, string> = {
    available: 'bg-success/10 text-success',
    reserved: 'bg-warning/15 text-warning',
    assigned: 'bg-canvas text-muted',
    revoked: 'bg-danger/10 text-danger',
    invalid: 'bg-danger/10 text-danger',
    recycled: 'bg-canvas text-muted',
};

/**
 * Storage item list with masked secrets. "Reveal" calls a JSON endpoint (audit-logged, never cached) and keeps
 * the secret only in this component's memory until "Hide".
 */
export function ItemTable({ items, operations, onPage, readOnly }: { items: Paged<StorageItemRow>; operations: string[]; onPage: (page: number) => void; readOnly: boolean }) {
    const t = useT();
    const { locale, errors } = useShared();
    const [selected, setSelected] = useState<number[]>([]);
    const [secrets, setSecrets] = useState<Record<number, Secret>>({});
    const [revealError, setRevealError] = useState<string | null>(null);
    const [confirm, setConfirm] = useState<string | null>(null);

    const reveal = async (id: number) => {
        setRevealError(null);
        const response = await fetch(`/seller/storage/items/${id}/reveal`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrfToken() },
        });
        if (response.status === 409 || response.redirected) {
            window.location.href = `/security/privacy-verify?return=${encodeURIComponent(window.location.pathname + window.location.search)}`;

            return;
        }
        const body = (await response.json()) as Secret & { message?: string };
        if (!response.ok) {
            setRevealError(body.message ?? t('storage.error.reveal'));

            return;
        }
        setSecrets((s) => ({ ...s, [id]: body }));
    };

    const run = (operation: string) => {
        router.post('/seller/storage/items/manage', { operation, ids: selected }, { preserveScroll: true, onSuccess: () => setSelected([]) });
        setConfirm(null);
    };

    const all = items.items.length > 0 && items.items.every((i) => selected.includes(i.id));

    return (
        <div className="space-y-4">
            {!readOnly && operations.length > 0 && (
                <div className="flex flex-wrap items-center gap-2 rounded-md bg-canvas p-2 text-sm">
                    <span className="px-1 font-semibold">{t('offer.bulk.selected', { count: selected.length })}</span>
                    {operations.map((op) =>
                        op === 'purge' && confirm === 'purge' ? (
                            <span key={op} className="flex items-center gap-1">
                                <button type="button" className="rounded-full bg-danger px-3 py-1 font-semibold text-white" onClick={() => run('purge')}>
                                    {t('storage.items.confirm_purge', { count: selected.length })}
                                </button>
                                <button type="button" className="btn-ghost px-2 py-1" onClick={() => setConfirm(null)}>
                                    {t('common.cancel')}
                                </button>
                            </span>
                        ) : (
                            <button key={op} type="button" className={`btn-outline-pill px-3 py-1 ${op === 'purge' ? 'text-danger' : ''}`} disabled={selected.length === 0} onClick={() => (op === 'purge' ? setConfirm('purge') : run(op))}>
                                {t(`storage.items.op.${op}`)}
                            </button>
                        ),
                    )}
                </div>
            )}
            {(errors.items || revealError) && <p className="text-sm text-danger">{errors.items ?? revealError}</p>}

            {items.items.length === 0 ? (
                <div className="flex flex-col items-center gap-3 py-12 text-center">
                    <span className="flex h-14 w-14 items-center justify-center rounded-full bg-canvas text-2xl font-bold text-muted">!</span>
                    <p className="font-semibold">{t('common.no_results')}</p>
                </div>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[720px] text-sm">
                        <thead className="text-xs uppercase text-muted">
                            <tr>
                                <th className="w-8 py-2">
                                    <input type="checkbox" aria-label={t('offer.bulk.select_all')} checked={all} onChange={(e) => setSelected(e.target.checked ? items.items.map((i) => i.id) : [])} />
                                </th>
                                <th className="py-2 text-start">#</th>
                                <th className="py-2 text-start">{t('storage.group.name')}</th>
                                <th className="py-2 text-start">{t('storage.items.secret')}</th>
                                <th className="py-2 text-start">{t('offer.col.status')}</th>
                                <th className="py-2 text-start">{t('storage.items.added')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {items.items.map((i) => {
                                const secret = secrets[i.id];

                                return (
                                    <tr key={i.id} className="border-t border-line align-top">
                                        <td className="py-2.5">
                                            <input type="checkbox" aria-label={`#${i.id}`} checked={selected.includes(i.id)} onChange={(e) => setSelected(e.target.checked ? [...selected, i.id] : selected.filter((x) => x !== i.id))} />
                                        </td>
                                        <td className="py-2.5 text-muted">{i.id}</td>
                                        <td className="py-2.5">{i.group}</td>
                                        <td className="py-2.5">
                                            {secret ? (
                                                <div className="space-y-1">
                                                    {secret.value !== null ? (
                                                        <code className="block break-all rounded bg-canvas px-2 py-1 font-mono text-xs">{secret.value}</code>
                                                    ) : (
                                                        <dl className="grid grid-cols-[auto_1fr] gap-x-3 rounded bg-canvas px-2 py-1 font-mono text-xs">
                                                            {Object.entries(secret.account ?? {})
                                                                .filter(([, v]) => v)
                                                                .map(([k, v]) => (
                                                                    <div key={k} className="contents">
                                                                        <dt className="text-muted">{t(`storage.account.${k}`)}</dt>
                                                                        <dd className="break-all">{v}</dd>
                                                                    </div>
                                                                ))}
                                                        </dl>
                                                    )}
                                                    <button type="button" className="text-xs text-muted hover:text-ink" onClick={() => setSecrets(({ [i.id]: _, ...rest }) => rest)}>
                                                        {t('storage.items.hide')}
                                                    </button>
                                                </div>
                                            ) : (
                                                <span className="flex items-center gap-3">
                                                    <span className="font-mono tracking-widest text-muted">••••••••</span>
                                                    {i.revealable && (
                                                        <button type="button" className="text-xs font-semibold text-primary hover:underline" onClick={() => void reveal(i.id)}>
                                                            {t('storage.items.reveal')}
                                                        </button>
                                                    )}
                                                </span>
                                            )}
                                            {i.flagged && <p className="text-xs font-semibold text-danger">{t('storage.items.flagged')}</p>}
                                        </td>
                                        <td className="py-2.5">
                                            <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${STATUS_STYLE[i.status] ?? ''}`}>{t(`storage.status.${i.status}`)}</span>
                                        </td>
                                        <td className="py-2.5 text-muted">{new Date(i.recycledAt ?? i.createdAt).toLocaleString(locale)}</td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}

            <Pagination page={items.page} lastPage={items.lastPage} onPage={onPage} />
        </div>
    );
}
