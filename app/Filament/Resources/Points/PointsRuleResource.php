<?php

declare(strict_types=1);

namespace App\Filament\Resources\Points;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Points\Models\PointsRule;
use App\Filament\Resources\Points\Pages\EditPointsRule;
use App\Filament\Resources\Points\Pages\ListPointsRules;
use App\Filament\Support\Staff;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * X2K Score rules (docs/ADMIN_PLAN.md item 7): how many points each trigger gives. Rules are seeded
 * (PointsRuleSeeder); staff change amounts and switch them on or off. PointsRule is Auditable.
 */
final class PointsRuleResource extends Resource
{
    protected static ?string $model = PointsRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static string|UnitEnum|null $navigationGroup = 'Points';

    protected static ?string $navigationLabel = 'Rules';

    protected static ?string $slug = 'points/rules';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return Staff::has(StaffRole::Finance);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('code')->disabled()->dehydrated(false),
            TextInput::make('trigger')->disabled()->dehydrated(false),
            TextInput::make('amount_per_usd')->label('Points per USD')->integer()->minValue(0)->maxValue(100000)
                ->helperText('Used by spending rules (order completed). Empty for fixed rules.'),
            TextInput::make('fixed')->label('Fixed points')->integer()->minValue(0)->maxValue(1000000)
                ->helperText('Used by one-off rules (first order, comment, daily sign-in).'),
            Toggle::make('multiplier_by_level')->label('Multiply by the buyer level'),
            Toggle::make('is_enabled')->label('On'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->columns([
                TextColumn::make('code')->searchable(),
                TextColumn::make('trigger')->badge()->color('gray'),
                TextColumn::make('amount_per_usd')->label('Per USD')->placeholder('—'),
                TextColumn::make('fixed')->placeholder('—'),
                IconColumn::make('multiplier_by_level')->label('× level')->boolean(),
                IconColumn::make('is_enabled')->label('On')->boolean(),
                TextColumn::make('updated_at')->since(),
            ])
            ->recordActions([EditAction::make()]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListPointsRules::route('/'),
            'edit' => EditPointsRule::route('/{record}/edit'),
        ];
    }
}
