import { router } from '@inertiajs/react';
import { useShared, useT } from '@/lib/i18n';

/** Heart toggle (spec Part 20 favorites toggle). Guests are sent to sign in. */
export function FavoriteButton({ type, id, active, className = '' }: { type: 'offer' | 'product'; id: string; active: boolean; className?: string }) {
    const t = useT();
    const { auth, locale } = useShared();
    const label = t(active ? 'favorites.remove' : 'favorites.add');

    const toggle = () => {
        if (!auth.user) {
            router.visit(`/${locale}/login`);

            return;
        }
        router.post('/favorites', { type, id }, { preserveScroll: true, preserveState: true });
    };

    return (
        <button type="button" onClick={toggle} aria-pressed={active} aria-label={label} title={label} className={`rounded-full border border-line px-3 py-1.5 text-sm font-semibold hover:border-primary ${active ? 'text-danger' : 'text-muted'} ${className}`}>
            <span aria-hidden="true">{active ? '♥' : '♡'}</span> <span className="hidden sm:inline">{label}</span>
        </button>
    );
}
