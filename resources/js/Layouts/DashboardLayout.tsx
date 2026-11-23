import { Head, Link } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';
import { Flash } from '@/Components/Flash';
import { Header } from '@/Components/Header';
import { Icon } from '@/Components/Icon';
import { Sidebar } from '@/Components/Sidebar';
import { useShared, useT } from '@/lib/i18n';

interface Props {
    title: string;
    children: ReactNode;
    /** Render children without the white card (for pages made of several cards). */
    bare?: boolean;
}

/**
 * Dashboard shell (spec 1.7 / 21.1): header, 280px sidebar (drawer below lg),
 * breadcrumb, content card, floating right rail (Home · Announcement · Chat).
 * The `menu` prop is shared by the server on every dashboard page.
 */
export function DashboardLayout({ title, children, bare = false }: Props) {
    const t = useT();
    const { dir, locale, menu } = useShared();
    const [drawer, setDrawer] = useState(false);
    const groups = menu?.groups ?? [];

    return (
        <div dir={dir} lang={locale} className="min-h-screen bg-canvas">
            <Head title={title} />
            <Header />

            <div className="mx-auto flex max-w-7xl gap-6 px-4 py-6">
                <aside className="hidden w-[280px] shrink-0 lg:block">
                    <Sidebar groups={groups} />
                </aside>

                {drawer && (
                    <div className="fixed inset-0 z-40 lg:hidden" role="dialog" aria-modal="true">
                        <button type="button" className="absolute inset-0 bg-black/40" aria-label="Close" onClick={() => setDrawer(false)} />
                        <div className="absolute inset-y-0 start-0 w-[300px] max-w-[85vw] overflow-y-auto bg-canvas p-3">
                            <Sidebar groups={groups} />
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
                        {menu?.active?.group && (
                            <>
                                <span>/</span>
                                <span>{menu.active.group}</span>
                            </>
                        )}
                        <span>/</span>
                        <span className="text-ink">{title}</span>
                    </div>

                    <Flash />

                    {bare ? (
                        <div className="space-y-6">
                            <h1 className="text-lg font-bold">{title}</h1>
                            {children}
                        </div>
                    ) : (
                        <section className="card p-6">
                            <h1 className="mb-4 text-lg font-bold">{title}</h1>
                            {children}
                        </section>
                    )}
                </div>
            </div>

            <FloatingRail />
        </div>
    );
}

/** White card section used inside bare dashboard pages. */
export function Panel({ title, description, children, id }: { title: string; description?: string; children: ReactNode; id?: string }) {
    return (
        <section id={id} className="card p-6" aria-labelledby={id ? `${id}-title` : undefined}>
            <h2 id={id ? `${id}-title` : undefined} className="text-base font-bold">
                {title}
            </h2>
            {description && <p className="mt-1 text-sm text-muted">{description}</p>}
            <div className="mt-4">{children}</div>
        </section>
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
