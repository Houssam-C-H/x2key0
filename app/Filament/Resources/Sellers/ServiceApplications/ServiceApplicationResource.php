<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sellers\ServiceApplications;

use App\Domains\Sellers\Actions\ReviewServiceApplication;
use App\Domains\Sellers\Enums\ServiceApplicationStatus;
use App\Domains\Sellers\Models\SellerServiceApplication;
use App\Filament\Resources\Sellers\SellerProfiles\SellerProfileResource;
use App\Filament\Resources\Sellers\ServiceApplications\Pages\ListServiceApplications;
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
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Service application queue (spec 6.9): pass, or refuse with a reason the seller sees.
 */
final class ServiceApplicationResource extends Resource
{
    protected static ?string $model = SellerServiceApplication::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Sellers';

    protected static ?string $navigationLabel = 'Service applications';

    protected static ?string $slug = 'sellers/service-applications';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = SellerServiceApplication::query()->where('status', ServiceApplicationStatus::Pending)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'asc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['profile', 'gameCategory.game', 'gameCategory.type', 'auditor']))
            ->columns([
                TextColumn::make('id'),
                TextColumn::make('profile.store_name')->label('Store')->searchable()
                    ->url(fn (SellerServiceApplication $record): string => SellerProfileResource::getUrl('view', ['record' => $record->seller_profile_id])),
                TextColumn::make('service')->label('Service')
                    ->state(fn (SellerServiceApplication $record): string => $record->gameCategory->title('en')),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (ServiceApplicationStatus $state): string => ucfirst($state->value))
                    ->color(fn (ServiceApplicationStatus $state): string => match ($state) {
                        ServiceApplicationStatus::Passed => 'success',
                        ServiceApplicationStatus::Pending => 'warning',
                        ServiceApplicationStatus::Refused => 'danger',
                    }),
                TextColumn::make('created_at')->label('Submitted')->since()->sortable(),
                TextColumn::make('auditor.username')->label('Reviewer')->placeholder('—'),
                TextColumn::make('reject_reason')->wrap()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(ServiceApplicationStatus::cases())->mapWithKeys(fn (ServiceApplicationStatus $s): array => [$s->value => ucfirst($s->value)])->all())
                    ->default(ServiceApplicationStatus::Pending->value),
            ])
            ->recordActions([
                Action::make('pass')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (SellerServiceApplication $record): bool => $record->status === ServiceApplicationStatus::Pending && Gate::allows('moderate', $record))
                    ->action(function (SellerServiceApplication $record): void {
                        app(ReviewServiceApplication::class)->pass($record, SellerProfileResource::actor());
                        Notification::make()->title('Application passed')->success()->send();
                    }),
                Action::make('refuse')
                    ->color('danger')
                    ->visible(fn (SellerServiceApplication $record): bool => $record->status === ServiceApplicationStatus::Pending && Gate::allows('moderate', $record))
                    ->schema([Textarea::make('reason')->label('Reason shown to the seller')->required()->maxLength(500)])
                    ->action(function (SellerServiceApplication $record, array $data): void {
                        app(ReviewServiceApplication::class)->refuse($record, SellerProfileResource::actor(), SellerProfileResource::reason($data));
                        Notification::make()->title('Application refused')->warning()->send();
                    }),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListServiceApplications::route('/'),
        ];
    }
}
