import { useEffect, useState } from 'react';
import { useT } from '@/lib/i18n';

const COOKIE = 'cookie_consent';

/** Bottom-end cookie consent card (spec 1.1). Choice stored in a first-party cookie for a year. */
export function CookieBanner() {
    const t = useT();
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        setVisible(!document.cookie.split('; ').some((c) => c.startsWith(`${COOKIE}=`)));
    }, []);

    if (!visible) return null;

    const answer = (value: 'agree' | 'decline') => {
        document.cookie = `${COOKIE}=${value}; path=/; max-age=${60 * 60 * 24 * 365}; SameSite=Lax`;
        setVisible(false);
    };

    return (
        <div className="card fixed bottom-4 end-4 z-50 max-w-sm p-4" role="dialog" aria-live="polite">
            <p className="text-sm">{t('cookie.text')}</p>
            <div className="mt-3 flex gap-2">
                <button type="button" className="btn-primary" onClick={() => answer('agree')}>
                    {t('cookie.agree')}
                </button>
                <button type="button" className="btn bg-canvas text-ink hover:bg-line" onClick={() => answer('decline')}>
                    {t('cookie.decline')}
                </button>
            </div>
        </div>
    );
}
