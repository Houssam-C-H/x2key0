import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { DashboardLayout } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';

interface Item {
    id: string;
    kind: 'notification' | 'announcement';
    title: string;
    body: string;
    category: string;
    href: string | null;
    read: boolean;
    starred: boolean;
    at: string;
}

interface Props {
    items: Item[];
    filter: string;
    category: string | null;
    categories: string[];
    unread: number;
}

/** Announcement centre (spec 12.2): All · Unread · Read · Starred, category chips, read/star, mark all read. */
export default function Announcements({ items, filter, category, categories, unread }: Props) {
    const t = useT();
    const { locale } = useShared();
    const [open, setOpen] = useState<string | null>(null);

    const go = (next: { filter?: string; category?: string | null }) =>
        router.get('/announcements', { filter: (next.filter ?? filter) === 'all' ? undefined : (next.filter ?? filter), category: (next.category === undefined ? category : next.category) ?? undefined }, { preserveScroll: true, preserveState: true });
    const toggleRead = (i: Item) => router.post(`/announcements/${i.id}/read`, { read: !i.read }, { preserveScroll: true });

    return (
        <DashboardLayout title={t('menu.announcements')} bare>
            <section className="card p-0">
                <div className="flex flex-wrap items-center gap-2 border-b border-line p-3">
                    {(['all', 'unread', 'read', 'starred'] as const).map((f) => (
                        <button
                            key={f}
                            type="button"
                            aria-pressed={filter === f}
                            onClick={() => go({ filter: f })}
                            className={`rounded-full border px-3 py-1 text-sm font-semibold ${filter === f ? 'border-primary bg-primary-soft text-primary' : 'border-line hover:border-primary'}`}
                        >
                            {t(`announcements.filter.${f}`)}
                            {f === 'unread' && unread > 0 ? ` (${unread})` : ''}
                        </button>
                    ))}
                    <button type="button" className="btn-outline-pill ms-auto px-3 py-1 text-sm" disabled={unread === 0} onClick={() => router.post('/announcements/read-all', {}, { preserveScroll: true })}>
                        {t('announcements.read_all')}
                    </button>
                </div>
                <div className="flex flex-wrap gap-2 border-b border-line p-3">
                    <button type="button" onClick={() => go({ category: null })} className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ${category === null ? 'bg-ink text-white' : 'bg-canvas'}`}>
                        {t('announcements.category.all')}
                    </button>
                    {categories.map((c) => (
                        <button key={c} type="button" onClick={() => go({ category: c })} className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ${category === c ? 'bg-ink text-white' : 'bg-canvas'}`}>
                            {t(`announcements.category.${c}`)}
                        </button>
                    ))}
                </div>

                {items.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 py-14 text-center">
                        <span className="flex h-14 w-14 items-center justify-center rounded-full bg-canvas text-2xl font-bold text-muted">!</span>
                        <p className="font-semibold">{t('announcements.empty')}</p>
                    </div>
                ) : (
                    <ul>
                        {items.map((i) => (
                            <li key={i.id} className={`flex gap-3 border-b border-line p-4 last:border-b-0 ${i.read ? '' : 'bg-primary-soft/40'}`}>
                                <button type="button" aria-label={t(i.starred ? 'announcements.unstar' : 'announcements.star')} title={t(i.starred ? 'announcements.unstar' : 'announcements.star')} onClick={() => router.post(`/announcements/${i.id}/star`, {}, { preserveScroll: true })} className={`text-lg ${i.starred ? 'text-warning' : 'text-muted'}`}>
                                    {i.starred ? '★' : '☆'}
                                </button>
                                <div className="min-w-0 flex-1">
                                    <button
                                        type="button"
                                        className="block w-full text-start"
                                        onClick={() => {
                                            setOpen(open === i.id ? null : i.id);
                                            if (!i.read) toggleRead(i);
                                        }}
                                    >
                                        <span className={`${i.read ? 'font-medium' : 'font-bold'}`}>{i.title}</span>
                                        <span className="ms-2 rounded bg-canvas px-1.5 py-0.5 text-[11px] text-muted">{t(`announcements.category.${i.category}`)}</span>
                                    </button>
                                    <p className="text-xs text-muted">{new Date(i.at).toLocaleString(locale)}</p>
                                    {(open === i.id || !i.read) && <p className="mt-1 whitespace-pre-line text-sm">{i.body}</p>}
                                    {open === i.id && i.href && (
                                        <Link href={i.href} className="mt-1 inline-block text-sm font-semibold text-primary hover:underline">
                                            {t('announcements.open')}
                                        </Link>
                                    )}
                                </div>
                                <button type="button" className="self-start text-xs text-muted hover:text-ink" onClick={() => toggleRead(i)}>
                                    {t(i.read ? 'announcements.mark_unread' : 'announcements.mark_read')}
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </DashboardLayout>
    );
}
