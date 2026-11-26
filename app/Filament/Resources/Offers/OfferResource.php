<?php

declare(strict_types=1);

namespace App\Filament\Resources\Offers;

use App\Domains\Offers\Enums\OfferStatus;
use App\Domains\Offers\Exceptions\OfferException;
use App\Domains\Offers\Models\Offer;
use App\Domains\Offers\StateMachines\OfferStateMachine;
use App\Filament\Resources\Offers\Pages\ListOffers;
use App\Filament\Resources\Sellers\SellerProfiles\SellerProfileResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Offer moderation queue (spec 6.2 / Part 18 "Offers (moderation queue, bulk actions)"): approve, reject
 * with a reason the seller sees, system-deactivate (violation) and restore. Status changes go through
 * OfferStateMachine; nobody moderates their own store's offers.
 */
final class OfferResource extends Resource
{
    protected static ?string $model = Offer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Offers';

    protected static ?string $navigationLabel = 'Offers';

    protected static ?string $slug = 'offers';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = Offer::query()->where('status', OfferStatus::PendingReview)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'asc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['seller', 'gameCategory.game', 'gameCategory.type', 'storageGroup']))
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('title')->searchable()->wrap()->limit(60)
                    ->description(fn (Offer $record): ?string => $record->duplicate_of_offer_id !== null ? "Duplicate of #{$record->duplicate_of_offer_id}" : null),
                TextColumn::make('seller.store_name')->label('Store')->searchable()
                    ->url(fn (Offer $record): string => SellerProfileResource::getUrl('edit', ['record' => $record->seller_profile_id])),
                TextColumn::make('service')->label('Service')->state(fn (Offer $record): string => $record->gameCategory->title('en')),
                TextColumn::make('delivery_method')->label('Delivery')->formatStateUsing(fn ($state): string => $state instanceof BackedEnum ? str_replace('_', ' ', (string) $state->value) : ''),
                TextColumn::make('unit_price_amount')->label('Price')->money('USD', divideBy: 100)->sortable(),
                TextColumn::make('stock')->label('Stock')->state(fn (Offer $record): int => $record->stock()),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (OfferStatus $state): string => ucfirst(str_replace('_', ' ', $state->value)))
                    ->color(fn (OfferStatus $state): string => match ($state) {
                        OfferStatus::Active => 'success',
                        OfferStatus::PendingReview, OfferStatus::PendingPublish, OfferStatus::OutOfStock => 'warning',
                        OfferStatus::Rejected, OfferStatus::SystemDeactivated => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('status_reason')->label('Reason')->wrap()->toggleable(),
                TextColumn::make('created_at')->label('Created')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(OfferStatus::cases())->mapWithKeys(fn (OfferStatus $s): array => [$s->value => ucfirst(str_replace('_', ' ', $s->value))])->all())
                    ->default(OfferStatus::PendingReview->value),
                TernaryFilter::make('duplicate')
                    ->label('Flagged duplicate')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('duplicate_of_offer_id'),
                        false: fn (Builder $query) => $query->whereNull('duplicate_of_offer_id'),
                    ),
            ])
            ->recordActions([
                Action::make('view')
                    ->color('gray')
                    ->url(fn (Offer $record): string => $record->path('en'), shouldOpenInNewTab: true),
                Action::make('approve')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Offer $record): bool => $record->status === OfferStatus::PendingReview && Gate::allows('moderate', $record))
                    ->action(fn (Offer $record) => self::run(fn () => app(OfferStateMachine::class)->approve($record, SellerProfileResource::actor()), 'Offer approved')),
                Action::make('reject')
                    ->color('danger')
                    ->visible(fn (Offer $record): bool => $record->status === OfferStatus::PendingReview && Gate::allows('moderate', $record))
                    ->schema([Textarea::make('reason')->label('Reason shown to the seller')->required()->maxLength(500)])
                    ->action(fn (Offer $record, array $data) => self::run(fn () => app(OfferStateMachine::class)->reject($record, SellerProfileResource::actor(), SellerProfileResource::reason($data)), 'Offer rejected')),
                Action::make('systemDeactivate')
                    ->label('System deactivate')
                    ->color('danger')
                    ->visible(fn (Offer $record): bool => $record->status !== OfferStatus::SystemDeactivated && Gate::allows('moderate', $record))
                    ->schema([Textarea::make('reason')->label('Reason shown to the seller (violation)')->required()->maxLength(500)])
                    ->action(fn (Offer $record, array $data) => self::run(fn () => app(OfferStateMachine::class)->systemDeactivate($record, SellerProfileResource::actor(), SellerProfileResource::reason($data)), 'Offer deactivated')),
                Action::make('restore')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (Offer $record): bool => $record->status === OfferStatus::SystemDeactivated && Gate::allows('moderate', $record))
                    ->action(fn (Offer $record) => self::run(fn () => app(OfferStateMachine::class)->restore($record, SellerProfileResource::actor()), 'Offer restored (seller can relist it)')),
            ])
            ->toolbarActions([
                BulkAction::make('approveSelected')
                    ->label('Approve selected')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (Collection $records): void {
                        $done = 0;
                        foreach ($records as $record) {
                            if ($record instanceof Offer && $record->status === OfferStatus::PendingReview && Gate::allows('moderate', $record)) {
                                try {
                                    app(OfferStateMachine::class)->approve($record, SellerProfileResource::actor());
                                    $done++;
                                } catch (OfferException) {
                                    // changed meanwhile
                                }
                            }
                        }
                        Notification::make()->title("{$done} offer(s) approved")->success()->send();
                    }),
            ]);
    }

    /**
     * @param  callable(): mixed  $transition
     */
    private static function run(callable $transition, string $message): void
    {
        try {
            $transition();
            Notification::make()->title($message)->success()->send();
        } catch (OfferException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListOffers::route('/'),
        ];
    }
}
