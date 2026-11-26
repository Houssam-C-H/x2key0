<?php

declare(strict_types=1);

namespace App\Filament\Livewire;

use App\Domains\Reporting\Reports;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Top-bar counter of open staff work (docs/ADMIN_PLAN.md item 11). Re-renders when the admin's websocket bridge
 * (resources/js/admin-live.ts) relays a `queues.changed` signal from the private `staff` channel.
 */
final class QueueCounter extends Component
{
    #[On('staff-queues-changed')]
    public function refreshCounts(): void
    {
        // Re-rendering reads fresh counts.
    }

    public function render(): View
    {
        $queues = app(Reports::class)->queues();

        return view('filament.livewire.queue-counter', [
            'total' => array_sum($queues),
            'queues' => array_filter($queues),
        ]);
    }
}
