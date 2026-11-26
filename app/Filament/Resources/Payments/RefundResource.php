<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Payments\Models\Refund;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Payments\Pages\ListRefunds;
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
 * Refunds (docs/ADMIN_PLAN.md item 5): every refund with its order, destination (card or credits), status and who
 * started it. Read-only — refunds are made only through RefundOrder (ADR-019). Finance and admins.
 */
final class RefundResource extends Resource
{
    protected static ?string $model = Refund::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    protected static string|UnitEnum|null $navigationGroup = 'Payments';

    protected static ?string $slug = 'refunds';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return Staff::has(StaffRole::Finance);
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
            ->modifyQueryUsing(fn (Builder $query) => $query->with('order'))
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('order.number')->label('Order')->searchable()
                    ->url(fn (Refund $record): string => OrderResource::getUrl('view', ['record' => $record->order_id])),
                TextColumn::make('amount')->money(fn (Refund $record): string => $record->currency, divideBy: 100)->sortable(),
                TextColumn::make('destination')->badge()->formatStateUsing(fn (string $state): string => str_replace('_', ' ', $state)),
                TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                    'succeeded', 'completed', 'posted' => 'success',
                    'failed' => 'danger',
                    default => 'warning',
                }),
                TextColumn::make('reason')->wrap()->limit(80)->searchable(),
                TextColumn::make('initiated_by_type')->label('By')
                    ->formatStateUsing(fn (string $state, Refund $record): string => $state.($record->initiated_by_id !== null ? ' #'.$record->initiated_by_id : '')),
                TextColumn::make('provider_refund_id')->label('Provider ID')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('destination')->options(fn (): array => Refund::query()->distinct()->orderBy('destination')->pluck('destination', 'destination')->all()),
                SelectFilter::make('status')->options(fn (): array => Refund::query()->distinct()->orderBy('status')->pluck('status', 'status')->all()),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return ['index' => ListRefunds::route('/')];
    }
}
