import { useState } from 'react';
import { useT } from '@/lib/i18n';
import type { FilterDef } from '@/types/catalog';

export interface ListingState {
    q: string;
    facets: Record<string, string[]>;
    ranges: Record<string, { min: number | null; max: number | null }>;
    price: { min: number | null; max: number | null };
    sort: string;
}

interface Props {
    filters: FilterDef[];
    state: ListingState;
    onChange: (next: ListingState) => void;
    showPrice: boolean;
}

const VISIBLE_OPTIONS = 6;

/**
 * Filter sidebar (spec 1.4.1): product name search, price range, platform/region and schema facets
 * with counts (disjunctive: a group's counts ignore its own selection), number ranges.
 */
export function FilterSidebar({ filters, state, onChange, showPrice }: Props) {
    const t = useT();
    const [q, setQ] = useState(state.q);
    const [price, setPrice] = useState({ min: state.price.min?.toString() ?? '', max: state.price.max?.toString() ?? '' });

    const toggle = (key: string, value: string) => {
        const current = state.facets[key] ?? [];
        const nextValues = current.includes(value) ? current.filter((v) => v !== value) : [...current, value];
        const facets = { ...state.facets, [key]: nextValues };
        if (nextValues.length === 0) delete facets[key];
        onChange({ ...state, facets });
    };

    const activeCount = Object.values(state.facets).reduce((n, v) => n + v.length, 0) + Object.keys(state.ranges).length + (state.q ? 1 : 0) + (state.price.min !== null || state.price.max !== null ? 1 : 0);

    return (
        <aside aria-label={t('catalog.filter.title')} className="card space-y-5 p-4">
            <div className="flex items-center justify-between">
                <h2 className="text-sm font-bold tracking-wide uppercase">{t('catalog.filter.title')}</h2>
                {activeCount > 0 && (
                    <button type="button" className="text-xs text-primary hover:underline" onClick={() => onChange({ q: '', facets: {}, ranges: {}, price: { min: null, max: null }, sort: state.sort })}>
                        {t('catalog.filter.clear', { count: activeCount })}
                    </button>
                )}
            </div>

            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    onChange({ ...state, q: q.trim() });
                }}
            >
                <label className="flex flex-col gap-1">
                    <span className="text-sm font-semibold">{t('catalog.filter.product_name')}</span>
                    <input type="search" className="field" value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('catalog.filter.product_name_placeholder')} />
                </label>
            </form>

            {showPrice && (
                <fieldset>
                    <legend className="mb-1 text-sm font-semibold">{t('catalog.filter.price')}</legend>
                    <form
                        className="flex items-center gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            onChange({ ...state, price: { min: price.min === '' ? null : Number(price.min), max: price.max === '' ? null : Number(price.max) } });
                        }}
                    >
                        <input aria-label={t('catalog.filter.from')} inputMode="decimal" className="field" placeholder={t('catalog.filter.from')} value={price.min} onChange={(e) => setPrice({ ...price, min: e.target.value })} />
                        <span aria-hidden="true">–</span>
                        <input aria-label={t('catalog.filter.to')} inputMode="decimal" className="field" placeholder={t('catalog.filter.to')} value={price.max} onChange={(e) => setPrice({ ...price, max: e.target.value })} />
                        <button type="submit" className="btn-outline-pill px-3 py-1.5 text-xs">
                            OK
                        </button>
                    </form>
                </fieldset>
            )}

            {filters.map((f) => (f.type === 'range' ? <RangeFilter key={f.key} filter={f} state={state} onChange={onChange} /> : <FacetFilter key={f.key} filter={f} selected={state.facets[f.key] ?? []} onToggle={(v) => toggle(f.key, v)} />))}
        </aside>
    );
}

function FacetFilter({ filter, selected, onToggle }: { filter: FilterDef; selected: string[]; onToggle: (value: string) => void }) {
    const t = useT();
    const [expanded, setExpanded] = useState(false);
    const options = expanded ? filter.options : filter.options.slice(0, VISIBLE_OPTIONS);
    const hidden = filter.options.length - VISIBLE_OPTIONS;

    return (
        <fieldset>
            <legend className="mb-2 text-sm font-semibold">{filter.label}</legend>
            <ul className="space-y-1.5">
                {options.map((o) => {
                    const checked = selected.includes(o.value);
                    const disabled = o.count === 0 && !checked;

                    return (
                        <li key={o.value}>
                            <label className={`flex items-center gap-2 text-sm ${disabled ? 'opacity-40' : 'cursor-pointer'}`}>
                                <input type="checkbox" checked={checked} disabled={disabled} onChange={() => onToggle(o.value)} />
                                <span className="flex-1">{o.label}</span>
                                <span className="text-xs text-muted tabular-nums">{o.count}</span>
                            </label>
                        </li>
                    );
                })}
            </ul>
            {hidden > 0 && (
                <button type="button" className="mt-1 text-xs text-primary hover:underline" onClick={() => setExpanded((v) => !v)}>
                    {expanded ? t('catalog.filter.less') : t('catalog.filter.more', { count: hidden })}
                </button>
            )}
        </fieldset>
    );
}

function RangeFilter({ filter, state, onChange }: { filter: FilterDef; state: ListingState; onChange: (next: ListingState) => void }) {
    const t = useT();
    const current = state.ranges[filter.key];
    const [min, setMin] = useState(current?.min?.toString() ?? '');
    const [max, setMax] = useState(current?.max?.toString() ?? '');

    return (
        <fieldset>
            <legend className="mb-1 text-sm font-semibold">
                {filter.label}
                {filter.unit && <span className="font-normal text-muted"> ({filter.unit})</span>}
            </legend>
            <form
                className="flex items-center gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    const ranges = { ...state.ranges };
                    if (min === '' && max === '') delete ranges[filter.key];
                    else ranges[filter.key] = { min: min === '' ? null : Number(min), max: max === '' ? null : Number(max) };
                    onChange({ ...state, ranges });
                }}
            >
                <input aria-label={`${filter.label} ${t('catalog.filter.from')}`} inputMode="decimal" className="field" placeholder={filter.bounds ? String(filter.bounds.min) : t('catalog.filter.from')} value={min} onChange={(e) => setMin(e.target.value)} />
                <span aria-hidden="true">–</span>
                <input aria-label={`${filter.label} ${t('catalog.filter.to')}`} inputMode="decimal" className="field" placeholder={filter.bounds ? String(filter.bounds.max) : t('catalog.filter.to')} value={max} onChange={(e) => setMax(e.target.value)} />
                <button type="submit" className="btn-outline-pill px-3 py-1.5 text-xs">
                    OK
                </button>
            </form>
        </fieldset>
    );
}
