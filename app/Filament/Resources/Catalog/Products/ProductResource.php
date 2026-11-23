<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Products;

use App\Domains\Catalog\Enums\ProductStatus;
use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Catalog\Models\Product;
use App\Filament\Resources\Catalog\Products\Pages\CreateProduct;
use App\Filament\Resources\Catalog\Products\Pages\EditProduct;
use App\Filament\Resources\Catalog\Products\Pages\ListProducts;
use App\Filament\Support\Translatable;
use App\Shared\Support\Translations;
use BackedEnum;
use Closure;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use InvalidArgumentException;
use UnitEnum;

final class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Product')->columns(3)->schema([
                Select::make('game_category_id')
                    ->label('Game × category')
                    ->required()
                    ->live()
                    ->searchable()
                    ->options(fn (): array => GameCategory::query()->with(['game', 'type'])->get()
                        ->mapWithKeys(fn (GameCategory $gc): array => [$gc->id => $gc->title('en')])->sort()->all()),
                Select::make('platform_id')->relationship('platform', 'name')->preload(),
                Select::make('region_id')->relationship('region', 'code')->preload(),
                TextInput::make('slug')->required()->regex('/^[a-z0-9][a-z0-9-]*$/')->maxLength(160),
                Select::make('status')->required()->default(ProductStatus::Draft->value)
                    ->options(collect(ProductStatus::cases())->mapWithKeys(fn (ProductStatus $s): array => [$s->value => ucfirst($s->value)])->all()),
                TextInput::make('image_path')->label('Image URL / path')->maxLength(255),
                TextInput::make('msrp_amount')->label('MSRP (USD cents)')->integer()->minValue(0),
                TextInput::make('msrp_currency')->label('MSRP currency')->default('USD')->length(3),
                Translatable::input('name', 'Name'),
                Translatable::input('description', 'Description', required: false, multiline: true),
                KeyValue::make('attribute_values')
                    ->label('Attributes')
                    ->keyLabel('Attribute key')
                    ->valueLabel('Value')
                    ->helperText(fn (Get $get): string => self::schemaHelp($get('game_category_id')))
                    ->rule(fn (Get $get): Closure => self::attributeRule($get('game_category_id')))
                    ->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('name.en')->label('Name')->limit(50)->searchable(query: fn (Builder $query, string $search) => $query->where('slug', 'like', '%'.str($search)->slug().'%')),
                TextColumn::make('gameCategory.game.slug')->label('Game'),
                TextColumn::make('gameCategory.type.code')->label('Type'),
                TextColumn::make('platform.name')->label('Platform'),
                TextColumn::make('region.code')->label('Region'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (ProductStatus $state): string => ucfirst($state->value)),
                TextColumn::make('offer_count_cached')->label('Offers'),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(ProductStatus::cases())->mapWithKeys(fn (ProductStatus $s): array => [$s->value => ucfirst($s->value)])->all()),
                TrashedFilter::make(),
            ])
            ->recordActions([EditAction::make()]);
    }

    /**
     * @return Builder<Model>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class])->with(['gameCategory.game', 'gameCategory.type', 'platform', 'region']);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }

    private static function schemaHelp(mixed $gameCategoryId): string
    {
        $gameCategory = is_numeric($gameCategoryId) ? GameCategory::query()->find((int) $gameCategoryId) : null;

        if ($gameCategory === null) {
            return 'Choose a game × category to see its attribute keys.';
        }

        $keys = array_map(
            static fn (array $f): string => $f['key'].' ('.$f['type']->value.($f['options'] !== [] ? ': '.implode('|', array_column($f['options'], 'value')) : '').')',
            $gameCategory->attributeSchema()->fields(),
        );

        return $keys === [] ? 'This game × category has no attributes.' : 'Keys: '.implode(', ', $keys).'. Multiselect: comma-separated. Boolean: yes/no.';
    }

    private static function attributeRule(mixed $gameCategoryId): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($gameCategoryId): void {
            $gameCategory = is_numeric($gameCategoryId) ? GameCategory::query()->find((int) $gameCategoryId) : null;

            if ($gameCategory === null) {
                return;
            }

            try {
                $values = [];
                foreach (is_array($value) ? $value : [] as $key => $item) {
                    $values[(string) $key] = $item;
                }
                $gameCategory->attributeSchema()->normalise($values);
            } catch (InvalidArgumentException $e) {
                $fail(Translations::pick(['en' => $e->getMessage()]));
            }
        };
    }
}
