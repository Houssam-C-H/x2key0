<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Risk\Models\RiskFlag;
use App\Filament\Resources\Orders\Pages\ListRiskFlags;
use App\Filament\Resources\Sellers\SellerProfiles\SellerProfileResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Risk flags (spec 5.17 / 17.1): checkout blocks and reviews, payment mismatches, late captures, refunds to
 * do, cross-seller storage duplicates. Finance resolves them.
 */
final class RiskFlagResource extends Resource
{
    protected static ?string $model = RiskFlag::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|UnitEnum|null $navigationGroup = 'Orders & risk';

    protected static ?string $slug = 'risk-flags';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $open = RiskFlag::query()->where('status', 'open')->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id'),
                TextColumn::make('rule')->badge()->searchable(),
                TextColumn::make('subject')->state(fn (RiskFlag $record): string => "{$record->subject_type} #{$record->subject_id}"),
                TextColumn::make('score'),
                TextColumn::make('details')->limit(80)->wrap()
                    ->state(fn (RiskFlag $record): string => (string) json_encode($record->details, JSON_UNESCAPED_SLASHES)),
                TextColumn::make('status')->badge()->color(fn (string $state): string => $state === 'open' ? 'warning' : 'gray'),
                TextColumn::make('created_at')->since(),
            ])
            ->filters([
                SelectFilter::make('status')->options(['open' => 'Open', 'resolved' => 'Resolved'])->default('open'),
            ])
            ->recordActions([
                Action::make('resolve')
                    ->requiresConfirmation()
                    ->visible(fn (RiskFlag $record): bool => $record->status === 'open' && self::isFinance())
                    ->action(function (RiskFlag $record): void {
                        $record->forceFill(['status' => 'resolved', 'resolved_by' => SellerProfileResource::actor()->id, 'resolved_at' => now()])->save();
                    }),
            ]);
    }

    private static function isFinance(): bool
    {
        $user = Auth::user();

        return $user instanceof User && ($user->hasStaffRole(StaffRole::Finance) || $user->hasStaffRole(StaffRole::Admin));
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return ['index' => ListRiskFlags::route('/')];
    }
}
