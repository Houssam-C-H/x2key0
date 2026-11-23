<?php

declare(strict_types=1);

namespace App\Filament\Resources\Violations;

use App\Domains\Violations\Enums\Penalty;
use App\Domains\Violations\Models\ViolationRule;
use App\Filament\Resources\Violations\Pages\EditViolationRule;
use App\Filament\Resources\Violations\Pages\ListViolationRules;
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

/** Violation rules (spec 5.17): points, penalty, enabled. Codes are seeded (automatic rules depend on them). */
final class ViolationRuleResource extends Resource
{
    protected static ?string $model = ViolationRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static string|UnitEnum|null $navigationGroup = 'Sellers';

    protected static ?string $navigationLabel = 'Violation rules';

    protected static ?string $slug = 'sellers/violation-rules';

    protected static ?int $navigationSort = 5;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->disabled()->dehydrated(false),
            Translatable::input('name', 'Name', maxLength: 64),
            TextInput::make('points')->numeric()->minValue(0)->maxValue(1000)->required(),
            Select::make('penalty')->options(Penalty::options())->required(),
            Toggle::make('is_enabled')->label('Enabled'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->paginated(false)
            ->columns([
                TextColumn::make('code')->badge(),
                TextColumn::make('name.en')->label('Name'),
                TextColumn::make('points'),
                TextColumn::make('penalty')->formatStateUsing(fn (Penalty $state): string => Penalty::options()[$state->value]),
                IconColumn::make('auto')->boolean(),
                IconColumn::make('is_enabled')->label('Enabled')->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListViolationRules::route('/'),
            'edit' => EditViolationRule::route('/{record}/edit'),
        ];
    }
}
