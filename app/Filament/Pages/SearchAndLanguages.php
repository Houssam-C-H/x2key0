<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domains\Catalog\Models\Game;
use App\Domains\Catalog\Models\SearchMiss;
use App\Domains\Identity\Enums\StaffRole;
use App\Filament\Support\Staff;
use App\Shared\Audit\AuditLogger;
use App\Shared\Support\TranslationProgress;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

/**
 * Search and languages (docs/ADMIN_PLAN.md item 14): searches that found nothing, most frequent first, each one
 * turned into a game search alias or dismissed; and translation progress per language (`lang:missing`).
 * Catalog staff (moderators) and admins.
 */
final class SearchAndLanguages extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?string $navigationLabel = 'Search & languages';

    protected static ?string $title = 'Search & languages';

    protected static ?string $slug = 'search-and-languages';

    protected string $view = 'filament.pages.search-and-languages';

    public static function canAccess(): bool
    {
        return Staff::has(StaffRole::Moderator);
    }

    /**
     * @return list<array{locale: string, name: string, kind: string, translated: int, total: int, missing: int, percent: float}>
     */
    public function languages(): array
    {
        return TranslationProgress::all();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Searches with no results')
            ->query(SearchMiss::query())
            ->defaultSort('hits', 'desc')
            ->columns([
                TextColumn::make('query')->searchable()->copyable(),
                TextColumn::make('locale')->badge()->color('gray'),
                TextColumn::make('hits')->numeric()->sortable(),
                TextColumn::make('last_seen_at')->label('Last')->since()->sortable(),
                TextColumn::make('first_seen_at')->label('First')->date()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('resolved_at')->label('Handled')->since()->placeholder('open'),
            ])
            ->filters([
                TernaryFilter::make('resolved')->label('Handled')->default(false)
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('resolved_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('resolved_at'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                SelectFilter::make('locale')->options(fn (): array => SearchMiss::query()->distinct()->orderBy('locale')->pluck('locale', 'locale')->all()),
            ])
            ->recordActions([
                Action::make('alias')->label('Add as game alias')->icon(Heroicon::OutlinedTag)
                    ->visible(fn (SearchMiss $record): bool => $record->resolved_at === null)
                    ->schema([
                        Select::make('game_id')->label('Game')->required()->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => Game::query()->where(fn (Builder $q) => $q->where('slug', 'like', '%'.str_replace(' ', '-', mb_strtolower($search)).'%')->orWhere('name', 'like', '%'.$search.'%'))->limit(20)->get()->mapWithKeys(fn (Game $g): array => [$g->id => $g->translate('name', 'en')])->all())
                            ->getOptionLabelUsing(fn (mixed $value): ?string => is_numeric($value) ? Game::query()->find((int) $value)?->translate('name', 'en') : null),
                    ])
                    ->action(function (SearchMiss $record, array $data): void {
                        $game = Game::query()->find(is_numeric($data['game_id'] ?? null) ? (int) $data['game_id'] : 0);
                        if ($game === null) {
                            return;
                        }
                        $aliases = array_values(array_unique([...($game->search_aliases ?? []), $record->query]));
                        $game->forceFill(['search_aliases' => $aliases])->save(); // Auditable; catalog cache and search index follow the save
                        $record->forceFill(['resolved_at' => now()])->save();
                        Notification::make()->success()->title('“'.$record->query.'” now finds '.$game->translate('name', 'en'))->send();
                    }),
                Action::make('dismiss')->icon(Heroicon::OutlinedXMark)->color('gray')
                    ->visible(fn (SearchMiss $record): bool => $record->resolved_at === null)
                    ->action(fn (SearchMiss $record) => $this->dismiss($record)),
            ])
            ->toolbarActions([
                BulkAction::make('dismiss_all')->label('Dismiss selected')->icon(Heroicon::OutlinedXMark)
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records): void {
                        foreach ($records as $record) {
                            if ($record instanceof SearchMiss) {
                                $this->dismiss($record, notify: false);
                            }
                        }
                        Notification::make()->success()->title($records->count().' searches dismissed')->send();
                    }),
            ]);
    }

    private function dismiss(SearchMiss $record, bool $notify = true): void
    {
        $record->forceFill(['resolved_at' => now()])->save();
        app(AuditLogger::class)->log('search.miss_dismissed', $record, null, ['query' => $record->query], Staff::current());
        if ($notify) {
            Notification::make()->success()->title('Dismissed')->send();
        }
    }
}
