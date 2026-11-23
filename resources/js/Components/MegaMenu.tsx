import { Link } from '@inertiajs/react';
import { useLocalePath, useShared, useT } from '@/lib/i18n';
import { categoryIcon, Icon } from './Icon';

interface Props {
    open: boolean;
    onClose: () => void;
}

/**
 * Mega menu (spec 1.1): one column per category type with its top games and counts, "View all"
 * to the category hub. Data is shared by the server (cached per locale).
 */
export function MegaMenu({ open, onClose }: Props) {
    const t = useT();
    const lp = useLocalePath();
    const { megaMenu } = useShared();

    if (!open) return null;

    const columns = megaMenu.filter((c) => c.games.length > 0);

    return (
        <div className="absolute inset-x-0 top-full z-40 max-h-[80vh] overflow-y-auto border-t border-line bg-white shadow-lg" role="dialog" aria-label={t('megamenu.title')}>
            <div className="mx-auto max-w-7xl p-6">
                <div className="mb-4 flex items-center justify-between">
                    <h2 className="text-base font-bold">{t('megamenu.title')}</h2>
                    <button type="button" className="btn-ghost p-1" onClick={onClose} aria-label={t('locale.cancel')}>
                        <Icon name="x" />
                    </button>
                </div>

                {columns.length === 0 ? (
                    <p className="text-sm text-muted">{t('megamenu.coming')}</p>
                ) : (
                    <div className="grid grid-cols-2 gap-6 sm:grid-cols-3 lg:grid-cols-5">
                        {columns.map((col) => (
                            <section key={col.code}>
                                <h3 className="mb-2 flex items-center gap-2 font-bold">
                                    <Icon name={categoryIcon[col.code] ?? 'box'} className="text-primary" size={18} />
                                    {col.label}
                                </h3>
                                <ul className="space-y-1 text-sm">
                                    {col.games.map((g) => (
                                        <li key={g.href}>
                                            <Link href={g.href} onClick={onClose} className="flex justify-between gap-2 text-ink/80 hover:text-primary">
                                                <span className="truncate">{g.name}</span>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                                <Link href={col.href} onClick={onClose} className="mt-2 inline-block text-xs font-semibold text-primary hover:underline">
                                    {t('megamenu.view_all')}
                                </Link>
                            </section>
                        ))}
                    </div>
                )}

                <div className="mt-6 flex flex-wrap gap-4 border-t border-line pt-4 text-sm font-medium">
                    <Link href={lp('/games')} onClick={onClose} className="text-primary hover:underline">
                        {t('megamenu.game_index')}
                    </Link>
                    <a href={lp('/page/how-to-buy')} className="text-primary hover:underline">
                        {t('megamenu.how_to_buy')}
                    </a>
                    <Link href={lp('/register')} onClick={onClose} className="text-primary hover:underline">
                        {t('megamenu.sell')}
                    </Link>
                </div>
            </div>
        </div>
    );
}
