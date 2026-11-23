import { Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { useShared, useT } from '@/lib/i18n';
import type { AuthUser } from '@/types';
import { Icon } from './Icon';

/** Avatar button + dropdown (spec 1.1 account dropdown). Balances join in M9. */
export function AccountMenu({ user }: { user: AuthUser }) {
    const t = useT();
    const { locale } = useShared();
    const [open, setOpen] = useState(false);
    const ref = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;
        const close = (e: MouseEvent | KeyboardEvent) => {
            if (e instanceof KeyboardEvent ? e.key === 'Escape' : !ref.current?.contains(e.target as Node)) setOpen(false);
        };
        document.addEventListener('mousedown', close);
        document.addEventListener('keydown', close);
        return () => {
            document.removeEventListener('mousedown', close);
            document.removeEventListener('keydown', close);
        };
    }, [open]);

    const links = [
        { href: '/dashboard', label: t('header.dashboard') },
        { href: '/buy/orders', label: t('menu.buy.orders') },
        user.seller?.opensSellerCenter
            ? { href: '/seller/store', label: t('menu.seller') }
            : { href: '/seller/onboarding', label: t('menu.seller.onboarding') },
        { href: '/settings/account', label: t('menu.settings.account') },
        { href: '/settings/security', label: t('menu.settings.security') },
        { href: '/settings/certification', label: t('menu.settings.certification') },
    ];

    return (
        <div ref={ref} className="relative">
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                aria-expanded={open}
                aria-haspopup="menu"
                aria-label={t('header.account')}
                className="relative flex h-9 w-9 items-center justify-center rounded-full bg-primary font-bold uppercase"
            >
                {user.name.charAt(0)}
                <span className="absolute -bottom-0.5 -end-0.5 h-3 w-3 rounded-full border-2 border-surface-dark bg-success" />
            </button>

            {open && (
                <div role="menu" className="card absolute end-0 top-full z-50 mt-2 w-64 overflow-hidden text-ink">
                    <div className="border-b border-line p-4">
                        <p className="font-bold">{user.name}</p>
                        <p className="text-xs text-muted">@{user.username}</p>
                        <p className="mt-1 text-xs text-muted">
                            {t('account.id')}: <span className="font-mono">{user.publicId.slice(-10)}</span>
                        </p>
                    </div>
                    <ul className="py-1">
                        {links.map((l) => (
                            <li key={l.href}>
                                <Link href={l.href} role="menuitem" className="block px-4 py-2 hover:bg-canvas" onClick={() => setOpen(false)}>
                                    {l.label}
                                </Link>
                            </li>
                        ))}
                        {user.isStaff && (
                            <li>
                                <a href="/admin" role="menuitem" className="block px-4 py-2 font-semibold text-primary hover:bg-canvas">
                                    {t('header.admin')}
                                </a>
                            </li>
                        )}
                    </ul>
                    <button
                        type="button"
                        role="menuitem"
                        onClick={() => router.post('/logout', {}, { onFinish: () => router.visit(`/${locale}`) })}
                        className="flex w-full items-center justify-center gap-2 bg-primary py-2.5 font-semibold text-white hover:bg-primary-hover"
                    >
                        <Icon name="x" size={16} />
                        {t('header.logout')}
                    </button>
                </div>
            )}
        </div>
    );
}
