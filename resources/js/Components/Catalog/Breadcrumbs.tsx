import { Link } from '@inertiajs/react';
import type { Crumb } from '@/types/catalog';

export function Breadcrumbs({ items }: { items: Crumb[] }) {
    return (
        <nav aria-label="Breadcrumb" className="text-sm text-muted">
            <ol className="flex flex-wrap items-center gap-1.5">
                {items.map((c, i) => (
                    <li key={c.href} className="flex items-center gap-1.5">
                        {i > 0 && (
                            <span aria-hidden="true" className="rtl:-scale-x-100">
                                ›
                            </span>
                        )}
                        {i === items.length - 1 ? (
                            <span aria-current="page" className="text-ink">
                                {c.name}
                            </span>
                        ) : (
                            <Link href={c.href} className="hover:text-primary">
                                {c.name}
                            </Link>
                        )}
                    </li>
                ))}
            </ol>
        </nav>
    );
}
