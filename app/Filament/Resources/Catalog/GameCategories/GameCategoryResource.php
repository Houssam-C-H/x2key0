<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\GameCategories;

use App\Domains\Catalog\Models\CategoryType;
use App\Domains\Catalog\Models\GameCategory;
use App\Filament\Resources\Catalog\GameCategories\Pages\CreateGameCategory;
use App\Filament\Resources\Catalog\GameCategories\Pages\EditGameCategory;
use App\Filament\Resources\Catalog\GameCategories\Pages\ListGameCategories;
use App\Filament\Support\SchemaBuilder;
use App\Filament\Support\Translatable;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

/**
 * Game × category editor (spec Part 18): label override, attribute & buyer-input schema builders, notice.
 */
final class GameCategoryResource extends Resource
{
    protected static ?string $model = GameCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Game categories';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()->columnSpanFull()->tabs([
                Tab::make('General')->columns(3)->schema([
                    Select::make('game_id')
                        ->relationship('game', 'slug')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->default(fn (): mixed => request()->query('game')),
                    Select::make('category_type_id')
                        ->label('Category type')
                        ->options(fn (): array => CategoryType::query()->orderBy('sort')->get()->mapWithKeys(fn (CategoryType $t): array => [$t->id => $t->translate('name', 'en')])->all())
                        ->required()
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, callable $get) => $rule->where('game_id', self::gameId($get('game_id')))),
                    TextInput::make('slug')
                        ->required()
                        ->regex('/^[a-z0-9][a-z0-9-]*$/')
                        ->maxLength(96)
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, callable $get) => $rule->where('game_id', self::gameId($get('game_id'))))
                        ->helperText('URL: /{locale}/{game}/{slug}, e.g. "coins", "accounts".'),
                    Toggle::make('is_enabled')->default(true)->label('Enabled'),
                    CheckboxList::make('allowed_delivery_methods')
                        ->options([
                            'digital_key' => 'Digital key', 'account_transfer' => 'Account transfer', 'manual_top_up' => 'Manual top-up',
                            'send_gift' => 'Send gift', 'service' => 'Service (boosting)', 'code' => 'Code', 'file' => 'File',
                        ])
                        ->columns(4)
                        ->columnSpanFull()
                        ->helperText('Used by offers from M5. Leave empty to allow the category type defaults.'),
                    Translatable::input('label_override', 'Label override (e.g. "Coins", "V-Bucks"; empty = category type name)', required: false),
                ]),
                Tab::make('Attributes & filters')->schema([SchemaBuilder::attributes()]),
                Tab::make('Buyer inputs')->schema([SchemaBuilder::buyerInputs()]),
                Tab::make('Notice')->schema([
                    Section::make('Important notice')
                        ->description('Shown above the listing (independent sellers, no publisher affiliation, ToS risk, trademark owner).')
                        ->schema([Translatable::input('notice', 'Notice', required: false, multiline: true)]),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('game.slug')->label('Game')->searchable(),
                TextColumn::make('type.name.en')->label('Type'),
                TextColumn::make('label_override.en')->label('Label')->placeholder('—'),
                TextColumn::make('slug'),
                TextColumn::make('product_count_cached')->label('Products')->sortable(),
                ToggleColumn::make('is_enabled')->label('Enabled'),
            ])
            ->filters([
                SelectFilter::make('category_type_id')->label('Type')
                    ->options(fn (): array => CategoryType::query()->orderBy('sort')->get()->mapWithKeys(fn (CategoryType $t): array => [$t->id => $t->translate('name', 'en')])->all()),
            ])
            ->recordActions([EditAction::make()]);
    }

    private static function gameId(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListGameCategories::route('/'),
            'create' => CreateGameCategory::route('/create'),
            'edit' => EditGameCategory::route('/{record}/edit'),
        ];
    }
}
