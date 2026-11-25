<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\CategoryTypes;

use App\Domains\Catalog\Enums\CategoryTypeCode;
use App\Domains\Catalog\Models\CategoryType;
use App\Filament\Resources\Catalog\CategoryTypes\Pages\EditCategoryType;
use App\Filament\Resources\Catalog\CategoryTypes\Pages\ListCategoryTypes;
use App\Filament\Resources\Catalog\CategoryTypes\RelationManagers\ServiceTypesRelationManager;
use App\Filament\Support\Translatable;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The 15 category types (spec 2.1 / D3): names, order, enabled, and their service types (ADR-032). Never created
 * or deleted (code depends on them).
 */
final class CategoryTypeResource extends Resource
{
    protected static ?string $model = CategoryType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->disabled()->dehydrated(false)
                ->formatStateUsing(fn (mixed $state): string => $state instanceof CategoryTypeCode ? $state->value : (is_scalar($state) ? (string) $state : '')),
            TextInput::make('sort')->numeric()->required(),
            Toggle::make('is_enabled')->label('Enabled (shown in menus and listings)'),
            Translatable::input('name', 'Name'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('code')->formatStateUsing(fn (CategoryTypeCode $state): string => $state->value),
                TextColumn::make('name.en')->label('Name'),
                ToggleColumn::make('is_enabled')->label('Enabled'),
                TextColumn::make('game_categories_count')->counts('gameCategories')->label('Games'),
                TextColumn::make('service_types_count')->counts(['serviceTypes' => fn (Builder $q) => $q->where('is_enabled', true)])->label('Services on'),
            ])
            ->recordActions([EditAction::make()]);
    }

    /**
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [ServiceTypesRelationManager::class];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListCategoryTypes::route('/'),
            'edit' => EditCategoryType::route('/{record}/edit'),
        ];
    }
}
