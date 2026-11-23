import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { useLocalePath, useShared, useT } from '@/lib/i18n';
import { AccountMenu } from './AccountMenu';
import { Icon } from './Icon';
import { LocaleModal } from './LocaleModal';
import { MegaMenu } from './MegaMenu';

/** Global header (spec 1.1): logo + menu, search with category select, locale pill, auth actions. */
export function Header() {
    const t = useT();
    const lp = useLocalePath();
    const { auth, locale, currency, categoryTypes, app, inbox } = useShared();
    const [menuOpen, setMenuOpen] = useState(false);
    const [localeOpen, setLocaleOpen] = useState(false);

    return (
        <header className="sticky top-0 z-30 bg-surface-dark text-white">
            <div className="relative">
                <div className="mx-auto flex h-16 max-w-7xl items-center gap-3 px-4">
                    <button
                        type="button"
                        className="rounded-md p-2 hover:bg-white/10"
                        aria-label={t('header.menu')}
                        aria-expanded={menuOpen}
                        onClick={() => setMenuOpen((v) => !v)}
                    >
                        <Icon name="menu" />
                    </button>

                    <Link href={lp('/')} className="font-display text-xl font-extrabold tracking-tight">
                        {app.name}
                    </Link>

                    <form action={`/${locale}/search`} method="get" className="mx-2 hidden flex-1 md:flex" role="search">
                        <input
                            name="q"
                            type="search"
                            placeholder={t('header.search_placeholder')}
                            aria-label={t('header.search')}
                            className="h-10 min-w-0 flex-1 rounded-s-md bg-white px-3 text-ink outline-none"
                        />
                        <select name="type" aria-label={t('header.all_categories')} className="h-10 max-w-44 border-s border-line bg-white px-2 text-ink">
                            <option value="">{t('header.all_categories')}</option>
                            {categoryTypes.map((c) => (
                                <option key={c.code} value={c.code}>
                                    {c.label}
                                </option>
                            ))}
                        </select>
                        <button type="submit" className="flex h-10 w-11 items-center justify-center rounded-e-md bg-primary hover:bg-primary-hover" aria-label={t('header.search')}>
                            <Icon name="search" />
                        </button>
                    </form>

                    <div className="ms-auto flex items-center gap-2">
                        <button
                            type="button"
                            onClick={() => setLocaleOpen(true)}
                            className="flex items-center gap-1.5 rounded-full border border-white/25 px-3 py-1.5 text-sm hover:bg-white/10"
                        >
                            <Icon name="globe" size={16} />
                            <span className="uppercase">{locale}</span>
                            <span className="opacity-50">|</span>
                            <span>{currency}</span>
                        </button>

                        {auth.user ? (
                            <>
                                <a href="/chat" className="rounded-md p-2 hover:bg-white/10" aria-label={t('header.chat')}>
                                    <Icon name="chat" />
                                </a>
                                <Link href="/announcements" className="relative rounded-md p-2 hover:bg-white/10" aria-label={t('header.announcements')}>
                                    <Icon name="bell" />
                                    {inbox && inbox.unread > 0 && (
                                        <span className="absolute -end-0.5 -top-0.5 min-w-[1.1rem] rounded-full bg-danger px-1 text-center text-[10px] font-bold leading-[1.1rem] text-white" aria-label={t('announcements.unread_count', { count: inbox.unread })}>
                                            {inbox.unread > 99 ? '99+' : inbox.unread}
                                        </span>
                                    )}
                                </Link>
                                <AccountMenu user={auth.user} />
                            </>
                        ) : (
                            <>
                                <Link href={lp('/register')} className="hidden rounded-full border border-white/60 px-4 py-1.5 text-sm font-semibold hover:bg-white/10 sm:inline-flex">
                                    {t('header.become_seller')}
                                </Link>
                                <Link href={lp('/login')} className="rounded-full bg-primary px-4 py-1.5 text-sm font-semibold hover:bg-primary-hover">
                                    {t('header.login_register')}
                                </Link>
                            </>
                        )}
                    </div>
                </div>

                <div className="text-ink">
                    <MegaMenu open={menuOpen} onClose={() => setMenuOpen(false)} />
                </div>
            </div>
            <LocaleModal open={localeOpen} onClose={() => setLocaleOpen(false)} />
        </header>
    );
}
