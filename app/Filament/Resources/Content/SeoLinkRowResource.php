<?php

declare(strict_types=1);

namespace App\Filament\Resources\Content;

use App\Domains\Content\Models\SeoLinkRow;
use App\Filament\Resources\Content\Pages\CreateSeoLinkRow;
use App\Filament\Resources\Content\Pages\EditSeoLinkRow;
use App\Filament\Resources\Content\Pages\ListSeoLinkRows;
use App\Filament\Support\Translatable;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** SEO link rows (spec Part 18): the keyword links in the footer. Only site paths (starting with /). */
final class SeoLinkRowResource extends Resource
{
    protected static ?string $model = SeoLinkRow::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'SEO links';

    protected static ?string $slug = 'seo-links';

    protected static ?int $navigationSort = 7;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Translatable::input('label', 'Label'),
            TextInput::make('url')->label('Path (e.g. /en/catalog/currency)')->required()->maxLength(500)->regex('#^/(?!/)#'),
            TextInput::make('sort')->numeric()->default(0)->required(),
            Toggle::make('is_enabled')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('label')->state(fn (SeoLinkRow $record): string => $record->translate('label', 'en')),
                TextColumn::make('url'),
                TextColumn::make('sort'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return ['index' => ListSeoLinkRows::route('/'), 'create' => CreateSeoLinkRow::route('/create'), 'edit' => EditSeoLinkRow::route('/{record}/edit')];
    }
}
