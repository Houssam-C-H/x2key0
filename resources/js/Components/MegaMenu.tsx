import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { GameArt } from '@/Components/Home/HomeParts';
import { useLocalePath, useShared, useT } from '@/lib/i18n';
import { categoryIcon, Icon } from './Icon';

interface Props {
    open: boolean;
    onClose: () => void;
}

/**
 * Mega menu (spec 1.1): category rail on the start side, the selected category's top games (art, name, offers)
 * on the other, quick links and a sell card at the bottom. Data is shared by the server (cached per locale).
 */
export function MegaMenu({ open, onClose }: Props) {
    const t = useT();
    const lp = useLocalePath();
    const { megaMenu, auth } = useShared();
    const columns = [...megaMenu.filter((c) => c.games.length > 0), ...megaMenu.filter((c) => c.games.length === 0)];
    const [active, setActive] = useState<string | null>(null);

    useEffect(() => {
        if (!open) return;
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [open, onClose]);

    if (!open) return null;

    const current = columns.find((c) => c.code === active) ?? columns[0];

    return (
        <>
            <div className="fixed inset-0 top-[4.25rem] z-30 bg-surface-dark/60 backdrop-blur-sm" onClick={onClose} aria-hidden="true" />
            <div className="absolute inset-x-0 top-full z-40 px-0 md:px-4" role="dialog" aria-label={t('megamenu.title')}>
                <div className="mx-auto max-h-[calc(100vh-5rem)] max-w-7xl overflow-y-auto bg-white text-ink shadow-2xl md:mt-2 md:rounded-2xl">
                    {columns.length === 0 || !current ? (
                        <p className="p-8 text-sm text-muted">{t('megamenu.coming')}</p>
                    ) : (
                        <div className="md:grid md:grid-cols-[17rem_1fr]">
                            {/* Category rail: chips on phones, a vertical list from md up. */}
                            <nav aria-label={t('megamenu.title')} className="border-b border-line bg-canvas/60 md:border-b-0 md:border-e">
                                <p className="hidden px-5 pt-5 pb-2 text-xs font-semibold uppercase tracking-wider text-muted md:block">{t('megamenu.title')}</p>
                                <ul className="no-scrollbar flex gap-2 overflow-x-auto p-3 md:flex-col md:gap-0.5 md:overflow-visible md:p-3 md:pt-0">
                                    {columns.map((c) => {
                                        const on = c.code === current.code;
                                        return (
                                            <li key={c.code} className="shrink-0">
                                                <button
                                                    type="button"
                                                    onMouseEnter={() => setActive(c.code)}
                                                    onFocus={() => setActive(c.code)}
                                                    onClick={() => setActive(c.code)}
                                                    aria-current={on ? 'true' : undefined}
                                                    className={
                                                        'flex w-full items-center gap-3 whitespace-nowrap rounded-xl px-3 py-1.5 text-start text-sm font-semibold transition ' +
                                                        (on ? 'bg-white text-primary shadow-sm ring-1 ring-line' : 'text-ink/80 hover:bg-white hover:text-ink')
                                                    }
                                                >
                                                    <span className={'flex h-7 w-7 shrink-0 items-center justify-center rounded-lg transition ' + (on ? 'bg-gradient-to-br from-primary to-brand-deep text-white' : 'bg-white text-primary ring-1 ring-line')}>
                                                        <Icon name={categoryIcon[c.code] ?? 'box'} size={17} />
                                                    </span>
                                                    <span className="flex-1">{c.label}</span>
                                                    <Icon name="chevron" size={14} className={'hidden shrink-0 rtl:rotate-180 md:block ' + (on ? 'opacity-100' : 'opacity-0')} />
                                                </button>
                                            </li>
                                        );
                                    })}
                                </ul>
                            </nav>

                            <div className="flex min-w-0 flex-col p-5 md:p-6">
                                <div className="mb-4 flex items-center justify-between gap-3">
                                    <h2 className="font-display text-lg font-extrabold">{t('megamenu.popular_in', { label: current.label })}</h2>
                                    <div className="flex items-center gap-2">
                                        <Link href={current.href} onClick={onClose} className="inline-flex items-center gap-1 text-sm font-semibold text-primary hover:text-primary-hover">
                                            {t('megamenu.view_all_in', { label: current.label })}
                                            <Icon name="arrow" size={15} className="rtl:rotate-180" />
                                        </Link>
                                        <button type="button" className="ms-2 rounded-lg p-1.5 text-muted hover:bg-canvas hover:text-ink" onClick={onClose} aria-label={t('locale.cancel')}>
                                            <Icon name="x" size={18} />
                                        </button>
                                    </div>
                                </div>

                                {current.games.length === 0 ? (
                                    <p className="rounded-xl border border-dashed border-line p-8 text-center text-sm text-muted">{t('megamenu.empty')}</p>
                                ) : (
                                    <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                        {current.games.map((g, i) => (
                                            <li key={g.href}>
                                                <Link href={g.href} onClick={onClose} className="group flex items-center gap-3 rounded-xl p-2 transition hover:bg-canvas">
                                                    <span className="h-11 w-11 shrink-0 overflow-hidden rounded-lg">
                                                        <GameArt name={g.name} compact />
                                                    </span>
                                                    <span className="min-w-0 flex-1">
                                                        <span className="flex items-center gap-1.5 truncate text-sm font-semibold group-hover:text-primary">
                                                            <span className="truncate">{g.name}</span>
                                                            {i < 3 && g.count > 0 && <Icon name="fire" size={14} className="shrink-0 text-brand-deep" />}
                                                        </span>
                                                        {g.count > 0 && <span className="block text-xs text-muted">{t('home.offers', { count: g.count })}</span>}
                                                    </span>
                                                </Link>
                                            </li>
                                        ))}
                                    </ul>
                                )}

                                <div className="mt-6 flex flex-col gap-4 border-t border-line pt-5 lg:flex-row lg:items-center">
                                    <div className="flex flex-wrap gap-2 text-sm font-medium">
                                        <Link href={lp('/games')} onClick={onClose} className="inline-flex items-center gap-1.5 rounded-full border border-line px-3 py-1.5 hover:border-primary hover:text-primary">
                                            <Icon name="grid" size={15} />
                                            {t('megamenu.game_index')}
                                        </Link>
                                        <a href={lp('/page/how-to-buy')} className="inline-flex items-center gap-1.5 rounded-full border border-line px-3 py-1.5 hover:border-primary hover:text-primary">
                                            <Icon name="shield" size={15} />
                                            {t('megamenu.how_to_buy')}
                                        </a>
                                    </div>
                                    <Link
                                        href={auth.user ? '/seller/onboarding' : lp('/register')}
                                        onClick={onClose}
                                        className="group flex items-center gap-3 rounded-xl bg-surface-dark px-4 py-3 text-white lg:ms-auto"
                                    >
                                        <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-gradient-to-br from-primary to-brand-deep">
                                            <Icon name="store" size={18} />
                                        </span>
                                        <span>
                                            <span className="block text-sm font-bold">{t('megamenu.sell_title')}</span>
                                            <span className="block text-xs text-white/70">{t('megamenu.sell_body')}</span>
                                        </span>
                                        <Icon name="arrow" size={16} className="ms-2 transition group-hover:translate-x-0.5 rtl:rotate-180" />
                                    </Link>
                                </div>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}
