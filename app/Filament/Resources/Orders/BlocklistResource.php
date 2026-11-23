<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders;

use App\Domains\Risk\Models\BlocklistEntry;
use App\Filament\Resources\Orders\Pages\CreateBlocklistEntry;
use App\Filament\Resources\Orders\Pages\EditBlocklistEntry;
use App\Filament\Resources\Orders\Pages\ListBlocklist;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Blocklist (spec 5.17 / 17.1): checkouts from a listed e-mail domain or IP are blocked. Audited. */
final class BlocklistResource extends Resource
{
    protected static ?string $model = BlocklistEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    protected static string|UnitEnum|null $navigationGroup = 'Orders & risk';

    protected static ?string $navigationLabel = 'Blocklist';

    protected static ?string $slug = 'blocklist';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')->options(array_combine(BlocklistEntry::TYPES, BlocklistEntry::TYPES))->required(),
            TextInput::make('value')->required()->maxLength(128)
                ->dehydrateStateUsing(fn (?string $state): string => mb_strtolower(trim((string) $state)))
                ->helperText('Lower-cased. E-mail domain without "@", e.g. "spam.example".'),
            TextInput::make('reason')->maxLength(255),
            DateTimePicker::make('expires_at')->label('Expires (empty = never)'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('type')->badge(),
                TextColumn::make('value')->searchable(),
                TextColumn::make('reason')->limit(60),
                TextColumn::make('expires_at')->dateTime()->placeholder('never'),
                TextColumn::make('created_at')->since(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListBlocklist::route('/'),
            'create' => CreateBlocklistEntry::route('/create'),
            'edit' => EditBlocklistEntry::route('/{record}/edit'),
        ];
    }
}
