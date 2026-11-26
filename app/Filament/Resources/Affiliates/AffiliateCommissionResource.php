<?php

declare(strict_types=1);

namespace App\Filament\Resources\Affiliates;

use App\Domains\Affiliate\Models\AffiliateCommission;
use App\Domains\Identity\Enums\StaffRole;
use App\Filament\Resources\Affiliates\Pages\ListAffiliateCommissions;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\Staff;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Affiliate commissions (docs/ADMIN_PLAN.md item 8): pending, released and reversed, per order and level. Read-only —
 * money moves only through AffiliateCommissions (accrue on completion, release, recalculate / reverse).
 */
final class AffiliateCommissionResource extends Resource
{
    protected static ?string $model = AffiliateCommission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Affiliates';

    protected static ?string $navigationLabel = 'Commissions';

    protected static ?string $slug = 'affiliate-commissions';

    public static function canAccess(): bool
    {
        return Staff::has(StaffRole::Finance, StaffRole::MerchantManager);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['affiliate.user', 'order']))
            ->columns([
                TextColumn::make('created_at')->label('Accrued')->dateTime()->sortable(),
                TextColumn::make('affiliate.user.username')->label('Affiliate')->searchable()
                    ->url(fn (AffiliateCommission $record): string => UserResource::getUrl('view', ['record' => $record->affiliate->user_id])),
                TextColumn::make('order.number')->label('Order')->searchable()
                    ->url(fn (AffiliateCommission $record): string => OrderResource::getUrl('view', ['record' => $record->order_id])),
                TextColumn::make('level')->formatStateUsing(fn (int $state): string => $state === 1 ? 'direct' : 'indirect'),
                TextColumn::make('base_amount')->label('Base')->money(fn (AffiliateCommission $r): string => $r->currency, divideBy: 100),
                TextColumn::make('rate_bps')->label('Rate')->formatStateUsing(fn (int $state): string => number_format($state / 100, 2).' %'),
                TextColumn::make('commission_amount')->label('Commission')->money(fn (AffiliateCommission $r): string => $r->currency, divideBy: 100)->sortable(),
                TextColumn::make('reversed_amount')->label('Reversed')->money(fn (AffiliateCommission $r): string => $r->currency, divideBy: 100),
                TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                    AffiliateCommission::APPROVED => 'success',
                    AffiliateCommission::REVERSED => 'danger',
                    default => 'warning',
                })->formatStateUsing(fn (string $state): string => $state === AffiliateCommission::APPROVED ? 'released' : $state),
                TextColumn::make('release_at')->label('Release')->date()->sortable(),
            ])
            ->filters([
                SelectFilter::make('level')->options([1 => 'Direct', 2 => 'Indirect']),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return ['index' => ListAffiliateCommissions::route('/')];
    }
}
