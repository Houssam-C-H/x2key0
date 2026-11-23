<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ledger;

use App\Domains\Ledger\Enums\AccountType;
use App\Domains\Ledger\Models\LedgerAccount;
use App\Filament\Resources\Ledger\Pages\ListLedgerAccounts;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Ledger accounts and balances, read-only (spec 7.1). Liabilities are shown as owed (−balance). */
final class LedgerAccountResource extends Resource
{
    protected static ?string $model = LedgerAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Ledger';

    protected static ?string $navigationLabel = 'Accounts';

    protected static ?string $slug = 'ledger/accounts';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('balanceRow'))
            ->columns([
                TextColumn::make('code')->searchable(),
                TextColumn::make('type')->badge()->formatStateUsing(fn (AccountType $state): string => $state->value),
                TextColumn::make('owner')->state(fn (LedgerAccount $record): string => $record->owner_type === 'user' ? "user #{$record->owner_id}" : 'platform'),
                TextColumn::make('currency'),
                TextColumn::make('balance')->label('Balance (debit +)')
                    ->state(fn (LedgerAccount $record): string => number_format($record->balance() / 100, 2)),
                TextColumn::make('owed')->label('Owed / earned')
                    ->state(fn (LedgerAccount $record): string => in_array($record->type, [AccountType::Liability, AccountType::Revenue, AccountType::Equity], true) ? number_format(-$record->balance() / 100, 2) : '—'),
            ])
            ->filters([
                SelectFilter::make('type')->options(collect(AccountType::cases())->mapWithKeys(fn (AccountType $t): array => [$t->value => ucfirst($t->value)])->all()),
                SelectFilter::make('owner_type')->label('Owner')->options(['platform' => 'Platform', 'user' => 'User']),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return ['index' => ListLedgerAccounts::route('/')];
    }
}
