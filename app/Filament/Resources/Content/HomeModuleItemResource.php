<?php

declare(strict_types=1);

namespace App\Filament\Resources\Content;

use App\Domains\Content\Models\HomeModule;
use App\Domains\Content\Models\HomeModuleItem;
use App\Filament\Resources\Content\Pages\CreateHomeModuleItem;
use App\Filament\Resources\Content\Pages\EditHomeModuleItem;
use App\Filament\Resources\Content\Pages\ListHomeModuleItems;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Pinned home items (spec 5.17 home_module_items): a game (Hot Game), a game×category (Trending, New Game), a
 * comment (Community ratings) or a news article (Game News), shown first in `sort` order.
 */
final class HomeModuleItemResource extends Resource
{
    protected static ?string $model = HomeModuleItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Pinned home items';

    protected static ?string $slug = 'home-pins';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('home_module_id')->label('Module')->required()
                ->options(fn (): array => HomeModule::query()->whereIn('code', array_keys(HomeModule::ITEM_TYPES))->orderBy('sort')->pluck('code', 'id')->all()),
            TextInput::make('item_id')->label('Item id (game, game category, comment or article, depending on the module)')->numeric()->minValue(1)->required(),
            TextInput::make('sort')->numeric()->default(0)->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('module'))
            ->columns([
                TextColumn::make('module.code')->label('Module')->badge(),
                TextColumn::make('item_type')->label('Type'),
                TextColumn::make('item_id')->label('Item id'),
                TextColumn::make('sort'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    /**
     * The item type follows the module.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalise(array $data): array
    {
        $module = is_numeric($data['home_module_id'] ?? null) ? HomeModule::query()->find((int) $data['home_module_id']) : null;
        $data['item_type'] = $module === null ? 'game_category' : (HomeModule::ITEM_TYPES[$module->code] ?? 'game_category');
        $data['pinned'] = true;

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListHomeModuleItems::route('/'),
            'create' => CreateHomeModuleItem::route('/create'),
            'edit' => EditHomeModuleItem::route('/{record}/edit'),
        ];
    }
}
