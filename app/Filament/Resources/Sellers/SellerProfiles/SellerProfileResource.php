<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sellers\SellerProfiles;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Enums\SellerStatus;
use App\Domains\Sellers\Models\SellerLevel;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Sellers\StateMachines\SellerProfileStateMachine;
use App\Domains\Storage\Actions\SetStorageAccess;
use App\Filament\Resources\Sellers\SellerProfiles\Pages\EditSellerProfile;
use App\Filament\Resources\Sellers\SellerProfiles\Pages\ListSellerProfiles;
use App\Filament\Resources\Sellers\SellerProfiles\Pages\ViewSellerProfile;
use App\Filament\Resources\Sellers\SellerProfiles\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\Sellers\SellerProfiles\RelationManagers\StatusChangesRelationManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Stores (spec Part 18): level override, merchant manager, payouts flag, and status actions that go
 * through SellerProfileStateMachine (suspend / reinstate / ban, with reason). Stores are created by
 * seller activation only.
 */
final class SellerProfileResource extends Resource
{
    protected static ?string $model = SellerProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'Sellers';

    protected static ?string $navigationLabel = 'Stores';

    protected static ?string $slug = 'sellers/stores';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('store_name')->required()->minLength(3)->maxLength(40)->unique(ignoreRecord: true),
            TextInput::make('slug')->disabled()->dehydrated(false),
            Select::make('seller_level_id')
                ->label('Seller level')
                ->options(fn (): array => SellerLevel::query()->orderBy('sort')->get()->mapWithKeys(fn (SellerLevel $l): array => [$l->id => $l->translate('name', 'en')])->all())
                ->required(),
            Toggle::make('level_locked')
                ->label('Lock level (staff override)')
                ->helperText('The level recompute job leaves locked stores alone.'),
            Select::make('merchant_manager_id')
                ->label('Merchant manager')
                ->options(fn (): array => User::role(StaffRole::MerchantManager->value)->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->nullable(),
            Toggle::make('payouts_enabled')
                ->label('Payouts enabled')
                ->helperText('Withdrawals also need identity verification and 2FA (spec 6.3). Finance only.')
                ->disabled(fn (): bool => ! self::isFinance()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'level', 'merchantManager']))
            ->columns([
                TextColumn::make('store_name')->searchable()->sortable(),
                TextColumn::make('user.username')->label('User')->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (SellerStatus $state): string => ucfirst($state->value))
                    ->color(fn (SellerStatus $state): string => match ($state) {
                        SellerStatus::Active => 'success',
                        SellerStatus::Vacation => 'warning',
                        SellerStatus::Suspended, SellerStatus::Banned => 'danger',
                        SellerStatus::Inactive => 'gray',
                    }),
                TextColumn::make('level.code')->label('Level')->badge(),
                TextColumn::make('merchantManager.name')->label('Manager')->placeholder('—'),
                TextColumn::make('activated_at')->label('Activated')->since()->sortable(),
                IconColumn::make('storage_enabled')->label('Storage')->boolean()->tooltip(fn (SellerProfile $record): string => $record->storage_enabled ? 'Storage on' : 'Storage switched off'),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(SellerStatus::cases())->mapWithKeys(fn (SellerStatus $s): array => [$s->value => ucfirst($s->value)])->all()),
                SelectFilter::make('seller_level_id')->label('Level')->relationship('level', 'code'),
                TernaryFilter::make('storage_enabled')->label('Storage'),
            ])
            ->recordActions([
                ViewAction::make()->label('Open'),
                EditAction::make(),
                Action::make('view_store')
                    ->label('Public page')
                    ->url(fn (SellerProfile $record): string => $record->path('en'), shouldOpenInNewTab: true)
                    ->visible(fn (SellerProfile $record): bool => $record->status->isPublic()),
                ...self::statusActions(),
            ]);
    }

    /**
     * Status and Storage decisions on one store, shared by the list and the store page.
     *
     * @return list<Action>
     */
    public static function statusActions(): array
    {
        return [
            Action::make('suspend')
                ->color('danger')
                ->visible(fn (SellerProfile $record): bool => $record->status->canTransitionTo(SellerStatus::Suspended) && Gate::allows('moderate', $record))
                ->schema([Textarea::make('reason')->label('Reason shown to the seller')->required()->maxLength(500)])
                ->action(function (SellerProfile $record, array $data): void {
                    app(SellerProfileStateMachine::class)->suspend($record, self::actor(), self::reason($data));
                    Notification::make()->title('Store suspended')->warning()->send();
                }),
            Action::make('reinstate')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (SellerProfile $record): bool => $record->status === SellerStatus::Suspended && Gate::allows('moderate', $record))
                ->action(function (SellerProfile $record): void {
                    app(SellerProfileStateMachine::class)->reinstate($record, self::actor());
                    Notification::make()->title('Store reinstated')->success()->send();
                }),
            // Storage switch (ADR-034): off = no Storage writes, Storage offers out of stock; on = restored.
            Action::make('storage_off')
                ->label('Storage off')
                ->color('warning')
                ->icon(Heroicon::OutlinedArchiveBoxXMark)
                ->visible(fn (SellerProfile $record): bool => $record->storage_enabled && Gate::allows('moderate', $record))
                ->modalDescription('The seller cannot add or change Storage items and every offer delivered from Storage goes out of stock. Keys stay encrypted; orders already reserved still deliver.')
                ->schema([Textarea::make('reason')->label('Reason (audit log)')->required()->maxLength(500)])
                ->action(function (SellerProfile $record, array $data): void {
                    app(SetStorageAccess::class)->handle($record, false, self::actor(), self::reason($data));
                    Notification::make()->title('Storage switched off')->warning()->send();
                }),
            Action::make('storage_on')
                ->label('Storage on')
                ->color('success')
                ->icon(Heroicon::OutlinedArchiveBox)
                ->visible(fn (SellerProfile $record): bool => ! $record->storage_enabled && Gate::allows('moderate', $record))
                ->schema([Textarea::make('reason')->label('Reason (audit log)')->required()->maxLength(500)])
                ->action(function (SellerProfile $record, array $data): void {
                    app(SetStorageAccess::class)->handle($record, true, self::actor(), self::reason($data));
                    Notification::make()->title('Storage switched on; stock recounted')->success()->send();
                }),
            Action::make('ban')
                ->color('danger')
                ->visible(fn (SellerProfile $record): bool => $record->status->canTransitionTo(SellerStatus::Banned) && Gate::allows('moderate', $record))
                ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(500)])
                ->requiresConfirmation()
                ->action(function (SellerProfile $record, array $data): void {
                    app(SellerProfileStateMachine::class)->ban($record, self::actor(), self::reason($data));
                    Notification::make()->title('Store banned')->danger()->send();
                }),
        ];
    }

    /**
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [ContactsRelationManager::class, StatusChangesRelationManager::class];
    }

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['store_name', 'slug', 'user.username'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return $record instanceof SellerProfile ? 'Store: '.$record->store_name : '';
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return $record instanceof SellerProfile ? ['Owner' => $record->user->username, 'Status' => $record->status->value] : [];
    }

    public static function getGlobalSearchResultUrl(Model $record): string
    {
        return self::getUrl('view', ['record' => $record]);
    }

    /**
     * @return Builder<Model>
     */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('user');
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListSellerProfiles::route('/'),
            'view' => ViewSellerProfile::route('/{record}'),
            'edit' => EditSellerProfile::route('/{record}/edit'),
        ];
    }

    public static function actor(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function reason(array $data): string
    {
        return is_string($data['reason'] ?? null) ? $data['reason'] : '';
    }

    private static function isFinance(): bool
    {
        $user = Auth::user();

        return $user instanceof User && ($user->hasStaffRole(StaffRole::Finance) || $user->hasStaffRole(StaffRole::Admin));
    }
}
