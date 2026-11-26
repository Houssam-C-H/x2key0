<?php

declare(strict_types=1);

namespace App\Filament\Resources\Offers;

use App\Domains\Storage\Enums\StorageItemStatus;
use App\Domains\Storage\Enums\StorageItemType;
use App\Domains\Storage\Models\StorageGroup;
use App\Filament\Resources\Offers\Pages\ListStorageGroups;
use App\Filament\Resources\Sellers\SellerProfiles\SellerProfileResource;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Storage groups for staff (spec Part 18: "counts only, never decrypted"). No payload column is ever
 * selected here; flagged = cross-seller duplicates frozen for risk review.
 */
final class StorageGroupResource extends Resource
{
    protected static ?string $model = StorageGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|UnitEnum|null $navigationGroup = 'Offers';

    protected static ?string $navigationLabel = 'Storage groups';

    protected static ?string $slug = 'storage-groups';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('seller')
                ->withCount([
                    'items as total_items',
                    'items as available_items' => fn (Builder $q) => $q->where('status', StorageItemStatus::Available),
                    'items as flagged_items' => fn (Builder $q) => $q->whereNotNull('flagged_at'),
                    'offers as linked_offers',
                ]))
            ->columns([
                TextColumn::make('id'),
                TextColumn::make('name')->searchable(),
                TextColumn::make('seller.store_name')->label('Store')->searchable()
                    ->url(fn (StorageGroup $record): string => SellerProfileResource::getUrl('view', ['record' => $record->seller_profile_id])),
                TextColumn::make('item_type')->label('Type')->formatStateUsing(fn (StorageItemType $state): string => ucfirst(str_replace('_', ' ', $state->value))),
                TextColumn::make('available_items')->label('Available'),
                TextColumn::make('total_items')->label('Total'),
                TextColumn::make('flagged_items')->label('Flagged')->badge()->color(fn (mixed $state): string => is_numeric($state) && (int) $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('low_stock_threshold')->label('Low-stock threshold'),
                TextColumn::make('linked_offers')->label('Offers'),
                TextColumn::make('created_at')->since(),
            ])
            ->filters([
                SelectFilter::make('item_type')->options(collect(StorageItemType::cases())->mapWithKeys(fn (StorageItemType $t): array => [$t->value => ucfirst(str_replace('_', ' ', $t->value))])->all()),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListStorageGroups::route('/'),
        ];
    }
}
