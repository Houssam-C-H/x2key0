<?php

declare(strict_types=1);

namespace App\Filament\Resources\Chargebacks;

use App\Domains\Identity\Models\User;
use App\Domains\Orders\Models\Order;
use App\Domains\Payments\Actions\ApplyChargeback;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Exceptions\ChargebackException;
use App\Domains\Payments\Models\Chargeback;
use App\Domains\Payments\Models\Payment;
use App\Filament\Resources\Chargebacks\Pages\ListChargebacks;
use BackedEnum;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Chargebacks (spec 9.4, Part 18) for finance: record one reported by the PSP (until PSP webhooks deliver them,
 * M6b), then mark it won or lost. Money movements are journaled by ApplyChargeback.
 */
final class ChargebackResource extends Resource
{
    protected static ?string $model = Chargeback::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'Orders & risk';

    protected static ?string $slug = 'chargebacks';

    protected static ?int $navigationSort = 3;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $open = Chargeback::query()->where('status', Chargeback::OPEN)->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('order'))
            ->columns([
                TextColumn::make('order.number')->label('Order')->searchable(),
                TextColumn::make('provider'),
                TextColumn::make('provider_case_id')->label('Case')->searchable(),
                TextColumn::make('amount')->formatStateUsing(fn (Chargeback $record): string => self::money($record->amount, $record->currency)),
                TextColumn::make('fee_amount')->label('Fee')->formatStateUsing(fn (Chargeback $record): string => self::money($record->fee_amount, $record->currency)),
                TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                    Chargeback::WON => 'success',
                    Chargeback::LOST => 'danger',
                    default => 'warning',
                }),
                TextColumn::make('recovered_amount')->label('Recovered')->formatStateUsing(fn (Chargeback $record): string => self::money($record->recovered_amount, $record->currency)),
                TextColumn::make('opened_at')->since(),
            ])
            ->filters([
                SelectFilter::make('status')->options([Chargeback::OPEN => 'Open', Chargeback::WON => 'Won', Chargeback::LOST => 'Lost']),
            ])
            ->headerActions([
                Action::make('record')
                    ->label('Record chargeback')
                    ->visible(fn (): bool => Gate::allows('create', Chargeback::class))
                    ->schema([
                        TextInput::make('order')->label('Order number')->required(),
                        TextInput::make('case_id')->label('PSP case id')->required()->maxLength(191),
                        TextInput::make('amount')->label('Amount (USD)')->numeric()->required()->minValue(0.01),
                        TextInput::make('fee')->label('Chargeback fee (USD)')->numeric()->default(0)->minValue(0),
                        TextInput::make('reason')->label('Reason code')->maxLength(64),
                    ])
                    ->action(function (array $data): void {
                        $order = Order::query()->where('number', is_string($data['order'] ?? null) ? trim($data['order']) : '')->first();
                        $payment = $order === null ? null : Payment::query()->where('order_id', $order->id)
                            ->where('provider', '!=', Payment::CREDITS)->where('status', PaymentStatus::Captured)->orderBy('id')->first();

                        if ($payment === null) {
                            Notification::make()->title('No captured card payment for this order')->danger()->send();

                            return;
                        }

                        try {
                            app(ApplyChargeback::class)->open(
                                $payment,
                                is_string($data['case_id'] ?? null) ? $data['case_id'] : '',
                                self::cents($data['amount'] ?? 0, $payment->currency),
                                self::cents($data['fee'] ?? 0, $payment->currency),
                                is_string($data['reason'] ?? null) && $data['reason'] !== '' ? $data['reason'] : null,
                            );
                        } catch (ChargebackException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Chargeback recorded, seller funds frozen')->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('won')->color('success')->requiresConfirmation()
                    ->visible(fn (Chargeback $record): bool => $record->status === Chargeback::OPEN && Gate::allows('update', $record))
                    ->action(function (Chargeback $record): void {
                        app(ApplyChargeback::class)->won($record, self::actor());
                        Notification::make()->title('Chargeback won')->success()->send();
                    }),
                Action::make('lost')->color('danger')->requiresConfirmation()
                    ->modalDescription('Recovers the money from the seller (completed orders) or the escrow (open orders).')
                    ->visible(fn (Chargeback $record): bool => $record->status === Chargeback::OPEN && Gate::allows('update', $record))
                    ->action(function (Chargeback $record): void {
                        app(ApplyChargeback::class)->lost($record, self::actor());
                        Notification::make()->title('Chargeback lost')->warning()->send();
                    }),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return ['index' => ListChargebacks::route('/')];
    }

    private static function money(int $minor, string $currency): string
    {
        return (string) Money::ofMinor($minor, $currency);
    }

    private static function cents(mixed $value, string $currency): int
    {
        $amount = is_numeric($value) ? (string) $value : '0';

        return Money::of($amount, $currency, roundingMode: RoundingMode::HalfUp)->getMinorAmount()->toInt();
    }

    private static function actor(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
