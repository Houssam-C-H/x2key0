<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Regions;

use App\Domains\Catalog\Models\Region;
use App\Filament\Resources\Catalog\Regions\Pages\CreateRegion;
use App\Filament\Resources\Catalog\Regions\Pages\EditRegion;
use App\Filament\Resources\Catalog\Regions\Pages\ListRegions;
use App\Filament\Support\Translatable;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

final class RegionResource extends Resource
{
    protected static ?string $model = Region::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->required()->regex('/^[A-Z0-9_]+$/')->maxLength(16)->unique(ignoreRecord: true),
            TextInput::make('flag')->maxLength(16)->helperText('Emoji flag, e.g. 🇪🇺'),
            TextInput::make('sort')->numeric()->default(0),
            Translatable::input('name', 'Name'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('flag'),
                TextColumn::make('code')->searchable(),
                TextColumn::make('name.en')->label('Name'),
            ])
            ->recordActions([EditAction::make()]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListRegions::route('/'),
            'create' => CreateRegion::route('/create'),
            'edit' => EditRegion::route('/{record}/edit'),
        ];
    }
}
