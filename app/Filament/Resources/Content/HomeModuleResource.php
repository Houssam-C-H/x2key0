<?php

declare(strict_types=1);

namespace App\Filament\Resources\Content;

use App\Domains\Content\Models\HomeModule;
use App\Filament\Resources\Content\Pages\EditHomeModule;
use App\Filament\Resources\Content\Pages\ListHomeModules;
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
use UnitEnum;

/** Home modules (spec Part 18 "Home modules (pin/sort items per module)"): order, on/off, title, number of tiles. */
final class HomeModuleResource extends Resource
{
    protected static ?string $model = HomeModule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $slug = 'home-modules';

    protected static ?int $navigationSort = 0;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->disabled(),
            Translatable::input('title', 'Section title (empty = default)', required: false),
            TextInput::make('config.limit')->label('Number of items (empty = default)')->numeric()->minValue(1)->maxValue(40),
            TextInput::make('sort')->numeric()->required(),
            Toggle::make('is_enabled')->label('Shown on the home page'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('sort'),
                TextColumn::make('code')->badge(),
                TextColumn::make('title')->state(fn (HomeModule $record): string => $record->title === null ? '—' : $record->translate('title', 'en')),
                TextColumn::make('items_count')->counts('items')->label('Pinned'),
                ToggleColumn::make('is_enabled')->label('Shown'),
            ])
            ->recordActions([EditAction::make()]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalise(array $data): array
    {
        $config = is_array($data['config'] ?? null) ? $data['config'] : [];
        $limit = $config['limit'] ?? null;
        $data['config'] = is_numeric($limit) ? ['limit' => (int) $limit] : null;
        unset($data['code']);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return ['index' => ListHomeModules::route('/'), 'edit' => EditHomeModule::route('/{record}/edit')];
    }
}
