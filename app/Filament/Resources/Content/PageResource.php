<?php

declare(strict_types=1);

namespace App\Filament\Resources\Content;

use App\Domains\Content\Models\Page;
use App\Filament\Resources\Content\Pages\CreatePage;
use App\Filament\Resources\Content\Pages\EditPage;
use App\Filament\Resources\Content\Pages\ListPages;
use App\Filament\Support\Translatable;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Pages & footer (spec Part 18): about, contact, legal and policy texts, and which footer column lists them. */
final class PageResource extends Resource
{
    protected static ?string $model = Page::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Pages & footer';

    protected static ?string $slug = 'pages';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('slug')->required()->maxLength(120)->regex('/^[a-z0-9][a-z0-9-]*$/')->unique(ignoreRecord: true),
            Translatable::input('title', 'Title'),
            Translatable::input('body', 'Body (Markdown)', multiline: true, maxLength: 50000),
            Select::make('footer_column')->label('Footer column (empty = not in the footer)')->options(array_combine(Page::FOOTER_COLUMNS, ['Company', 'Support', 'Legal', 'Policies'])),
            TextInput::make('sort')->numeric()->default(0)->required(),
            Toggle::make('is_published')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('slug'),
                TextColumn::make('title')->state(fn (Page $record): string => $record->translate('title', 'en'))->limit(50),
                TextColumn::make('footer_column')->badge()->placeholder('—'),
                TextColumn::make('updated_at')->since(),
            ])
            ->recordActions([EditAction::make()]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return ['index' => ListPages::route('/'), 'create' => CreatePage::route('/create'), 'edit' => EditPage::route('/{record}/edit')];
    }
}
