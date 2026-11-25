<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users;

use App\Domains\Identity\Models\AccountDeletionRequest;
use App\Filament\Resources\Users\Pages\ListAccountDeletions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Account deletion requests (spec 6.8): read-only for staff — users request and cancel, the daily job completes. */
final class AccountDeletionResource extends Resource
{
    protected static ?string $model = AccountDeletionRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserMinus;

    protected static string|UnitEnum|null $navigationGroup = 'Users';

    protected static ?string $navigationLabel = 'Account deletions';

    protected static ?string $slug = 'account-deletions';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user:id,username'))
            ->columns([
                TextColumn::make('user.username')->label('User'),
                TextColumn::make('status')->badge(),
                TextColumn::make('blockers')->state(fn (AccountDeletionRequest $record): string => implode(', ', array_map(static fn (string $k): string => (string) __($k), $record->blockers ?? [])))->placeholder('—')->wrap(),
                TextColumn::make('factors')->state(fn (AccountDeletionRequest $record): string => implode(' + ', $record->factors)),
                TextColumn::make('scheduled_for')->dateTime()->placeholder('—'),
                TextColumn::make('completed_at')->dateTime()->placeholder('—'),
                TextColumn::make('created_at')->since(),
            ])
            ->filters([SelectFilter::make('status')->options(['blocked' => 'Blocked', 'scheduled' => 'Scheduled', 'completed' => 'Completed', 'cancelled' => 'Cancelled'])]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return ['index' => ListAccountDeletions::route('/')];
    }
}
