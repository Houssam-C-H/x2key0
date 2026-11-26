import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { echo } from '@/lib/echo';

/**
 * Live bell and chat badge (docs/ADMIN_PLAN.md item 11): listens on the user's private channel for the content-free
 * `user.signal` and reloads only the shared counters. Without Reverb the counters refresh on the next page load.
 */
export function useLiveCounters(userId: number | null | undefined): void {
    useEffect(() => {
        if (!userId) return;
        const channel = `user.${userId}`;
        let timer: ReturnType<typeof setTimeout> | null = null;

        void echo().then((client) => {
            client?.private(channel).listen('.user.signal', () => {
                // Several signals in a burst (e.g. order paid + chat opened) → one reload.
                if (timer) clearTimeout(timer);
                timer = setTimeout(() => router.reload({ only: ['inbox', 'chat'] }), 300);
            });
        });

        return () => {
            if (timer) clearTimeout(timer);
            void echo().then((client) => client?.leave(channel));
        };
    }, [userId]);
}
