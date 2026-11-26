import { echo } from '@/lib/echo';

/**
 * Admin live bridge (docs/ADMIN_PLAN.md item 11): relays the content-free `queues.changed` signal from the private
 * `staff` channel to Livewire, so the top-bar queue counter and the work-queue widget refresh at once. Without
 * Reverb configured nothing happens and the widgets keep their polling.
 */
type LivewireGlobal = { dispatch: (event: string) => void };

void echo().then((client) => {
    client?.private('staff').listen('.queues.changed', () => {
        (window as unknown as { Livewire?: LivewireGlobal }).Livewire?.dispatch('staff-queues-changed');
    });
});
