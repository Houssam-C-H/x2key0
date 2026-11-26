import { useEffect, useState } from 'react';
import { Panel } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';

interface InstallPrompt extends Event {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

/** base64url VAPID key → bytes for pushManager.subscribe. */
function keyBytes(base64: string): Uint8Array<ArrayBuffer> {
    const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    const raw = window.atob(padded);
    const out = new Uint8Array(new ArrayBuffer(raw.length));
    for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
}

function csrf(): string {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1] ?? '') : '';
}

/** Settings › Account: install the app and turn web push on or off for this device (ADR-037). */
export function PwaPanel() {
    const t = useT();
    const { pushKey } = useShared();
    const [install, setInstall] = useState<InstallPrompt | null>(null);
    const [standalone, setStandalone] = useState(false);
    const [status, setStatus] = useState<'unsupported' | 'blocked' | 'off' | 'on' | 'busy'>('off');

    useEffect(() => {
        setStandalone(window.matchMedia('(display-mode: standalone)').matches);
        const onPrompt = (e: Event) => {
            e.preventDefault();
            setInstall(e as InstallPrompt);
        };
        window.addEventListener('beforeinstallprompt', onPrompt);

        if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
            setStatus('unsupported');
        } else if (Notification.permission === 'denied') {
            setStatus('blocked');
        } else {
            void navigator.serviceWorker.ready.then((reg) => reg.pushManager.getSubscription()).then((sub) => setStatus(sub ? 'on' : 'off'));
        }

        return () => window.removeEventListener('beforeinstallprompt', onPrompt);
    }, []);

    const send = (method: 'POST' | 'DELETE', body: unknown) =>
        fetch('/push/subscription', { method, headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': csrf() }, credentials: 'same-origin', body: JSON.stringify(body) });

    const turnOn = async () => {
        if (!pushKey) return;
        setStatus('busy');
        try {
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                setStatus(permission === 'denied' ? 'blocked' : 'off');
                return;
            }
            const reg = await navigator.serviceWorker.ready;
            const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(pushKey) });
            await send('POST', sub.toJSON());
            setStatus('on');
        } catch {
            setStatus('off');
        }
    };

    const turnOff = async () => {
        setStatus('busy');
        const reg = await navigator.serviceWorker.ready;
        const sub = await reg.pushManager.getSubscription();
        if (sub) {
            await send('DELETE', { endpoint: sub.endpoint });
            await sub.unsubscribe();
        }
        setStatus('off');
    };

    const ios = typeof navigator !== 'undefined' && /iphone|ipad/i.test(navigator.userAgent);

    return (
        <Panel id="app" title={t('pwa.title')} description={t('pwa.hint')}>
            <div className="flex flex-wrap items-center gap-3">
                {standalone ? (
                    <span className="rounded-full bg-success/10 px-3 py-1 text-sm font-semibold text-success">{t('pwa.installed')}</span>
                ) : install ? (
                    <button
                        type="button"
                        className="btn-primary"
                        onClick={() => {
                            void install.prompt();
                            void install.userChoice.then(() => setInstall(null));
                        }}
                    >
                        {t('pwa.install')}
                    </button>
                ) : ios ? (
                    <span className="text-sm text-muted">{t('pwa.install_ios')}</span>
                ) : null}

                {pushKey && status === 'off' && (
                    <button type="button" className="btn-outline-pill px-4 py-2 text-sm" onClick={() => void turnOn()}>
                        {t('pwa.push_on')}
                    </button>
                )}
                {status === 'on' && (
                    <>
                        <span className="text-sm text-success">{t('pwa.push_enabled')}</span>
                        <button type="button" className="text-sm text-muted underline" onClick={() => void turnOff()}>
                            {t('pwa.push_off')}
                        </button>
                    </>
                )}
                {status === 'blocked' && <span className="text-sm text-muted">{t('pwa.push_blocked')}</span>}
                {status === 'unsupported' && <span className="text-sm text-muted">{t('pwa.push_unsupported')}</span>}
            </div>
        </Panel>
    );
}
