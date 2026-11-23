<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payouts;

use App\Domains\Identity\Models\User;
use App\Domains\Payouts\Enums\WithdrawalStatus;
use App\Domains\Payouts\Exceptions\PayoutException;
use App\Domains\Payouts\Models\WithdrawalRequest;
use App\Domains\Payouts\StateMachines\WithdrawalStateMachine;
use App\Filament\Resources\Payouts\Pages\ListWithdrawals;
use BackedEnum;
use Brick\Money\Money;
use Filament\Actions\Action;
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
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Withdrawal approval queue (spec Part 18 / 6.6) for finance: approve → processing → paid (provider reference,
 * voucher issued) or failed (money back); reject or let the seller cancel while requested. The full payout
 * details are shown only here, decrypted for finance at the moment they pay.
 */
final class WithdrawalResource extends Resource
{
    protected static ?string $model = WithdrawalRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Ledger';

    protected static ?string $slug = 'withdrawals';

    protected static ?int $navigationSort = 0;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $open = WithdrawalRequest::query()->whereIn('status', [WithdrawalStatus::Requested->value, WithdrawalStatus::Approved->value])->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'method'])
                ->orderByRaw("case status when 'requested' then 0 when 'approved' then 1 when 'processing' then 2 else 3 end")->orderBy('id'))
            ->columns([
                TextColumn::make('public_id')->label('Number')->formatStateUsing(fn (WithdrawalRequest $record): string => $record->number())->searchable(),
                TextColumn::make('user.username')->label('Seller')->searchable(),
                TextColumn::make('amount')->formatStateUsing(fn (WithdrawalRequest $record): string => (string) Money::ofMinor($record->amount, $record->currency)),
                TextColumn::make('fee_amount')->label('Fee')->formatStateUsing(fn (WithdrawalRequest $record): string => (string) Money::ofMinor($record->fee_amount, $record->currency)),
                TextColumn::make('payout_amount')->label('Payout')->formatStateUsing(fn (WithdrawalRequest $record): string => (string) Money::ofMinor($record->payout_amount, $record->payout_currency)),
                TextColumn::make('payout_label')->label('To'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (WithdrawalStatus $state): string => ucfirst($state->value))
                    ->color(fn (WithdrawalStatus $state): string => match ($state) {
                        WithdrawalStatus::Paid => 'success',
                        WithdrawalStatus::Rejected, WithdrawalStatus::Failed => 'danger',
                        WithdrawalStatus::Cancelled => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('requested_at')->since()->sortable(),
                TextColumn::make('provider_ref')->label('Reference')->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(WithdrawalStatus::cases())->mapWithKeys(fn (WithdrawalStatus $s): array => [$s->value => ucfirst($s->value)])->all()),
            ])
            ->recordActions([
                Action::make('details')->label('Payout details')->icon(Heroicon::OutlinedEye)
                    ->visible(fn (WithdrawalRequest $record): bool => Gate::allows('update', $record))
                    ->modalHeading(fn (WithdrawalRequest $record): string => 'Payout details · '.$record->number())
                    ->modalContent(fn (WithdrawalRequest $record) => view('filament.payouts.details', ['withdrawal' => $record]))
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close'),
                self::step('approve', 'success', WithdrawalStatus::Requested, fn (WithdrawalRequest $r) => app(WithdrawalStateMachine::class)->approve($r, self::actor())),
                Action::make('reject')->color('danger')
                    ->visible(fn (WithdrawalRequest $record): bool => $record->status === WithdrawalStatus::Requested && Gate::allows('update', $record))
                    ->schema([Textarea::make('reason')->label('Remark shown to the seller')->required()->maxLength(500)])
                    ->action(fn (WithdrawalRequest $record, array $data) => self::run(fn () => app(WithdrawalStateMachine::class)->reject($record, self::actor(), self::text($data, 'reason')))),
                self::step('processing', 'info', WithdrawalStatus::Approved, fn (WithdrawalRequest $r) => app(WithdrawalStateMachine::class)->process($r, self::actor())),
                Action::make('paid')->label('Mark paid')->color('success')
                    ->visible(fn (WithdrawalRequest $record): bool => $record->status === WithdrawalStatus::Processing && Gate::allows('update', $record))
                    ->schema([TextInput::make('reference')->label('Provider / bank reference')->required()->maxLength(191)])
                    ->action(fn (WithdrawalRequest $record, array $data) => self::run(fn () => app(WithdrawalStateMachine::class)->pay($record, self::actor(), self::text($data, 'reference')))),
                Action::make('failed')->label('Mark failed')->color('danger')
                    ->visible(fn (WithdrawalRequest $record): bool => $record->status === WithdrawalStatus::Processing && Gate::allows('update', $record))
                    ->schema([Textarea::make('reason')->label('Reason shown to the seller')->required()->maxLength(500)])
                    ->action(fn (WithdrawalRequest $record, array $data) => self::run(fn () => app(WithdrawalStateMachine::class)->fail($record, self::actor(), self::text($data, 'reason')))),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return ['index' => ListWithdrawals::route('/')];
    }

    private static function step(string $name, string $color, WithdrawalStatus $from, callable $do): Action
    {
        return Action::make($name)->color($color)->requiresConfirmation()
            ->visible(fn (WithdrawalRequest $record): bool => $record->status === $from && Gate::allows('update', $record))
            ->action(fn (WithdrawalRequest $record) => self::run(fn () => $do($record)));
    }

    private static function run(callable $action): void
    {
        try {
            $action();
        } catch (PayoutException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Done')->success()->send();
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function text(array $data, string $key): string
    {
        return is_string($data[$key] ?? null) ? $data[$key] : '';
    }

    private static function actor(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
