import { useEffect, useState } from 'react';
import { useShared } from '@/lib/i18n';
import { Icon } from './Icon';

/** Success / error banner from the server's session flash. Auto-hides success after 5 s. */
export function Flash() {
    const { flash } = useShared();
    const [hidden, setHidden] = useState<string | null>(null);
    const message = flash.error ?? flash.success;
    const tone = flash.error ? 'error' : 'success';

    useEffect(() => {
        setHidden(null);
        if (tone === 'success' && message) {
            const timer = setTimeout(() => setHidden(message), 5000);
            return () => clearTimeout(timer);
        }
    }, [message, tone]);

    if (!message || hidden === message) return null;

    return (
        <div
            role={tone === 'error' ? 'alert' : 'status'}
            className={`mb-4 flex items-start gap-3 rounded-md border-s-4 p-3 text-sm ${
                tone === 'error' ? 'border-danger bg-danger/5 text-danger' : 'border-success bg-success/5 text-success'
            }`}
        >
            <span className="flex-1">{message}</span>
            <button type="button" onClick={() => setHidden(message)} aria-label="Dismiss" className="opacity-70 hover:opacity-100">
                <Icon name="x" size={16} />
            </button>
        </div>
    );
}
