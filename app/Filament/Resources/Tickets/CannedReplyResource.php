<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tickets;

use App\Domains\Tickets\Enums\TicketTopic;
use App\Domains\Tickets\Models\CannedReply;
use App\Filament\Resources\Tickets\Pages\CreateCannedReply;
use App\Filament\Resources\Tickets\Pages\EditCannedReply;
use App\Filament\Resources\Tickets\Pages\ListCannedReplies;
use App\Filament\Support\Translatable;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Canned replies (spec Part 11) in en/fr/ar; the reply form uses the user's language. */
final class CannedReplyResource extends Resource
{
    protected static ?string $model = CannedReply::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected static ?string $navigationLabel = 'Canned replies';

    protected static ?string $slug = 'canned-replies';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required()->maxLength(120),
            Select::make('topic')->label('Topic (empty = all)')->nullable()
                ->options(collect(TicketTopic::cases())->mapWithKeys(fn (TicketTopic $t): array => [$t->value => str_replace('_', ' ', $t->value)])->all()),
            Translatable::input('body', 'Reply', multiline: true, maxLength: 5000),
            Toggle::make('is_active')->label('Active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('title')
            ->columns([
                TextColumn::make('title')->searchable(),
                TextColumn::make('topic')->formatStateUsing(fn (?TicketTopic $state): string => $state === null ? 'all' : str_replace('_', ' ', $state->value))->placeholder('all'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListCannedReplies::route('/'),
            'create' => CreateCannedReply::route('/create'),
            'edit' => EditCannedReply::route('/{record}/edit'),
        ];
    }
}
