import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useShared, useT } from '@/lib/i18n';

/**
 * Bookmark prompt under the header (spec 1.1): "If you like it, press Ctrl+D to bookmark this website · No more
 * prompts" (⌘D on Mac, the browser menu on phones). "No more prompts" is remembered on the account or in a cookie;
 * × only hides it for this visit.
 */
export function BookmarkPrompt() {
    const t = useT();
    const { bookmarkPrompt } = useShared();
    const [hidden, setHidden] = useState(false);
    const [keys, setKeys] = useState('Ctrl+D');

    useEffect(() => {
        if (/Mac|iPhone|iPad/.test(navigator.platform)) {
            setKeys('⌘D');
        }
        if (/Android|iPhone|iPad/.test(navigator.userAgent)) {
            setKeys('');
        }
        try {
            setHidden(sessionStorage.getItem('x2k_bookmark_hidden') === '1');
        } catch {
            /* storage blocked: keep showing */
        }
    }, []);

    if (bookmarkPrompt !== true || hidden) {
        return null;
    }

    const close = () => {
        setHidden(true);
        try {
            sessionStorage.setItem('x2k_bookmark_hidden', '1');
        } catch {
            /* ignore */
        }
    };

    return (
        <div role="note" className="bg-ink text-sm text-white">
            <div className="mx-auto flex max-w-7xl flex-wrap items-center gap-3 px-4 py-2">
                <span>{keys === '' ? t('bookmark.mobile') : t('bookmark.desktop', { keys })}</span>
                <button type="button" className="text-white/70 underline hover:text-white" onClick={() => router.post('/bookmark-prompt/dismiss', {}, { preserveScroll: true, preserveState: true })}>
                    {t('bookmark.never')}
                </button>
                <button type="button" className="ms-auto rounded px-2 text-white/70 hover:text-white" aria-label={t('common.close')} onClick={close}>
                    ×
                </button>
            </div>
        </div>
    );
}
