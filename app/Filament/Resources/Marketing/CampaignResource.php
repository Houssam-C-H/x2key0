<?php

declare(strict_types=1);

namespace App\Filament\Resources\Marketing;

use App\Domains\Campaigns\Models\Campaign;
use App\Domains\Catalog\Models\Game;
use App\Filament\Resources\Marketing\Pages\CreateCampaign;
use App\Filament\Resources\Marketing\Pages\EditCampaign;
use App\Filament\Resources\Marketing\Pages\ListCampaigns;
use App\Filament\Support\Translatable;
use BackedEnum;
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

/** Marketing campaigns sellers apply to (spec 14.2, Part 18). Applications are decided in Campaign applications. */
final class CampaignResource extends Resource
{
    protected static ?string $model = Campaign::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Marketing';

    protected static ?string $slug = 'campaigns';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Translatable::input('name', 'Name'),
            Translatable::input('description', 'Description (shown to sellers)', multiline: true, maxLength: 2000, required: false),
            Select::make('type')->options(array_combine(Campaign::TYPES, ['Discount event', 'Flash sale', 'Featured slot', 'New game launch']))->required(),
            Select::make('rules.game_ids')->label('Only offers of these games (empty = all)')->multiple()->searchable()
                ->options(fn (): array => Game::query()->orderBy('slug')->limit(500)->get()->mapWithKeys(fn (Game $g): array => [$g->id => $g->translate('name', 'en')])->all()),
            TextInput::make('platform_subsidy_bps')->label('Platform subsidy (bps, informational)')->numeric()->minValue(0)->maxValue(10000)->default(0)->required(),
            TextInput::make('max_offers_per_seller')->numeric()->minValue(1)->maxValue(100)->default(10)->required(),
            DateTimePicker::make('starts_at')->required()->default(now()),
            DateTimePicker::make('ends_at')->required()->after('starts_at'),
            Select::make('status')->options(array_combine(Campaign::STATUSES, ['Draft', 'Active', 'Ended']))->required()->default('draft'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at', 'desc')
            ->columns([
                TextColumn::make('name')->state(fn (Campaign $record): string => $record->translate('name', 'en'))->limit(40),
                TextColumn::make('type')->badge(),
                TextColumn::make('starts_at')->dateTime()->sortable(),
                TextColumn::make('ends_at')->dateTime(),
                TextColumn::make('participations_count')->counts('participations')->label('Applications'),
                TextColumn::make('status')->badge()->color(fn (string $state): string => $state === 'active' ? 'success' : 'gray'),
            ])
            ->recordActions([EditAction::make()]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalise(array $data): array
    {
        $rules = is_array($data['rules'] ?? null) ? $data['rules'] : [];
        $ids = is_array($rules['game_ids'] ?? null) ? $rules['game_ids'] : [];
        $games = array_values(array_map(static fn (int|string $id): int => (int) $id, array_filter($ids, static fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))));
        $data['rules'] = $games === [] ? null : ['game_ids' => $games];

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListCampaigns::route('/'),
            'create' => CreateCampaign::route('/create'),
            'edit' => EditCampaign::route('/{record}/edit'),
        ];
    }
}
