import { router } from '@inertiajs/react';
import { useEffect, useId, useRef, useState } from 'react';
import { GameArt } from '@/Components/Home/HomeParts';
import { Icon } from '@/Components/Icon';
import { useShared, useT } from '@/lib/i18n';

interface Suggestion {
    games: { name: string; href: string; icon: string | null }[];
    categories: { label: string; href: string }[];
}

const RECENT_KEY = 'x2k_recent_searches';

export function readRecent(): string[] {
    try {
        const raw = JSON.parse(window.localStorage.getItem(RECENT_KEY) ?? '[]') as unknown;
        return Array.isArray(raw) ? raw.filter((x): x is string => typeof x === 'string').slice(0, 6) : [];
    } catch {
        return [];
    }
}

export function rememberSearch(q: string): void {
    const text = q.trim();
    if (text.length < 2) return;
    try {
        window.localStorage.setItem(RECENT_KEY, JSON.stringify([text, ...readRecent().filter((r) => r.toLowerCase() !== text.toLowerCase())].slice(0, 6)));
    } catch {
        /* storage unavailable: recent searches are a convenience */
    }
}

/**
 * Header search box with suggestions as you type (games with pictures, categories; typo tolerant on the server)
 * and the last searches when empty. Arrow keys move, Enter opens, Escape closes. The surrounding form submits
 * normally when no suggestion is chosen.
 */
export function SearchSuggestions({ name = 'q', placeholder, className = '' }: { name?: string; placeholder: string; className?: string }) {
    const t = useT();
    const { locale } = useShared();
    const [q, setQ] = useState('');
    const [open, setOpen] = useState(false);
    const [data, setData] = useState<Suggestion>({ games: [], categories: [] });
    const [recent, setRecent] = useState<string[]>([]);
    const [active, setActive] = useState(-1);
    const root = useRef<HTMLDivElement>(null);
    const listId = useId();

    useEffect(() => {
        const text = q.trim();
        if (text.length < 2) {
            setData({ games: [], categories: [] });
            return;
        }
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            fetch(`/${locale}/search/suggest?q=${encodeURIComponent(text)}`, { signal: controller.signal, headers: { Accept: 'application/json' } })
                .then((r) => (r.ok ? (r.json() as Promise<Suggestion>) : { games: [], categories: [] }))
                .then((d) => {
                    setData(d);
                    setActive(-1);
                })
                .catch(() => undefined);
        }, 150);
        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [q, locale]);

    useEffect(() => {
        const close = (e: MouseEvent) => {
            if (root.current && !root.current.contains(e.target as Node)) setOpen(false);
        };
        document.addEventListener('mousedown', close);
        return () => document.removeEventListener('mousedown', close);
    }, []);

    const showRecent = q.trim().length < 2;
    const items: { label: string; href: string; kind: 'game' | 'category' | 'recent'; icon?: string | null }[] = showRecent
        ? recent.map((r) => ({ label: r, href: `/${locale}/search?q=${encodeURIComponent(r)}`, kind: 'recent' as const }))
        : [...data.games.map((g) => ({ label: g.name, href: g.href, kind: 'game' as const, icon: g.icon })), ...data.categories.map((c) => ({ label: c.label, href: c.href, kind: 'category' as const }))];

    const go = (href: string, label: string) => {
        rememberSearch(label);
        setOpen(false);
        router.visit(href);
    };

    return (
        <div ref={root} className={'relative min-w-0 flex-1 ' + className}>
            <input
                name={name}
                type="search"
                value={q}
                autoComplete="off"
                role="combobox"
                aria-expanded={open && items.length > 0}
                aria-controls={listId}
                aria-autocomplete="list"
                aria-label={t('header.search')}
                placeholder={placeholder}
                onFocus={() => {
                    setRecent(readRecent());
                    setOpen(true);
                }}
                onChange={(e) => {
                    setQ(e.target.value);
                    setOpen(true);
                }}
                onKeyDown={(e) => {
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        setActive((i) => Math.min(items.length - 1, i + 1));
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        setActive((i) => Math.max(-1, i - 1));
                    } else if (e.key === 'Escape') {
                        setOpen(false);
                    } else if (e.key === 'Enter') {
                        const item = items[active];
                        if (open && item) {
                            e.preventDefault();
                            go(item.href, item.kind === 'recent' ? item.label : q || item.label);
                        } else {
                            rememberSearch(q);
                        }
                    }
                }}
                className="h-full w-full min-w-0 bg-transparent px-2.5 text-sm text-ink outline-none placeholder:text-muted"
            />

            {open && items.length > 0 && (
                <ul id={listId} role="listbox" className="absolute start-0 top-full z-50 mt-2 w-full min-w-72 overflow-hidden rounded-xl border border-line bg-white py-1 text-ink shadow-2xl">
                    {showRecent && <li className="px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wide text-muted">{t('search.recent')}</li>}
                    {items.map((item, i) => (
                        <li key={item.kind + item.href} role="option" aria-selected={i === active}>
                            <button
                                type="button"
                                onMouseEnter={() => setActive(i)}
                                onClick={() => go(item.href, item.kind === 'recent' ? item.label : q)}
                                className={'flex w-full items-center gap-3 px-3 py-2 text-start text-sm ' + (i === active ? 'bg-canvas' : '')}
                            >
                                {item.kind === 'game' ? (
                                    <span className="h-7 w-7 shrink-0 overflow-hidden rounded-md">
                                        <GameArt name={item.label} image={item.icon} compact />
                                    </span>
                                ) : (
                                    <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-canvas text-muted">
                                        <Icon name={item.kind === 'recent' ? 'clock' : 'grid'} size={14} />
                                    </span>
                                )}
                                <span className="min-w-0 flex-1 truncate">{item.label}</span>
                                <span className="text-[11px] text-muted">{t('search.kind.' + item.kind)}</span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
