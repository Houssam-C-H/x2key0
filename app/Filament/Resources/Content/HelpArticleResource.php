<?php

declare(strict_types=1);

namespace App\Filament\Resources\Content;

use App\Domains\Content\Models\HelpArticle;
use App\Filament\Resources\Content\Pages\CreateHelpArticle;
use App\Filament\Resources\Content\Pages\EditHelpArticle;
use App\Filament\Resources\Content\Pages\ListHelpArticles;
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

/** Help center (spec Part 18): articles per section, Markdown body, order and publication. */
final class HelpArticleResource extends Resource
{
    protected static ?string $model = HelpArticle::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Help center';

    protected static ?string $slug = 'help-articles';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('slug')->required()->maxLength(120)->regex('/^[a-z0-9][a-z0-9-]*$/')->unique(ignoreRecord: true),
            Select::make('section')->options(array_combine(HelpArticle::SECTIONS, ['How to buy', 'How to sell', 'How to cancel', 'Payments', 'Account', 'FAQ']))->required(),
            Translatable::input('title', 'Title'),
            Translatable::input('body', 'Body (Markdown)', multiline: true, maxLength: 20000),
            TextInput::make('sort')->numeric()->default(0)->required(),
            Toggle::make('is_published')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('section')->badge(),
                TextColumn::make('title')->state(fn (HelpArticle $record): string => $record->translate('title', 'en'))->limit(60),
                TextColumn::make('sort'),
                TextColumn::make('is_published')->label('Published')->badge()->formatStateUsing(fn (bool $state): string => $state ? 'yes' : 'no'),
            ])
            ->recordActions([EditAction::make()]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return ['index' => ListHelpArticles::route('/'), 'create' => CreateHelpArticle::route('/create'), 'edit' => EditHelpArticle::route('/{record}/edit')];
    }
}
