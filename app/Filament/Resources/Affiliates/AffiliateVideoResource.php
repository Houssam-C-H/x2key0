<?php

declare(strict_types=1);

namespace App\Filament\Resources\Affiliates;

use App\Domains\Affiliate\Actions\AffiliateVideos;
use App\Domains\Affiliate\Exceptions\AffiliateException;
use App\Domains\Affiliate\Models\AffiliateVideoRecord;
use App\Filament\Resources\Affiliates\Pages\ListAffiliateVideos;
use App\Filament\Resources\Sellers\SellerProfiles\SellerProfileResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Promotional video reviews (spec Part 15): approve with a reward (EXP_AFFILIATE → affiliate available) or reject. */
final class AffiliateVideoResource extends Resource
{
    protected static ?string $model = AffiliateVideoRecord::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedVideoCamera;

    protected static string|UnitEnum|null $navigationGroup = 'Affiliates';

    protected static ?string $navigationLabel = 'Video reviews';

    protected static ?string $slug = 'affiliate-videos';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = AffiliateVideoRecord::query()->where('status', 'submitted')->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('affiliate.user:id,username'))
            ->columns([
                TextColumn::make('affiliate.user.username')->label('Affiliate'),
                TextColumn::make('platform')->badge(),
                TextColumn::make('url')->limit(50)->url(fn (AffiliateVideoRecord $record): string => $record->url, shouldOpenInNewTab: true),
                TextColumn::make('views_reported')->label('Views (reported)')->numeric(),
                TextColumn::make('reward_amount')->label('Reward')->state(fn (AffiliateVideoRecord $record): string => number_format($record->reward_amount / 100, 2).' USD'),
                TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                    'approved' => 'success',
                    'rejected' => 'danger',
                    default => 'warning',
                }),
                TextColumn::make('created_at')->since(),
            ])
            ->filters([SelectFilter::make('status')->options(['submitted' => 'Submitted', 'approved' => 'Approved', 'rejected' => 'Rejected'])->default('submitted')])
            ->recordActions([
                Action::make('approve')->color('success')
                    ->visible(fn (AffiliateVideoRecord $record): bool => $record->status === 'submitted')
                    ->schema([
                        TextInput::make('reward')->label('Reward (USD)')->numeric()->minValue(0)->maxValue(10000)->required(),
                        Textarea::make('note')->maxLength(500),
                    ])
                    ->action(function (AffiliateVideoRecord $record, array $data): void {
                        $reward = is_numeric($data['reward'] ?? null) ? (int) round(((float) $data['reward']) * 100) : 0;
                        self::run(fn () => app(AffiliateVideos::class)->approve($record, $reward, SellerProfileResource::actor(), is_string($data['note'] ?? null) ? $data['note'] : null));
                    }),
                Action::make('reject')->color('danger')
                    ->visible(fn (AffiliateVideoRecord $record): bool => $record->status === 'submitted')
                    ->schema([Textarea::make('note')->label('Reason shown to the affiliate')->required()->maxLength(500)])
                    ->action(fn (AffiliateVideoRecord $record, array $data) => self::run(fn () => app(AffiliateVideos::class)->reject($record, is_string($data['note'] ?? null) ? $data['note'] : '', SellerProfileResource::actor()))),
            ]);
    }

    private static function run(callable $action): void
    {
        try {
            $action();
        } catch (AffiliateException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return ['index' => ListAffiliateVideos::route('/')];
    }
}
