import { useT } from '@/lib/i18n';

export function Pagination({ page, lastPage, onPage }: { page: number; lastPage: number; onPage: (page: number) => void }) {
    const t = useT();

    if (lastPage <= 1) return null;

    const pages = Array.from({ length: lastPage }, (_, i) => i + 1).filter((p) => p === 1 || p === lastPage || Math.abs(p - page) <= 2);

    return (
        <nav aria-label={t('catalog.pagination')} className="flex flex-wrap items-center justify-center gap-1">
            <button type="button" className="btn-ghost px-3" disabled={page <= 1} onClick={() => onPage(page - 1)}>
                <span className="rtl:-scale-x-100">‹</span>
            </button>
            {pages.map((p, i) => (
                <span key={p} className="flex items-center gap-1">
                    {i > 0 && pages[i - 1] !== p - 1 && <span className="px-1 text-muted">…</span>}
                    <button
                        type="button"
                        aria-current={p === page ? 'page' : undefined}
                        className={`h-9 min-w-9 rounded-md px-2 text-sm font-semibold ${p === page ? 'bg-primary text-white' : 'hover:bg-canvas'}`}
                        onClick={() => onPage(p)}
                    >
                        {p}
                    </button>
                </span>
            ))}
            <button type="button" className="btn-ghost px-3" disabled={page >= lastPage} onClick={() => onPage(page + 1)}>
                <span className="rtl:-scale-x-100">›</span>
            </button>
        </nav>
    );
}
