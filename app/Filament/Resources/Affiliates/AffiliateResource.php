<?php

declare(strict_types=1);

namespace App\Filament\Resources\Affiliates;

use App\Domains\Affiliate\Models\Affiliate;
use App\Domains\Affiliate\Models\AffiliateCommission;
use App\Filament\Resources\Affiliates\Pages\EditAffiliate;
use App\Filament\Resources\Affiliates\Pages\ListAffiliates;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Affiliates (spec Part 18): codes, inviter, clicks/signups, commissions; suspend; per-affiliate rates and rank. */
final class AffiliateResource extends Resource
{
    protected static ?string $model = Affiliate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Affiliates';

    protected static ?string $slug = 'affiliates';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('rank')->numeric()->minValue(1)->maxValue(99)->required(),
            TextInput::make('commission_bps')->label('Direct rate (bps, empty = default)')->numeric()->minValue(0)->maxValue(5000),
            TextInput::make('level2_bps')->label('Level-2 rate (bps, empty = default)')->numeric()->minValue(0)->maxValue(2000),
            TextInput::make('attribution_days')->label('Attribution days (empty = default)')->numeric()->minValue(1)->maxValue(365),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user:id,username', 'parent:id,code']))
            ->columns([
                TextColumn::make('user.username')->label('User')->searchable(),
                TextColumn::make('code')->searchable()->copyable(),
                TextColumn::make('parent.code')->label('Invited by')->placeholder('—'),
                TextColumn::make('clicks'),
                TextColumn::make('signups'),
                TextColumn::make('earned')->label('Commission (net)')->state(fn (Affiliate $record): string => number_format(self::earned($record) / 100, 2).' USD'),
                TextColumn::make('rank'),
                TextColumn::make('status')->badge()->color(fn (string $state): string => $state === 'active' ? 'success' : 'danger'),
            ])
            ->filters([SelectFilter::make('status')->options(['active' => 'Active', 'suspended' => 'Suspended'])])
            ->recordActions([
                EditAction::make(),
                Action::make('suspend')->color('danger')->requiresConfirmation()
                    ->visible(fn (Affiliate $record): bool => $record->status === Affiliate::ACTIVE)
                    ->action(fn (Affiliate $record) => $record->forceFill(['status' => Affiliate::SUSPENDED])->save()),
                Action::make('activate')->color('success')->requiresConfirmation()
                    ->visible(fn (Affiliate $record): bool => $record->status === Affiliate::SUSPENDED)
                    ->action(fn (Affiliate $record) => $record->forceFill(['status' => Affiliate::ACTIVE])->save()),
            ]);
    }

    private static function earned(Affiliate $affiliate): int
    {
        $sum = AffiliateCommission::query()->where('affiliate_id', $affiliate->id)->selectRaw('COALESCE(SUM(commission_amount - reversed_amount), 0) as total')->value('total');

        return is_numeric($sum) ? (int) $sum : 0;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function save(Model $record, array $data): Model
    {
        $int = static fn (mixed $v): ?int => is_numeric($v) ? (int) $v : null;
        $record->forceFill([
            'rank' => $int($data['rank'] ?? null) ?? 1,
            'commission_bps' => $int($data['commission_bps'] ?? null),
            'level2_bps' => $int($data['level2_bps'] ?? null),
            'attribution_days' => $int($data['attribution_days'] ?? null),
        ])->save();

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return ['index' => ListAffiliates::route('/'), 'edit' => EditAffiliate::route('/{record}/edit')];
    }
}
