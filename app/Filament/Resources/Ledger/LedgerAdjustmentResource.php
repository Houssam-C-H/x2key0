<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ledger;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Ledger\Actions\ManualAdjustments;
use App\Domains\Ledger\Enums\AccountCode;
use App\Domains\Ledger\Exceptions\LedgerException;
use App\Domains\Ledger\Models\LedgerAdjustment;
use App\Filament\Resources\Ledger\Pages\ListLedgerAdjustments;
use App\Filament\Resources\Sellers\SellerProfiles\SellerProfileResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Manual ledger adjustments (spec 7.6, Part 18 "Ledger: manual adjustment with dual approval"): finance requests;
 * at or above the threshold another finance user approves; the journal is posted on approval.
 */
final class LedgerAdjustmentResource extends Resource
{
    protected static ?string $model = LedgerAdjustment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Ledger';

    protected static ?string $navigationLabel = 'Manual adjustments';

    protected static ?string $slug = 'ledger-adjustments';

    public static function canAccess(): bool
    {
        return self::isFinance();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = LedgerAdjustment::query()->where('status', LedgerAdjustment::PENDING)->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['owner:id,username', 'requester:id,username']))
            ->columns([
                TextColumn::make('id'),
                TextColumn::make('account_code')->label('Account')->badge(),
                TextColumn::make('owner.username')->label('User')->placeholder('platform'),
                TextColumn::make('amount')->money('USD', divideBy: 100),
                TextColumn::make('offset_code')->label('Offset'),
                TextColumn::make('reason')->limit(50)->wrap(),
                TextColumn::make('requester.username')->label('Requested by'),
                TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                    'posted' => 'success',
                    'rejected' => 'danger',
                    default => 'warning',
                }),
                TextColumn::make('created_at')->since(),
            ])
            ->filters([SelectFilter::make('status')->options(['pending' => 'Pending', 'posted' => 'Posted', 'rejected' => 'Rejected'])])
            ->headerActions([
                Action::make('new')->label('New adjustment')
                    ->schema([
                        Select::make('account')->options(collect(AccountCode::cases())->mapWithKeys(fn (AccountCode $c): array => [$c->value => $c->value])->all())->required()->live(),
                        TextInput::make('user_id')->label('User id (user-owned accounts only)')->numeric(),
                        Select::make('offset')->options(collect(ManualAdjustments::OFFSETS)->mapWithKeys(fn (AccountCode $c): array => [$c->value => $c->value])->all())->required()->default(AccountCode::ExpPromotions->value),
                        TextInput::make('amount')->label('Amount in USD (+ owed more / − less)')->numeric()->required(),
                        Textarea::make('reason')->required()->minLength(10)->maxLength(1000),
                    ])
                    ->action(function (array $data): void {
                        self::run(fn () => app(ManualAdjustments::class)->request(
                            SellerProfileResource::actor(),
                            AccountCode::from(is_string($data['account'] ?? null) ? $data['account'] : ''),
                            is_numeric($data['user_id'] ?? null) ? (int) $data['user_id'] : null,
                            AccountCode::from(is_string($data['offset'] ?? null) ? $data['offset'] : ''),
                            is_numeric($data['amount'] ?? null) ? (int) round(((float) $data['amount']) * 100) : 0,
                            SellerProfileResource::reason($data),
                        ), 'Adjustment saved');
                    }),
            ])
            ->recordActions([
                Action::make('approve')->color('success')->requiresConfirmation()
                    ->visible(fn (LedgerAdjustment $record): bool => $record->status === LedgerAdjustment::PENDING && $record->requested_by !== Auth::id())
                    ->action(fn (LedgerAdjustment $record) => self::run(fn () => app(ManualAdjustments::class)->approve($record, SellerProfileResource::actor()), 'Adjustment posted')),
                Action::make('reject')->color('danger')
                    ->visible(fn (LedgerAdjustment $record): bool => $record->status === LedgerAdjustment::PENDING)
                    ->schema([Textarea::make('reason')->required()->maxLength(500)])
                    ->action(fn (LedgerAdjustment $record, array $data) => self::run(fn () => app(ManualAdjustments::class)->reject($record, SellerProfileResource::actor(), SellerProfileResource::reason($data)), 'Adjustment rejected')),
            ]);
    }

    private static function run(callable $action, string $message): void
    {
        try {
            $action();
            Notification::make()->success()->title($message)->send();
        } catch (LedgerException|\ValueError $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
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
        return ['index' => ListLedgerAdjustments::route('/')];
    }
}
