<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments\Pages;

use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\Payment;
use App\Filament\Resources\Payments\PaymentResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $open = [PaymentStatus::Created->value, PaymentStatus::Pending->value, PaymentStatus::RequiresAction->value, PaymentStatus::Authorized->value];

        return [
            'all' => Tab::make('All'),
            'failed' => Tab::make('Failed')
                ->badge(fn (): int => Payment::query()->where('status', PaymentStatus::Failed)->where('created_at', '>=', now()->subDay())->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', PaymentStatus::Failed)),
            'open' => Tab::make('Not finished')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', $open)),
            'captured' => Tab::make('Captured')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', PaymentStatus::Captured)),
        ];
    }
}
