<?php

declare(strict_types=1);

namespace App\Filament\Resources\Reviews;

use App\Domains\Reviews\Actions\OrderComments;
use App\Domains\Reviews\Exceptions\ReviewException;
use App\Domains\Reviews\Models\OrderComment;
use App\Filament\Resources\Reviews\Pages\ListOrderComments;
use App\Filament\Resources\Sellers\SellerProfiles\SellerProfileResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Reviews (spec Part 18): hide with a reason or show again, feature positive comments in Community ratings. */
final class OrderCommentResource extends Resource
{
    protected static ?string $model = OrderComment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Reviews';

    protected static ?string $slug = 'reviews';

    protected static ?int $navigationSort = 3;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['seller:id,store_name', 'buyer:id,username', 'order:id,number']))
            ->columns([
                TextColumn::make('order.number')->label('Order')->searchable(),
                TextColumn::make('seller.store_name')->label('Store'),
                TextColumn::make('buyer.username')->label('Buyer'),
                TextColumn::make('rating')->badge()->color(fn (string $state): string => match ($state) {
                    'positive' => 'success',
                    'negative' => 'danger',
                    default => 'gray',
                }),
                TextColumn::make('body')->limit(80)->wrap()->placeholder('—'),
                TextColumn::make('seller_reply')->label('Reply')->limit(60)->wrap()->placeholder('—'),
                TextColumn::make('status')->badge()->color(fn (string $state): string => $state === 'visible' ? 'success' : 'warning'),
                TextColumn::make('featured_in_community')->label('Featured')->badge()->formatStateUsing(fn (bool $state): string => $state ? 'yes' : 'no'),
                TextColumn::make('created_at')->since(),
            ])
            ->filters([
                SelectFilter::make('rating')->options(['positive' => 'Positive', 'neutral' => 'Neutral', 'negative' => 'Negative']),
                SelectFilter::make('status')->options(['visible' => 'Visible', 'hidden' => 'Hidden']),
            ])
            ->recordActions([
                Action::make('hide')->color('danger')
                    ->visible(fn (OrderComment $record): bool => $record->status === 'visible')
                    ->schema([Textarea::make('reason')->required()->maxLength(500)])
                    ->action(fn (OrderComment $record, array $data) => self::run(fn () => app(OrderComments::class)->hide($record, SellerProfileResource::actor(), is_string($data['reason'] ?? null) ? $data['reason'] : ''))),
                Action::make('show')->requiresConfirmation()
                    ->visible(fn (OrderComment $record): bool => $record->status === 'hidden')
                    ->action(fn (OrderComment $record) => self::run(fn () => app(OrderComments::class)->show($record))),
                Action::make('feature')->label(fn (OrderComment $record): string => $record->featured_in_community ? 'Unfeature' : 'Feature')
                    ->visible(fn (OrderComment $record): bool => $record->status === 'visible' && $record->rating === 'positive')
                    ->action(fn (OrderComment $record) => self::run(fn () => app(OrderComments::class)->feature($record, ! $record->featured_in_community))),
            ]);
    }

    private static function run(callable $action): void
    {
        try {
            $action();
        } catch (ReviewException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return ['index' => ListOrderComments::route('/')];
    }
}
