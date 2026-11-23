<?php

declare(strict_types=1);

namespace App\Filament\Resources\Announcements;

use App\Domains\Announcements\Models\Announcement;
use App\Filament\Resources\Announcements\Pages\CreateAnnouncement;
use App\Filament\Resources\Announcements\Pages\EditAnnouncement;
use App\Filament\Resources\Announcements\Pages\ListAnnouncements;
use App\Filament\Support\Translatable;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/** Announcements (spec Part 18: compose, audience, schedule). Users see them in the announcement centre. */
final class AnnouncementResource extends Resource
{
    protected static ?string $model = Announcement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Translatable::input('title', 'Title'),
            Translatable::input('body', 'Message', multiline: true, maxLength: 4000),
            Select::make('audience')->options(array_combine(Announcement::AUDIENCES, ['Everyone', 'Buyers', 'Sellers']))->required()->default('all'),
            Select::make('category')->options(array_combine(Announcement::CATEGORIES, array_map(fn (string $c): string => ucfirst(str_replace('_', ' ', $c)), Announcement::CATEGORIES)))->required()->default('announcement'),
            DateTimePicker::make('publish_at')->required()->default(now()),
            DateTimePicker::make('expires_at')->label('Expires (empty = never)')->after('publish_at'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('publish_at', 'desc')
            ->columns([
                TextColumn::make('title')->state(fn (Announcement $record): string => $record->translate('title', 'en'))->limit(50),
                TextColumn::make('audience')->badge(),
                TextColumn::make('category')->badge(),
                TextColumn::make('publish_at')->dateTime()->sortable(),
                TextColumn::make('expires_at')->dateTime()->placeholder('never'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withAuthor(array $data): array
    {
        $data['created_by'] = Auth::id();

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListAnnouncements::route('/'),
            'create' => CreateAnnouncement::route('/create'),
            'edit' => EditAnnouncement::route('/{record}/edit'),
        ];
    }
}
