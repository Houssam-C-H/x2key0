import { Logo } from '@/Components/Brand/Logo';
import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { useLocalePath, useShared, useT } from '@/lib/i18n';
import { AccountMenu } from './AccountMenu';
import { CategorySelect } from './CategorySelect';
import { Icon } from './Icon';
import { LocaleModal } from './LocaleModal';
import { MegaMenu } from './MegaMenu';

/** Global header (spec 1.1): logo + menu, search with category select, locale pill, auth actions. */
export function Header() {
    const t = useT();
    const lp = useLocalePath();
    const { auth, locale, currency, app, inbox, chat } = useShared();
    const [menuOpen, setMenuOpen] = useState(false);
    const [localeOpen, setLocaleOpen] = useState(false);

    return (
        <header className="sticky top-0 z-30 bg-surface-dark text-white">
            <div className="relative">
                <div className="mx-auto flex h-16 max-w-7xl items-center gap-3 px-4">
                    <Link href={lp('/')} aria-label={app.name} className="shrink-0">
                        <span className="hidden sm:inline-flex">
                            <Logo tone="dark" size={30} label={app.name} />
                        </span>
                        <span className="sm:hidden">
                            <Logo tone="dark" size={30} markOnly label={app.name} />
                        </span>
                    </Link>

                    <button
                        type="button"
                        className={
                            'flex items-center gap-2 rounded-xl px-2.5 py-2 text-sm font-semibold transition lg:px-3.5 ' +
                            (menuOpen ? 'bg-white text-ink' : 'bg-white/5 ring-1 ring-white/10 hover:bg-white/10')
                        }
                        aria-label={t('header.menu')}
                        aria-expanded={menuOpen}
                        onClick={() => setMenuOpen((v) => !v)}
                    >
                        <Icon name={menuOpen ? 'x' : 'grid'} size={18} />
                        <span className="hidden lg:inline">{t('header.browse')}</span>
                    </button>

                    <form
                        action={`/${locale}/search`}
                        method="get"
                        role="search"
                        className="mx-1 hidden h-11 flex-1 items-stretch rounded-xl bg-white p-1 ring-2 ring-transparent transition focus-within:ring-brand-lavender md:flex"
                    >
                        <Icon name="search" size={18} className="ms-2.5 shrink-0 self-center text-muted" />
                        <input
                            name="q"
                            type="search"
                            placeholder={t('header.search_short')}
                            aria-label={t('header.search')}
                            className="min-w-0 flex-1 bg-transparent px-2.5 text-sm text-ink outline-none placeholder:text-muted"
                        />
                        <CategorySelect />
                        <button type="submit" className="ms-1 rounded-lg bg-primary px-4 text-sm font-semibold text-white hover:bg-primary-hover">
                            {t('header.search')}
                        </button>
                    </form>

                    <div className="ms-auto flex items-center gap-2">
                        <Link href={lp('/search')} className="rounded-md p-2 hover:bg-white/10 md:hidden" aria-label={t('header.search')}>
                            <Icon name="search" />
                        </Link>
                        <button
                            type="button"
                            onClick={() => setLocaleOpen(true)}
                            className="flex items-center gap-1.5 rounded-full px-2.5 py-1.5 text-sm text-white/85 ring-1 ring-white/15 hover:bg-white/10 hover:text-white"
                        >
                            <Icon name="globe" size={16} />
                            <span className="uppercase">{locale}</span>
                            <span className="opacity-50">|</span>
                            <span>{currency}</span>
                        </button>

                        {auth.user ? (
                            <>
                                <Link href="/chat" className="relative rounded-md p-2 hover:bg-white/10" aria-label={t('header.chat')}>
                                    <Icon name="chat" />
                                    {chat && chat.unread > 0 && (
                                        <span className="absolute -end-0.5 -top-0.5 min-w-[1.1rem] rounded-full bg-success px-1 text-center text-[10px] font-bold leading-[1.1rem] text-white" aria-label={t('chat.unread_count', { count: chat.unread })}>
                                            {chat.unread > 99 ? '99+' : chat.unread}
                                        </span>
                                    )}
                                </Link>
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
                                <Link href={lp('/register')} className="hidden items-center gap-1.5 rounded-full px-3.5 py-1.5 text-sm font-semibold text-white/85 hover:text-white xl:inline-flex">
                                    <Icon name="store" size={16} />
                                    {t('header.become_seller')}
                                </Link>
                                <Link href={lp('/login')} className="whitespace-nowrap rounded-full bg-gradient-to-r from-primary to-brand-magenta px-4 py-2 text-sm font-semibold shadow-lg shadow-primary/30 hover:brightness-110">
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
