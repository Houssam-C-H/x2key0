import { useForm } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { useShared, useT } from '@/lib/i18n';
import { Icon } from './Icon';

interface Props {
    open: boolean;
    onClose: () => void;
}

/** Language + display-currency picker (spec 1.1). Saves to cookies via POST /preferences. */
export function LocaleModal({ open, onClose }: Props) {
    const t = useT();
    const { locale, currency, locales, currencies } = useShared();
    const dialog = useRef<HTMLDialogElement>(null);

    const form = useForm({ locale, currency, redirect: '' });

    useEffect(() => {
        const el = dialog.current;
        if (!el) return;
        if (open && !el.open) {
            form.setData('redirect', window.location.pathname + window.location.search);
            el.showModal();
        }
        if (!open && el.open) el.close();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    return (
        <dialog
            ref={dialog}
            onClose={onClose}
            className="card m-auto w-full max-w-sm p-0 backdrop:bg-black/50"
            aria-labelledby="locale-title"
        >
            <form
                className="flex flex-col gap-4 p-5"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/preferences', { onSuccess: onClose });
                }}
            >
                <div className="flex items-center justify-between">
                    <h2 id="locale-title" className="text-lg font-bold">
                        {t('locale.title')}
                    </h2>
                    <button type="button" className="btn-ghost p-1" onClick={onClose} aria-label={t('locale.cancel')}>
                        <Icon name="x" />
                    </button>
                </div>

                <label className="flex flex-col gap-1">
                    <span className="font-medium">{t('locale.language')}</span>
                    <select className="field" value={form.data.locale} onChange={(e) => form.setData('locale', e.target.value)}>
                        {locales.map((l) => (
                            <option key={l.code} value={l.code}>
                                {l.name}
                            </option>
                        ))}
                    </select>
                </label>

                <label className="flex flex-col gap-1">
                    <span className="font-medium">{t('locale.currency')}</span>
                    <select className="field" value={form.data.currency} onChange={(e) => form.setData('currency', e.target.value)}>
                        {currencies.map((c) => (
                            <option key={c} value={c}>
                                {c}
                            </option>
                        ))}
                    </select>
                    <span className="text-xs text-muted">{t('locale.currency_note')}</span>
                </label>

                <div className="flex justify-end gap-2">
                    <button type="button" className="btn-ghost" onClick={onClose}>
                        {t('locale.cancel')}
                    </button>
                    <button type="submit" className="btn-primary" disabled={form.processing}>
                        {t('locale.save')}
                    </button>
                </div>
            </form>
        </dialog>
    );
}
