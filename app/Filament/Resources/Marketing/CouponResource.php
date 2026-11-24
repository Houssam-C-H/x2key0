<?php

declare(strict_types=1);

namespace App\Filament\Resources\Marketing;

use App\Domains\Catalog\Enums\CategoryTypeCode;
use App\Domains\Coupons\Models\Coupon;
use App\Filament\Resources\Marketing\Pages\CreateCoupon;
use App\Filament\Resources\Marketing\Pages\EditCoupon;
use App\Filament\Resources\Marketing\Pages\ListCoupons;
use App\Filament\Support\Translatable;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Platform coupons (spec 14.1, Part 18): funded by the platform (EXP_PROMOTIONS), claimed free in the Coupon
 * Center or bought with X2K Score (`points_cost`), scoped by category type, minimum order, buyer level.
 * Store coupons are the sellers' own and are not edited here.
 */
final class CouponResource extends Resource
{
    protected static ?string $model = Coupon::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|UnitEnum|null $navigationGroup = 'Marketing';

    protected static ?string $navigationLabel = 'Platform coupons';

    protected static ?string $slug = 'platform-coupons';

    protected static ?int $navigationSort = 1;

    /**
     * @return Builder<Coupon>
     */
    public static function getEloquentQuery(): Builder
    {
        return Coupon::query()->where('issuer', Coupon::PLATFORM);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Translatable::input('name', 'Name'),
            Select::make('type')->options([Coupon::FIXED => 'Fixed amount', Coupon::PERCENT => 'Percent'])->required()->default(Coupon::FIXED)->live(),
            TextInput::make('value')->numeric()->required()->minValue(1)
                ->helperText('Fixed: amount in cents (500 = USD 5.00). Percent: basis points (1000 = 10 %).'),
            TextInput::make('max_discount_amount')->label('Max discount (cents, percent coupons)')->numeric()->minValue(1),
            TextInput::make('min_order_amount')->label('Minimum order (cents)')->numeric()->minValue(0)->default(0)->required(),
            TextInput::make('points_cost')->label('Price in X2K Score (empty = free claim)')->numeric()->minValue(1),
            TextInput::make('claim_limit_total')->label('Total claims (empty = unlimited)')->numeric()->minValue(1),
            TextInput::make('claim_limit_per_user')->numeric()->minValue(1)->default(1)->required(),
            TextInput::make('user_level_min')->label('Minimum buyer level rank')->numeric()->minValue(0),
            Select::make('scope.category_types')->label('Only these category types (empty = all)')->multiple()
                ->options(collect(CategoryTypeCode::cases())->mapWithKeys(fn (CategoryTypeCode $c): array => [$c->value => str_replace('_', ' ', ucfirst($c->value))])->all()),
            DateTimePicker::make('valid_from')->default(now()),
            DateTimePicker::make('valid_to')->after('valid_from'),
            TextInput::make('valid_days_after_claim')->label('Valid for N days after claiming')->numeric()->minValue(1),
            Toggle::make('stackable')->label('Can be combined with a store coupon')->default(true),
            Select::make('status')->options(['draft' => 'Draft', 'active' => 'Active', 'paused' => 'Paused', 'ended' => 'Ended'])->required()->default('draft'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('name')->state(fn (Coupon $record): string => $record->translate('name', 'en'))->limit(40),
                TextColumn::make('label')->label('Discount')->state(fn (Coupon $record): string => $record->label()),
                TextColumn::make('points_cost')->label('Points')->placeholder('free'),
                TextColumn::make('claimed_count')->label('Claimed'),
                TextColumn::make('used_count')->label('Used'),
                TextColumn::make('valid_to')->dateTime()->placeholder('no end'),
                TextColumn::make('status')->badge()->color(fn (string $state): string => $state === 'active' ? 'success' : 'gray'),
            ])
            ->filters([SelectFilter::make('status')->options(['draft' => 'Draft', 'active' => 'Active', 'paused' => 'Paused', 'ended' => 'Ended'])])
            ->recordActions([EditAction::make()]);
    }

    /**
     * Normalises the form data for the guarded model (Pages force-fill it).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalise(array $data): array
    {
        $scope = is_array($data['scope'] ?? null) ? $data['scope'] : [];
        $types = is_array($scope['category_types'] ?? null) ? array_values(array_filter($scope['category_types'], 'is_string')) : [];
        $data['scope'] = $types === [] ? null : ['category_types' => $types];
        $data['issuer'] = Coupon::PLATFORM;
        $data['seller_profile_id'] = null;
        $data['currency'] = 'USD';
        $data['is_public'] = true;
        foreach (['max_discount_amount', 'points_cost', 'claim_limit_total', 'user_level_min', 'valid_days_after_claim'] as $nullable) {
            $value = $data[$nullable] ?? null;
            $data[$nullable] = is_numeric($value) ? (int) $value : null;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListCoupons::route('/'),
            'create' => CreateCoupon::route('/create'),
            'edit' => EditCoupon::route('/{record}/edit'),
        ];
    }
}
