import { Link } from '@inertiajs/react';
import { useShared, useT } from '@/lib/i18n';
import { formatCount } from '@/lib/money';
import type { GameCard } from '@/types/catalog';

/** Game with its category links and counts (Game Index / search, spec 1.4.4). */
export function GameTile({ game }: { game: GameCard }) {
    const t = useT();
    const { locale } = useShared();

    return (
        <article className="card flex flex-col gap-3 p-4">
            <div className="flex items-center gap-3">
                <span aria-hidden="true" className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-primary to-surface-dark-2 font-display text-lg font-bold text-white">
                    {game.name.charAt(0)}
                </span>
                <div className="min-w-0">
                    <Link href={game.href} className="block truncate font-bold hover:text-primary">
                        {game.name}
                    </Link>
                    <p className="text-xs text-muted">
                        {t('catalog.listings', { count: formatCount(game.count, locale) })}
                        {game.hot && <span className="ms-2 rounded bg-danger/10 px-1.5 text-danger">HOT</span>}
                        {game.isNew && <span className="ms-2 rounded bg-success/10 px-1.5 text-success">{t('catalog.new')}</span>}
                    </p>
                </div>
            </div>
            <ul className="flex flex-wrap gap-1.5">
                {game.categories.map((c) => (
                    <li key={c.code}>
                        <Link href={c.href} className="inline-flex items-center gap-1 rounded-full bg-canvas px-2.5 py-1 text-xs hover:bg-primary-soft hover:text-primary">
                            {c.label}
                            <span className="text-muted">{formatCount(c.count, locale)}</span>
                        </Link>
                    </li>
                ))}
            </ul>
        </article>
    );
}
