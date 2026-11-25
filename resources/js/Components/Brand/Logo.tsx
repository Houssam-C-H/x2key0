import paths from './logo-paths.json';

/**
 * X2KEY logo (docs/BRAND.md): the official mark + wordmark, vectorised from resources/brand-src by
 * scripts/trace-logo.py. Inline SVG: no request, crisp at any size, CSP-safe.
 *
 * - `tone="light"` (default): on white — black ink, red accents.
 * - `tone="dark"`: on dark surfaces — white ink, red accents.
 * - `markOnly`: square spaces (avatars, small mobile header, auth card).
 * `size` is the logo height in px.
 */
const INK = { light: 'var(--color-ink)', dark: '#ffffff' } as const;

export function LogoMark({ size = 28, tone = 'light', className = '' }: { size?: number; tone?: 'light' | 'dark'; className?: string }) {
    const d = paths.mark;
    return (
        <svg width={size} height={size} viewBox={`0 0 ${d.w} ${d.h}`} aria-hidden="true" className={'shrink-0 ' + className}>
            <path fill={INK[tone]} fillRule="evenodd" d={d.ink} />
            <path fill="var(--color-brand-red)" fillRule="evenodd" d={d.red} />
        </svg>
    );
}

export function Logo({ tone = 'light', size = 28, markOnly = false, label = 'X2KEY' }: { tone?: 'dark' | 'light'; size?: number; markOnly?: boolean; label?: string }) {
    if (markOnly) {
        return (
            <span className="inline-flex" aria-label={label} role="img">
                <LogoMark size={size} tone={tone} />
            </span>
        );
    }
    const d = paths.full;
    return (
        <svg width={Math.round((size * d.w) / d.h)} height={size} viewBox={`0 0 ${d.w} ${d.h}`} role="img" aria-label={label} className="shrink-0">
            <path fill={INK[tone]} fillRule="evenodd" d={d.ink} />
            <path fill="var(--color-brand-red)" fillRule="evenodd" d={d.red} />
        </svg>
    );
}
