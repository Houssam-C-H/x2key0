<?php

declare(strict_types=1);

namespace App\Filament\Resources\Marketing;

use App\Domains\Campaigns\Actions\CampaignParticipations;
use App\Domains\Campaigns\Models\CampaignParticipation;
use App\Domains\Coupons\Exceptions\CouponException;
use App\Domains\Offers\Models\Offer;
use App\Filament\Resources\Marketing\Pages\ListCampaignParticipations;
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

/** Campaign applications (spec 14.2): approve (offers get the badge while the campaign runs) or reject with a reason. */
final class CampaignParticipationResource extends Resource
{
    protected static ?string $model = CampaignParticipation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHandRaised;

    protected static string|UnitEnum|null $navigationGroup = 'Marketing';

    protected static ?string $navigationLabel = 'Campaign applications';

    protected static ?string $slug = 'campaign-applications';

    protected static ?int $navigationSort = 3;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = CampaignParticipation::query()->where('status', CampaignParticipation::APPLIED)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['campaign', 'seller']))
            ->columns([
                TextColumn::make('campaign')->state(fn (CampaignParticipation $record): string => $record->campaign->translate('name', 'en'))->limit(30),
                TextColumn::make('seller.store_name')->label('Store'),
                TextColumn::make('offers')->wrap()->limit(120)->state(fn (CampaignParticipation $record): string => Offer::query()->whereIn('id', $record->offer_ids)->pluck('title')->implode(' · ')),
                TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                    'approved' => 'success',
                    'rejected' => 'danger',
                    'applied' => 'warning',
                    default => 'gray',
                }),
                TextColumn::make('created_at')->since(),
            ])
            ->filters([SelectFilter::make('status')->options(['applied' => 'Applied', 'approved' => 'Approved', 'rejected' => 'Rejected', 'withdrawn' => 'Withdrawn'])->default('applied')])
            ->recordActions([
                Action::make('approve')->color('success')->requiresConfirmation()
                    ->visible(fn (CampaignParticipation $record): bool => $record->status === CampaignParticipation::APPLIED)
                    ->action(fn (CampaignParticipation $record) => self::decide($record, true, null)),
                Action::make('reject')->color('danger')
                    ->visible(fn (CampaignParticipation $record): bool => $record->status === CampaignParticipation::APPLIED)
                    ->schema([Textarea::make('reason')->label('Reason shown to the seller')->required()->maxLength(500)])
                    ->action(fn (CampaignParticipation $record, array $data) => self::decide($record, false, is_string($data['reason'] ?? null) ? $data['reason'] : '')),
            ]);
    }

    private static function decide(CampaignParticipation $record, bool $approve, ?string $reason): void
    {
        try {
            app(CampaignParticipations::class)->decide($record, $approve, SellerProfileResource::actor(), $reason);
        } catch (CouponException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return ['index' => ListCampaignParticipations::route('/')];
    }
}
