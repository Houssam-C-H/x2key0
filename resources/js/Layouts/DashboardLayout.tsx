import { Link } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';
import { Header } from '@/Components/Header';
import { Icon } from '@/Components/Icon';
import { Sidebar } from '@/Components/Sidebar';
import { useShared, useT } from '@/lib/i18n';
import type { DashboardMenu } from '@/types';

interface Props {
    menu: DashboardMenu;
    title: string;
    children: ReactNode;
}

/**
 * Dashboard shell (spec 1.7 / 21.1): header, 280px sidebar (drawer below lg),
 * breadcrumb, white content card, floating right rail (Home · Announcement · Chat).
 */
export function DashboardLayout({ menu, title, children }: Props) {
    const t = useT();
    const { dir, locale } = useShared();
    const [drawer, setDrawer] = useState(false);

    return (
        <div dir={dir} lang={locale} className="min-h-screen bg-canvas">
            <Header />

            <div className="mx-auto flex max-w-7xl gap-6 px-4 py-6">
                <aside className="hidden w-[280px] shrink-0 lg:block">
                    <Sidebar groups={menu.groups} />
                </aside>

                {drawer && (
                    <div className="fixed inset-0 z-40 lg:hidden" role="dialog" aria-modal="true">
                        <button type="button" className="absolute inset-0 bg-black/40" aria-label="Close" onClick={() => setDrawer(false)} />
                        <div className="absolute inset-y-0 start-0 w-[300px] max-w-[85vw] overflow-y-auto bg-canvas p-3">
                            <Sidebar groups={menu.groups} />
                        </div>
                    </div>
                )}

                <div className="min-w-0 flex-1">
                    <div className="mb-4 flex items-center gap-2 text-sm text-muted">
                        <button type="button" className="btn-ghost p-1.5 lg:hidden" onClick={() => setDrawer(true)} aria-label={t('header.menu')}>
                            <Icon name="menu" size={18} />
                        </button>
                        <Link href="/dashboard" className="flex items-center gap-1 hover:text-primary">
                            <Icon name="home" size={16} />
                            {t('dashboard.home')}
                        </Link>
                        {menu.active?.group && (
                            <>
                                <span>/</span>
                                <span>{menu.active.group}</span>
                            </>
                        )}
                        <span>/</span>
                        <span className="text-ink">{title}</span>
                    </div>

                    <section className="card p-6">
                        <h1 className="mb-4 text-lg font-bold">{title}</h1>
                        {children}
                    </section>
                </div>
            </div>

            <FloatingRail />
        </div>
    );
}

function FloatingRail() {
    const t = useT();
    const { locale } = useShared();
    const items = [
        { href: `/${locale}`, icon: 'home', label: t('dashboard.home') },
        { href: '/announcements', icon: 'bell', label: t('header.announcements') },
        { href: '/chat', icon: 'chat', label: t('header.chat') },
    ];

    return (
        <nav aria-label="Quick links" className="fixed end-3 top-1/2 z-20 hidden -translate-y-1/2 flex-col gap-1 rounded-full bg-white p-1.5 shadow-lg xl:flex">
            {items.map((i) => (
                <a key={i.icon} href={i.href} title={i.label} aria-label={i.label} className="rounded-full p-2 text-muted hover:bg-primary-soft hover:text-primary">
                    <Icon name={i.icon} size={18} />
                </a>
            ))}
        </nav>
    );
}
