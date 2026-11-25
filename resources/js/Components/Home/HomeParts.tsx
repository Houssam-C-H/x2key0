import { Link } from '@inertiajs/react';
import { type ReactNode, useRef } from 'react';
import { categoryIcon, Icon } from '@/Components/Icon';
import { useT } from '@/lib/i18n';

/** Stable number from a string (same game → same colours on every page). */
function hash(text: string): number {
    let h = 0;
    for (let i = 0; i < text.length; i++) {
        h = (h * 31 + text.charCodeAt(i)) | 0;
    }
    return Math.abs(h);
}

/** "Genshin Impact" → "GI", "Fortnite" → "FO". */
function monogram(name: string): string {
    const words = name.replace(/[^\p{L}\p{N} ]/gu, '').split(/\s+/).filter(Boolean);
    const [first = '?', second] = words;
    return (second ? first.charAt(0) + second.charAt(0) : first.slice(0, 2)).toUpperCase();
}

/**
 * Cover art for a game: the licensed image when there is one, otherwise a generated tile (brand guidelines §6):
 * a gradient picked from the game's name, a large monogram and the category icon — never an empty box.
 */
export function GameArt({ name, image, category, compact = false, className = '' }: { name: string; image?: string | null; category?: string; compact?: boolean; className?: string }) {
    if (image) {
        return <img src={image} alt="" loading="lazy" className={'h-full w-full object-cover ' + className} />;
    }
    const hue = hash(name) % 360;
    const style = { backgroundImage: `radial-gradient(120% 90% at 100% 0%, hsl(${(hue + 50) % 360} 85% 60% / .55), transparent 60%), linear-gradient(135deg, hsl(${hue} 70% 42%), hsl(${(hue + 35) % 360} 65% 18%))` };

    return (
        <div aria-hidden="true" className={'relative h-full w-full overflow-hidden ' + className} style={style}>
            {compact ? (
                <span className="absolute inset-0 flex items-center justify-center font-display text-sm font-extrabold text-white/95 select-none">{monogram(name)}</span>
            ) : (
                <span className="absolute -bottom-3 -end-2 font-display text-[5.5rem] font-extrabold leading-none text-white/15 select-none">{monogram(name)}</span>
            )}
            {category && (
                <span className="absolute start-3 top-3 flex h-8 w-8 items-center justify-center rounded-lg bg-white/15 text-white backdrop-blur-sm">
                    <Icon name={categoryIcon[category] ?? 'box'} size={18} />
                </span>
            )}
        </div>
    );
}

/** Section title row: title, optional subtitle, optional "View all" link. */
export function SectionHeading({ title, subtitle, more, tone = 'light' }: { title: string; subtitle?: string; more?: string | null; tone?: 'light' | 'dark' }) {
    const t = useT();

    return (
        <div className="mb-6 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 className="font-display text-2xl font-extrabold tracking-tight sm:text-3xl">{title}</h2>
                {subtitle && <p className={'mt-1 text-sm ' + (tone === 'dark' ? 'text-white/70' : 'text-muted')}>{subtitle}</p>}
            </div>
            {more && (
                <Link href={more} className={'inline-flex items-center gap-1 text-sm font-semibold ' + (tone === 'dark' ? 'text-white hover:text-brand-lavender' : 'text-primary hover:text-primary-hover')}>
                    {t('home.view_all')}
                    <Icon name="arrow" size={16} className="rtl:rotate-180" />
                </Link>
            )}
        </div>
    );
}

/** Horizontal rail with snap scrolling and previous / next buttons (no visible scrollbar). */
export function Rail({ children, label }: { children: ReactNode; label: string }) {
    const t = useT();
    const ref = useRef<HTMLUListElement>(null);
    const move = (dir: 1 | -1) => {
        const el = ref.current;
        if (!el) return;
        const rtl = getComputedStyle(el).direction === 'rtl' ? -1 : 1;
        el.scrollBy({ left: dir * rtl * el.clientWidth * 0.85, behavior: 'smooth' });
    };

    return (
        <div className="group/rail relative">
            <ul ref={ref} aria-label={label} className="no-scrollbar -mx-4 flex snap-x snap-mandatory gap-4 overflow-x-auto scroll-px-4 px-4 pb-2">
                {children}
            </ul>
            {(['prev', 'next'] as const).map((d) => (
                <button
                    key={d}
                    type="button"
                    onClick={() => move(d === 'prev' ? -1 : 1)}
                    aria-label={t(d === 'prev' ? 'home.previous' : 'home.next')}
                    className={'absolute top-[38%] hidden h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full border border-line bg-white text-ink shadow-md transition hover:border-primary hover:text-primary md:flex ' + (d === 'prev' ? '-start-5' : '-end-5')}
                >
                    <Icon name="chevron" size={18} className={d === 'prev' ? 'rotate-180 rtl:rotate-0' : 'rtl:rotate-180'} />
                </button>
            ))}
        </div>
    );
}

export interface GameTileData {
    name: string;
    label: string;
    href: string;
    count: number;
    image: string | null;
    from: number | null;
}

/** Game × category card: art, game name, category, offers and the lowest price. */
export function GameCard({ tile, category, money }: { tile: GameTileData; category: string; money: (cents: number) => string }) {
    const t = useT();

    return (
        <Link href={tile.href} className="group flex h-full flex-col overflow-hidden rounded-xl border border-line bg-white transition hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-lg">
            <div className="aspect-[4/3] overflow-hidden">
                <GameArt name={tile.name} image={tile.image} category={category} className="transition duration-300 group-hover:scale-105" />
            </div>
            <div className="flex flex-1 flex-col gap-1 p-3">
                <p className="line-clamp-1 font-semibold">{tile.name}</p>
                <p className="text-xs text-muted">
                    {tile.label} · {t('home.offers', { count: tile.count })}
                </p>
                {tile.from !== null && (
                    <p className="mt-auto pt-1 text-sm">
                        <span className="text-muted">{t('home.from')} </span>
                        <span className="font-bold text-ink">{money(tile.from)}</span>
                    </p>
                )}
            </div>
        </Link>
    );
}

/** Why buy here — four promises under the hero (spec: trust first). */
export function TrustBar() {
    const t = useT();
    const items = [
        { icon: 'shield', key: 'protection' },
        { icon: 'bolt', key: 'delivery' },
        { icon: 'lock', key: 'payments' },
        { icon: 'headset', key: 'support' },
    ];

    return (
        <section aria-label={t('home.trust.label')} className="border-b border-line bg-white">
            <ul className="mx-auto grid max-w-7xl grid-cols-2 gap-4 px-4 py-6 lg:grid-cols-4">
                {items.map((i) => (
                    <li key={i.key} className="flex items-start gap-3">
                        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary-soft text-primary">
                            <Icon name={i.icon} size={20} />
                        </span>
                        <span>
                            <span className="block text-sm font-semibold">{t('home.trust.' + i.key)}</span>
                            <span className="block text-xs text-muted">{t('home.trust.' + i.key + '_hint')}</span>
                        </span>
                    </li>
                ))}
            </ul>
        </section>
    );
}

/** How it works — three steps from search to delivery. */
export function HowItWorks() {
    const t = useT();
    const steps = [
        { icon: 'search', key: 'find' },
        { icon: 'wallet', key: 'pay' },
        { icon: 'check', key: 'receive' },
    ];

    return (
        <section className="mx-auto max-w-7xl px-4 pt-14">
            <SectionHeading title={t('home.how.title')} subtitle={t('home.how.subtitle')} />
            <ol className="grid gap-4 md:grid-cols-3">
                {steps.map((s, i) => (
                    <li key={s.key} className="relative rounded-2xl border border-line bg-white p-6">
                        <span className="absolute end-5 top-4 font-display text-5xl font-extrabold text-primary/10">{i + 1}</span>
                        <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-primary to-brand-magenta text-white">
                            <Icon name={s.icon} size={22} />
                        </span>
                        <h3 className="mt-4 text-lg font-bold">{t('home.how.' + s.key)}</h3>
                        <p className="mt-1 text-sm text-muted">{t('home.how.' + s.key + '_hint')}</p>
                    </li>
                ))}
            </ol>
        </section>
    );
}
