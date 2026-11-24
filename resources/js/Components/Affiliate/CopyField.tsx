import { useState } from 'react';
import { useT } from '@/lib/i18n';

/** Read-only link with a Copy button (clipboard write only; falls back to selecting the text). */
export function CopyField({ value, label }: { value: string; label: string }) {
    const t = useT();
    const [copied, setCopied] = useState(false);

    const copy = async (input: HTMLInputElement | null) => {
        try {
            await navigator.clipboard.writeText(value);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            input?.select();
        }
    };

    return (
        <div className="flex gap-2">
            <input id={'copy-' + label} className="field flex-1 font-mono text-sm" readOnly value={value} aria-label={label} onFocus={(e) => e.target.select()} />
            <button type="button" className="btn-outline-pill shrink-0 px-3 py-1 text-sm" onClick={() => void copy(document.getElementById('copy-' + label) as HTMLInputElement | null)}>
                {copied ? t('affiliate.copied') : t('affiliate.copy')}
            </button>
        </div>
    );
}
