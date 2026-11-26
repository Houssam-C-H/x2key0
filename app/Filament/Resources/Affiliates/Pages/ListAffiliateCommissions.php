<?php

declare(strict_types=1);

namespace App\Filament\Resources\Affiliates\Pages;

use App\Domains\Affiliate\Models\AffiliateCommission;
use App\Filament\Resources\Affiliates\AffiliateCommissionResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListAffiliateCommissions extends ListRecords
{
    protected static string $resource = AffiliateCommissionResource::class;

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'pending' => Tab::make('Pending')->modifyQueryUsing(fn (Builder $query) => $query->where('status', AffiliateCommission::PENDING)),
            'released' => Tab::make('Released')->modifyQueryUsing(fn (Builder $query) => $query->where('status', AffiliateCommission::APPROVED)),
            'reversed' => Tab::make('Reversed')->modifyQueryUsing(fn (Builder $query) => $query->where(fn (Builder $q) => $q->where('status', AffiliateCommission::REVERSED)->orWhere('reversed_amount', '>', 0))),
        ];
    }
}
