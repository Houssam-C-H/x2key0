/**
 * X2KEY logo (docs/BRAND.md): the key-X mark and the wordmark. The mark is inline SVG (no request, scales crisply,
 * CSP-safe); the wordmark is live text in the display font so it stays sharp and translatable-free.
 *
 * - `tone="dark"`: on dark backgrounds (header, footer) — white wordmark, lavender "2".
 * - `tone="light"`: on white — ink wordmark, violet "2".
 * - `markOnly`: square spaces (app icon, avatars, small mobile header).
 */
export function LogoMark({ size = 28, className = '' }: { size?: number; className?: string }) {
    return (
        <svg width={size} height={size} viewBox="0 0 64 64" aria-hidden="true" className={'shrink-0 ' + className}>
            <defs>
                <linearGradient id="x2k-mark-gradient" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0" stopColor="#5b3df5" />
                    <stop offset="1" stopColor="#db2777" />
                </linearGradient>
            </defs>
            <rect width="64" height="64" rx="14" fill="url(#x2k-mark-gradient)" />
            <g fill="none" stroke="#fff" strokeLinecap="round" strokeLinejoin="round">
                <circle cx="22" cy="22" r="7" strokeWidth="5" />
                <path d="M27 27 45 45" strokeWidth="6" />
                <path d="M39 39 43.5 34.5M43.5 43.5 48 39" strokeWidth="5" />
                <path d="M45 19 19 45" strokeWidth="6" />
            </g>
        </svg>
    );
}

export function Logo({ tone = 'dark', size = 28, markOnly = false, label = 'X2KEY' }: { tone?: 'dark' | 'light'; size?: number; markOnly?: boolean; label?: string }) {
    return (
        <span className="inline-flex items-center gap-2" aria-label={label} role="img">
            <LogoMark size={size} />
            {!markOnly && (
                <span aria-hidden="true" className={'font-display font-extrabold tracking-tight ' + (tone === 'dark' ? 'text-white' : 'text-ink')} style={{ fontSize: Math.round(size * 0.72) }}>
                    X<span className={tone === 'dark' ? 'text-brand-lavender' : 'text-primary'}>2</span>KEY
                </span>
            )}
        </span>
    );
}
