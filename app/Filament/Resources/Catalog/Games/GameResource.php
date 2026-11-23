<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Games;

use App\Domains\Catalog\Models\Game;
use App\Filament\Resources\Catalog\Games\Pages\CreateGame;
use App\Filament\Resources\Catalog\Games\Pages\EditGame;
use App\Filament\Resources\Catalog\Games\Pages\ListGames;
use App\Filament\Resources\Catalog\Games\RelationManagers\CategoriesRelationManager;
use App\Filament\Support\Translatable;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

final class GameResource extends Resource
{
    protected static ?string $model = Game::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'slug';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Game')->columns(3)->schema([
                TextInput::make('slug')
                    ->required()
                    ->maxLength(96)
                    ->regex('/^[a-z0-9][a-z0-9-]*$/')
                    ->notIn(Game::RESERVED_SLUGS)
                    ->unique(ignoreRecord: true)
                    ->helperText('URL: /{locale}/{slug}. Cannot be a reserved word (games, search, login…).'),
                TextInput::make('publisher')->maxLength(128),
                TagsInput::make('search_aliases')->helperText('Extra search terms, e.g. "cod", "fifa".'),
                Toggle::make('is_enabled')->default(true)->label('Enabled'),
                Toggle::make('is_hot')->label('Hot (mega menu, Game Index HOT tab)'),
                Toggle::make('is_new')->label('New'),
                Translatable::input('name', 'Name'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('slug')
            ->columns([
                TextColumn::make('name.en')->label('Name')->searchable(query: fn (Builder $query, string $search) => $query->where('slug', 'like', '%'.str($search)->slug().'%')),
                TextColumn::make('slug'),
                TextColumn::make('categories_count')->counts('categories')->label('Categories'),
                TextColumn::make('product_count_cached')->label('Products')->sortable(),
                ToggleColumn::make('is_hot')->label('Hot'),
                ToggleColumn::make('is_enabled')->label('Enabled'),
            ])
            ->filters([
                TernaryFilter::make('is_enabled')->label('Enabled'),
                TernaryFilter::make('is_hot')->label('Hot'),
            ])
            ->recordActions([EditAction::make()]);
    }

    /**
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [CategoriesRelationManager::class];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListGames::route('/'),
            'create' => CreateGame::route('/create'),
            'edit' => EditGame::route('/{record}/edit'),
        ];
    }
}
