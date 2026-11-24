<?php

declare(strict_types=1);

namespace App\Filament\Resources\Content;

use App\Domains\Content\Models\NewsArticle;
use App\Domains\Content\Support\HomePresenter;
use App\Filament\Resources\Content\Pages\CreateNewsArticle;
use App\Filament\Resources\Content\Pages\EditNewsArticle;
use App\Filament\Resources\Content\Pages\ListNewsArticles;
use App\Filament\Support\Translatable;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Game News (spec Part 18 "News"): Markdown body, cover, game, draft / published with a publication time. */
final class NewsArticleResource extends Resource
{
    protected static ?string $model = NewsArticle::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'News';

    protected static ?string $slug = 'news';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('slug')->required()->maxLength(120)->regex('/^[a-z0-9][a-z0-9-]*$/')->unique(ignoreRecord: true),
            Translatable::input('title', 'Title'),
            Translatable::input('excerpt', 'Excerpt', required: false, multiline: true, maxLength: 500),
            Translatable::input('body', 'Body (Markdown)', multiline: true, maxLength: 20000),
            FileUpload::make('cover_path')->label('Cover')->image()->disk('public')->directory('news')->maxSize(4096),
            Select::make('game_id')->label('Game')->searchable()->options(fn (): array => HomePresenter::gameOptions()),
            Select::make('status')->options(['draft' => 'Draft', 'published' => 'Published'])->required()->default('draft'),
            DateTimePicker::make('published_at')->default(now()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('published_at', 'desc')
            ->columns([
                TextColumn::make('title')->state(fn (NewsArticle $record): string => $record->translate('title', 'en'))->limit(50)->searchable(query: fn (Builder $query, string $search): Builder => $query->where('slug', 'like', "%{$search}%")),
                TextColumn::make('slug'),
                TextColumn::make('status')->badge()->color(fn (string $state): string => $state === 'published' ? 'success' : 'gray'),
                TextColumn::make('published_at')->dateTime()->sortable(),
            ])
            ->recordActions([EditAction::make()]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return ['index' => ListNewsArticles::route('/'), 'create' => CreateNewsArticle::route('/create'), 'edit' => EditNewsArticle::route('/{record}/edit')];
    }
}
