import { useEffect, useId, useRef, useState } from 'react';
import { useShared, useT } from '@/lib/i18n';
import { categoryIcon, Icon } from './Icon';

/**
 * Category scope for the header search: a styled listbox (icons, two columns) instead of a native select.
 * Submits as the hidden `type` field of the surrounding form.
 */
export function CategorySelect({ name = 'type' }: { name?: string }) {
    const t = useT();
    const { categoryTypes } = useShared();
    const [open, setOpen] = useState(false);
    const [value, setValue] = useState('');
    const root = useRef<HTMLDivElement>(null);
    const listId = useId();

    useEffect(() => {
        if (!open) return;
        const onDown = (e: MouseEvent) => {
            if (root.current && !root.current.contains(e.target as Node)) setOpen(false);
        };
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') setOpen(false);
        };
        document.addEventListener('mousedown', onDown);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onDown);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    const current = categoryTypes.find((c) => c.code === value);
    const options = [{ code: '', label: t('header.all_categories') }, ...categoryTypes];

    return (
        <div ref={root} className="relative shrink-0">
            <input type="hidden" name={name} value={value} />
            <button
                type="button"
                aria-haspopup="listbox"
                aria-expanded={open}
                aria-controls={listId}
                aria-label={t('header.all_categories')}
                onClick={() => setOpen((v) => !v)}
                className="flex h-full max-w-48 items-center gap-2 border-s border-line px-3 text-sm font-medium text-ink hover:bg-canvas"
            >
                <Icon name={current ? (categoryIcon[current.code] ?? 'box') : 'grid'} size={16} className="shrink-0 text-primary" />
                <span className="truncate">{current?.label ?? t('header.all_categories')}</span>
                <Icon name="chevron" size={14} className={'shrink-0 text-muted transition ' + (open ? '-rotate-90' : 'rotate-90')} />
            </button>

            {open && (
                <ul
                    id={listId}
                    role="listbox"
                    aria-label={t('header.all_categories')}
                    className="absolute end-0 top-full z-50 mt-2 grid w-[30rem] max-w-[calc(100vw-2rem)] grid-cols-2 gap-1 rounded-2xl border border-line bg-white p-2 text-ink shadow-2xl"
                >
                    {options.map((c) => {
                        const selected = c.code === value;
                        return (
                            <li key={c.code || 'all'} role="option" aria-selected={selected} className={c.code === '' ? 'col-span-2' : ''}>
                                <button
                                    type="button"
                                    onClick={() => {
                                        setValue(c.code);
                                        setOpen(false);
                                    }}
                                    className={
                                        'flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-start text-sm transition ' +
                                        (selected ? 'bg-primary-soft font-semibold text-primary' : 'hover:bg-canvas')
                                    }
                                >
                                    <span className={'flex h-7 w-7 shrink-0 items-center justify-center rounded-md ' + (selected ? 'bg-primary text-white' : 'bg-canvas text-primary')}>
                                        <Icon name={c.code === '' ? 'grid' : (categoryIcon[c.code] ?? 'box')} size={15} />
                                    </span>
                                    <span className="truncate">{c.label}</span>
                                    {selected && <Icon name="check" size={16} className="ms-auto shrink-0" />}
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}
