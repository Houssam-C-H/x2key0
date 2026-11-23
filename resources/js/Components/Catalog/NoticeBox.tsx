import { useState } from 'react';
import { Icon } from '@/Components/Icon';
import { useT } from '@/lib/i18n';

/** "Important notice" box (spec 1.4.1): orange start border, dismissible for the visit. */
export function NoticeBox({ text }: { text: string }) {
    const t = useT();
    const [open, setOpen] = useState(true);

    if (!open) return null;

    return (
        <aside className="flex gap-3 rounded-md border-s-4 border-warning bg-warning-soft p-4 text-sm" aria-label={t('catalog.notice')}>
            <div className="flex-1">
                <p className="mb-1 font-semibold">{t('catalog.notice')}</p>
                <p className="text-ink/80">{text}</p>
            </div>
            <button type="button" onClick={() => setOpen(false)} aria-label={t('locale.cancel')} className="self-start opacity-60 hover:opacity-100">
                <Icon name="x" size={16} />
            </button>
        </aside>
    );
}
