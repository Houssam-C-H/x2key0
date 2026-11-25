import { router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Icon } from '@/Components/Icon';
import { useShared, useT } from '@/lib/i18n';
import { formatUsd } from '@/lib/money';

export interface PriceAlertState {
    type: 'product' | 'category';
    id: string;
    alertId: number | null;
    target: number | null;
    lowest: number | null;
}

/** Bell: set a target price; we notify once when the lowest price reaches it (alerts:prices). */
export function PriceAlertButton({ alert }: { alert: PriceAlertState }) {
    const t = useT();
    const { auth, locale } = useShared();
    const [open, setOpen] = useState(false);
    const root = useRef<HTMLDivElement>(null);
    const suggested = alert.target ?? (alert.lowest !== null ? Math.max(1, Math.floor(alert.lowest * 0.9)) : null);
    const form = useForm({ type: alert.type, id: alert.id, target: suggested !== null ? (suggested / 100).toFixed(2) : '' });

    useEffect(() => {
        if (!open) return;
        const close = (e: MouseEvent) => {
            if (root.current && !root.current.contains(e.target as Node)) setOpen(false);
        };
        document.addEventListener('mousedown', close);
        return () => document.removeEventListener('mousedown', close);
    }, [open]);

    const active = alert.alertId !== null && alert.target !== null;

    return (
        <div ref={root} className="relative">
            <button
                type="button"
                aria-expanded={open}
                onClick={() => (auth.user ? setOpen((v) => !v) : router.visit(`/${locale}/login`))}
                className={'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm font-semibold hover:border-primary ' + (active ? 'border-primary bg-primary-soft text-primary' : 'border-line text-muted')}
            >
                <Icon name="bell" size={15} />
                <span className="hidden sm:inline">{active ? t('price_alert.active', { price: formatUsd(alert.target ?? 0, locale) ?? '' }) : t('price_alert.button')}</span>
            </button>

            {open && (
                <form
                    className="absolute end-0 top-full z-40 mt-2 w-72 rounded-xl border border-line bg-white p-4 text-ink shadow-2xl"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/price-alerts', { preserveScroll: true, onSuccess: () => setOpen(false) });
                    }}
                >
                    <label className="text-sm font-medium" htmlFor="price-alert-target">
                        {t('price_alert.prompt')}
                    </label>
                    <div className="mt-2 flex items-center gap-2">
                        <span className="text-sm text-muted">USD</span>
                        <input id="price-alert-target" className="field" inputMode="decimal" value={form.data.target} onChange={(e) => form.setData('target', e.target.value)} />
                    </div>
                    {form.errors.target && <p className="mt-1 text-xs text-danger">{form.errors.target}</p>}
                    {alert.lowest !== null && <p className="mt-1 text-xs text-muted">{t('price_alert.current', { price: formatUsd(alert.lowest, locale) ?? '' })}</p>}
                    <div className="mt-3 flex items-center justify-between gap-2">
                        <button type="submit" className="btn-primary px-4 text-sm" disabled={form.processing}>
                            {t('price_alert.save')}
                        </button>
                        {active && (
                            <button
                                type="button"
                                className="text-xs font-semibold text-danger hover:underline"
                                onClick={() => router.delete(`/price-alerts/${alert.alertId}`, { preserveScroll: true, onSuccess: () => setOpen(false) })}
                            >
                                {t('price_alert.remove')}
                            </button>
                        )}
                    </div>
                </form>
            )}
        </div>
    );
}
