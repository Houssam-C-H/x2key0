<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\Payment;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Support\CsvExport;
use App\Filament\Support\MinorUnits;
use App\Filament\Support\Staff;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Payments (docs/ADMIN_PLAN.md item 5): every provider payment with its order, method, status and failure reason;
 * tabs for failed and pending ones. Read-only — payment state only changes through provider events. Finance, admins.
 */
final class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Payments';

    protected static ?string $slug = 'payments';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return Staff::has(StaffRole::Finance);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('order'))
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('public_id')->label('Payment')->searchable()->copyable()->limit(12),
                TextColumn::make('order.number')->label('Order')->searchable()
                    ->url(fn (Payment $record): ?string => $record->order_id !== null ? OrderResource::getUrl('view', ['record' => $record->order_id]) : null),
                TextColumn::make('purpose')->badge()->color('gray'),
                TextColumn::make('provider')->description(fn (Payment $record): string => $record->method_code),
                TextColumn::make('amount')->money(fn (Payment $record): string => $record->currency, divideBy: 100)->sortable(),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (PaymentStatus $state): string => str_replace('_', ' ', $state->value))
                    ->color(fn (PaymentStatus $state): string => match ($state) {
                        PaymentStatus::Captured => 'success',
                        PaymentStatus::Failed => 'danger',
                        PaymentStatus::Cancelled => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('card_last4')->label('Card')->formatStateUsing(fn (?string $state, Payment $record): string => $state === null ? '—' : '•••• '.$state.' '.($record->card_country ?? ''))->placeholder('—'),
                IconColumn::make('three_ds')->label('3DS')->boolean()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('failure_reason')->label('Failure')->wrap()->placeholder('—'),
                TextColumn::make('provider_payment_id')->label('Provider ID')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('captured_at')->dateTime()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(PaymentStatus::cases())->mapWithKeys(fn (PaymentStatus $s): array => [$s->value => str_replace('_', ' ', $s->value)])->all()),
                SelectFilter::make('provider')->options(fn (): array => Payment::query()->distinct()->orderBy('provider')->pluck('provider', 'provider')->all()),
                SelectFilter::make('method_code')->label('Method')->options(fn (): array => Payment::query()->distinct()->orderBy('method_code')->pluck('method_code', 'method_code')->all()),
            ])
            ->toolbarActions([
                CsvExport::bulkAction('payments', [
                    'Payment' => fn (Model $r): string => $r instanceof Payment ? $r->public_id : '',
                    'Order' => fn (Model $r): string => $r instanceof Payment && $r->order !== null ? $r->order->number : '',
                    'Provider' => fn (Model $r): string => $r instanceof Payment ? $r->provider : '',
                    'Method' => fn (Model $r): string => $r instanceof Payment ? $r->method_code : '',
                    'Amount' => fn (Model $r): string => $r instanceof Payment ? MinorUnits::toDecimal($r->amount) : '',
                    'Currency' => fn (Model $r): string => $r instanceof Payment ? $r->currency : '',
                    'Status' => fn (Model $r): string => $r instanceof Payment ? $r->status->value : '',
                    'Failure' => fn (Model $r): string => $r instanceof Payment ? (string) $r->failure_reason : '',
                    'Created' => fn (Model $r): string => $r instanceof Payment ? $r->created_at->toDateTimeString() : '',
                ]),
            ]);
    }

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['public_id', 'provider_payment_id'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return $record instanceof Payment ? 'Payment '.substr($record->public_id, -8) : '';
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return $record instanceof Payment ? ['Amount' => $record->currency.' '.number_format($record->amount / 100, 2), 'Status' => $record->status->value] : [];
    }

    public static function getGlobalSearchResultUrl(Model $record): string
    {
        return $record instanceof Payment && $record->order_id !== null ? OrderResource::getUrl('view', ['record' => $record->order_id]) : self::getUrl('index');
    }

    /**
     * @return Builder<Model>
     */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('order');
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return ['index' => ListPayments::route('/')];
    }
}
