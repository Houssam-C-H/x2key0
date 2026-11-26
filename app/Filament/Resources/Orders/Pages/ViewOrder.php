<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Domains\Admin\Actions\StaffNotes;
use App\Domains\Admin\Support\OrderOverview;
use App\Domains\Identity\Models\User;
use App\Domains\Orders\Models\Order;
use App\Filament\Resources\Audit\AuditLogResource;
use App\Filament\Resources\Orders\OrderResource;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;
use LogicException;

/**
 * Order 360° (docs/ADMIN_PLAN.md item 4): the full timeline and every money and delivery record of one order, with
 * the list's staff decisions (approve / reject payment, cancel & refund) on top.
 */
final class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected string $view = 'filament.orders.overview';

    public function getTitle(): string
    {
        return 'Order '.$this->order()->number;
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        return app(OrderOverview::class)->for($this->order());
    }

    public function auditUrl(): ?string
    {
        return AuditLogResource::canAccess() ? AuditLogResource::urlFor($this->order()) : null;
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('note')->label('Add note')->icon('heroicon-o-pencil-square')->color('gray')
                ->schema([Textarea::make('body')->label('Internal note (never shown to buyer or seller)')->required()->maxLength(5000)->rows(4)])
                ->action(function (array $data): void {
                    try {
                        app(StaffNotes::class)->add($this->staff(), 'order', $this->order()->id, is_string($data['body'] ?? null) ? $data['body'] : '');
                        Notification::make()->success()->title('Note added')->send();
                    } catch (DomainException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
            ...array_map(fn (Action $a): Action => $a->after(fn () => $this->order()->refresh()), OrderResource::orderActions()),
        ];
    }

    private function order(): Order
    {
        $record = $this->getRecord();

        return $record instanceof Order ? $record : throw new LogicException('ViewOrder expects an Order record.');
    }

    private function staff(): User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : throw new LogicException('Staff only.');
    }
}
