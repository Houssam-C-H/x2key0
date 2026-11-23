<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sellers\SellerLevels;

use App\Domains\Sellers\Models\SellerLevel;
use App\Filament\Resources\Sellers\SellerLevels\Pages\EditSellerLevel;
use App\Filament\Resources\Sellers\SellerLevels\Pages\ListSellerLevels;
use App\Filament\Support\Translatable;
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
use UnitEnum;

/**
 * Seller tiers (spec 3.3): commission, holds, reserve, offer limit, auto-approval, ranking boost and the
 * thresholds used by the recompute job (M16). Codes are seeded; admins edit values (audited).
 */
final class SellerLevelResource extends Resource
{
    protected static ?string $model = SellerLevel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static string|UnitEnum|null $navigationGroup = 'Sellers';

    protected static ?string $navigationLabel = 'Seller levels';

    protected static ?string $slug = 'sellers/levels';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->disabled()->dehydrated(false),
            Translatable::input('name', 'Name', maxLength: 64),
            TextInput::make('stars')->numeric()->minValue(0)->maxValue(5)->required(),
            TextInput::make('commission_bps')->label('Commission (bps)')->numeric()->minValue(0)->maxValue(5000)->required(),
            TextInput::make('hold_days')->numeric()->minValue(0)->maxValue(90)->required(),
            TextInput::make('reserve_bps')->label('Rolling reserve (bps)')->numeric()->minValue(0)->maxValue(5000)->required(),
            TextInput::make('max_active_offers')->numeric()->minValue(1)->required(),
            Toggle::make('auto_approve')->label('Auto-approve offers and service applications'),
            TextInput::make('rank_boost')->numeric()->minValue(-10)->maxValue(10)->required(),
            TextInput::make('min_orders_completed')->numeric()->minValue(0)->required(),
            TextInput::make('min_completion_rate_bps')->label('Min completion rate (bps)')->numeric()->minValue(0)->maxValue(10000)->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->paginated(false)
            ->columns([
                TextColumn::make('code')->badge(),
                TextColumn::make('name.en')->label('Name'),
                TextColumn::make('stars'),
                TextColumn::make('commission_bps')->label('Commission')->formatStateUsing(fn (int $state): string => ($state / 100).'%'),
                TextColumn::make('hold_days')->label('Hold'),
                TextColumn::make('reserve_bps')->label('Reserve')->formatStateUsing(fn (int $state): string => ($state / 100).'%'),
                TextColumn::make('max_active_offers')->label('Max offers'),
                IconColumn::make('auto_approve')->boolean(),
                TextColumn::make('profiles_count')->counts('profiles')->label('Stores'),
            ])
            ->recordActions([EditAction::make()]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListSellerLevels::route('/'),
            'edit' => EditSellerLevel::route('/{record}/edit'),
        ];
    }
}
