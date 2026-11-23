<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ledger;

use App\Domains\Ledger\Enums\JournalType;
use App\Domains\Ledger\Models\LedgerJournal;
use App\Domains\Ledger\Models\LedgerPosting;
use App\Filament\Resources\Ledger\Pages\ListLedgerJournals;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Ledger journals, read-only (spec Part 18). Each row lists its postings: account, owner, signed amount. */
final class LedgerJournalResource extends Resource
{
    protected static ?string $model = LedgerJournal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Ledger';

    protected static ?string $navigationLabel = 'Journals';

    protected static ?string $slug = 'ledger/journals';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('postings.account'))
            ->columns([
                TextColumn::make('id'),
                TextColumn::make('type')->badge()->formatStateUsing(fn (JournalType $state): string => $state->value),
                TextColumn::make('description')->searchable()->limit(40),
                TextColumn::make('lines')->html(false)->wrap()
                    ->state(fn (LedgerJournal $record): string => $record->postings->map(fn (LedgerPosting $p): string => sprintf(
                        '%s%s %s%s',
                        $p->account->code,
                        $p->account->owner_type === 'user' ? "(user {$p->account->owner_id})" : '',
                        $p->amount > 0 ? '+' : '−',
                        number_format(abs($p->amount) / 100, 2).' '.$p->currency,
                    ))->implode(' · ')),
                TextColumn::make('idempotency_key')->label('Key')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('posted_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')->options(collect(JournalType::cases())->mapWithKeys(fn (JournalType $t): array => [$t->value => $t->value])->all()),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return ['index' => ListLedgerJournals::route('/')];
    }
}
