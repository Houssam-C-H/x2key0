import { Head, Link } from '@inertiajs/react';
import { Header } from '@/Components/Header';
import { Icon } from '@/Components/Icon';
import { useShared, useT } from '@/lib/i18n';
import { isBranch, type MenuNode } from '@/types';

/**
 * Dashboard home (spec 1.7): no sidebar. Hero with balances (real values from the
 * ledger in M7/M9), then every menu group as a card.
 */
export default function DashboardIndex() {
    const t = useT();
    const { auth, dir, locale, menu } = useShared();
    const user = auth.user;

    return (
        <div dir={dir} lang={locale} className="min-h-screen bg-canvas">
            <Head title={t('dashboard.title')} />
            <Header />

            <section className="bg-gradient-to-r from-primary to-surface-dark-2 text-white">
                <div className="mx-auto grid max-w-7xl gap-6 px-4 py-8 md:grid-cols-3 md:items-center">
                    <div className="flex items-center gap-4">
                        <span className="flex h-14 w-14 items-center justify-center rounded-full bg-white/20 text-2xl font-bold uppercase">{user?.name.charAt(0)}</span>
                        <div>
                            <p className="text-lg font-bold">{user?.name}</p>
                            <p className="text-sm text-white/75">
                                {t('dashboard.credits_balance')}: <span className="font-semibold">—</span> · {t('dashboard.score')}: <span className="font-semibold">—</span>
                            </p>
                        </div>
                    </div>
                    <div className="text-center">
                        <p className="text-3xl font-extrabold">USD —</p>
                        <p className="text-sm text-white/75">{t('dashboard.available_balance')}</p>
                    </div>
                    <div className="grid grid-cols-2 gap-3 text-center">
                        <div className="rounded-lg bg-white/10 p-3">
                            <p className="text-xl font-bold">—</p>
                            <p className="text-xs text-white/75">{t('dashboard.success_rate')}</p>
                        </div>
                        <div className="rounded-lg bg-white/10 p-3">
                            <p className="text-xl font-bold">—</p>
                            <p className="text-xs text-white/75">{t('dashboard.in_processing')}</p>
                        </div>
                    </div>
                </div>
            </section>

            <div className="mx-auto grid max-w-7xl gap-4 px-4 py-8 sm:grid-cols-2 lg:grid-cols-4">
                {(menu?.groups ?? []).map((group) => (
                    <section key={group.key} className="card p-4">
                        <h2 className="mb-3 flex items-center gap-2 font-bold">
                            <Icon name={group.icon} size={18} className="text-primary" />
                            {group.label}
                        </h2>
                        <ul className="space-y-1.5 text-sm">
                            {flatten(group.children).map((leaf) => (
                                <li key={leaf.key}>
                                    <Link href={leaf.href} className="text-muted hover:text-primary">
                                        {leaf.label}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                ))}
            </div>
        </div>
    );
}

function flatten(nodes: MenuNode[]): Extract<MenuNode, { href: string }>[] {
    return nodes.flatMap((n) => (isBranch(n) ? flatten(n.children) : [n]));
}
