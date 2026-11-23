import type Echo from 'laravel-echo';

/**
 * Websocket client for Reverb (live chat, ADR-021), created lazily in the browser only (never during SSR) and
 * only when VITE_REVERB_APP_KEY is configured. Callers keep a polling fallback for when this returns null.
 */
let instance: Promise<Echo<'reverb'> | null> | null = null;

export function echo(): Promise<Echo<'reverb'> | null> {
    if (typeof window === 'undefined') return Promise.resolve(null);

    const key = import.meta.env.VITE_REVERB_APP_KEY as string | undefined;
    if (!key) return Promise.resolve(null);

    instance ??= Promise.all([import('laravel-echo'), import('pusher-js')])
        .then(([{ default: EchoClass }, { default: Pusher }]) => {
            (window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher;
            const scheme = (import.meta.env.VITE_REVERB_SCHEME as string | undefined) ?? 'http';
            const port = Number(import.meta.env.VITE_REVERB_PORT ?? 8080);

            return new EchoClass({
                broadcaster: 'reverb',
                key,
                wsHost: (import.meta.env.VITE_REVERB_HOST as string | undefined) ?? window.location.hostname,
                wsPort: port,
                wssPort: port,
                forceTLS: scheme === 'https',
                enabledTransports: ['ws', 'wss'],
                authEndpoint: '/broadcasting/auth',
            });
        })
        .catch(() => null);

    return instance;
}
