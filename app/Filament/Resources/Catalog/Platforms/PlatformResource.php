<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Platforms;

use App\Domains\Catalog\Models\Platform;
use App\Filament\Resources\Catalog\Platforms\Pages\CreatePlatform;
use App\Filament\Resources\Catalog\Platforms\Pages\EditPlatform;
use App\Filament\Resources\Catalog\Platforms\Pages\ListPlatforms;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

final class PlatformResource extends Resource
{
    protected static ?string $model = Platform::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedComputerDesktop;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('slug')->required()->regex('/^[a-z0-9-]+$/')->maxLength(48)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule),
            TextInput::make('name')->required()->maxLength(64),
            TextInput::make('sort')->numeric()->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('slug')->searchable(),
            ])
            ->recordActions([EditAction::make()]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListPlatforms::route('/'),
            'create' => CreatePlatform::route('/create'),
            'edit' => EditPlatform::route('/{record}/edit'),
        ];
    }
}
