import { Link } from '@inertiajs/react';
import { useLocalePath, useShared, useT } from '@/lib/i18n';
import { categoryIcon, Icon } from './Icon';

interface Props {
    open: boolean;
    onClose: () => void;
}

/**
 * Mega menu (spec 1.1): one column per category type with its top games.
 * Games come from the catalog in M3; until then each column shows its category only.
 */
export function MegaMenu({ open, onClose }: Props) {
    const t = useT();
    const lp = useLocalePath();
    const { categoryTypes } = useShared();

    if (!open) return null;

    return (
        <div className="absolute inset-x-0 top-full z-40 border-t border-line bg-white shadow-lg" role="dialog" aria-label={t('megamenu.title')}>
            <div className="mx-auto max-w-7xl p-6">
                <div className="mb-4 flex items-center justify-between">
                    <h2 className="text-base font-bold">{t('megamenu.title')}</h2>
                    <button type="button" className="btn-ghost p-1" onClick={onClose} aria-label="Close">
                        <Icon name="x" />
                    </button>
                </div>
                <ul className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
                    {categoryTypes.map((c) => (
                        <li key={c.code}>
                            <span className="flex items-center gap-2 rounded-md p-2 font-semibold hover:bg-canvas">
                                <Icon name={categoryIcon[c.code] ?? 'box'} className="text-primary" size={18} />
                                {c.label}
                            </span>
                        </li>
                    ))}
                </ul>
                <p className="mt-4 text-sm text-muted">{t('megamenu.coming')}</p>
                <div className="mt-4 flex flex-wrap gap-4 border-t border-line pt-4 text-sm font-medium">
                    <Link href={lp('/')} className="text-primary hover:underline">
                        {t('megamenu.game_index')}
                    </Link>
                    <Link href={lp('/')} className="text-primary hover:underline">
                        {t('megamenu.how_to_buy')}
                    </Link>
                    <Link href={lp('/')} className="text-primary hover:underline">
                        {t('megamenu.sell')}
                    </Link>
                </div>
            </div>
        </div>
    );
}
