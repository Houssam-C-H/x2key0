<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Exceptions\CheckoutException;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderStatusHistory;
use App\Domains\Orders\StateMachines\OrderStateMachine;
use App\Domains\Payments\Actions\ReviewPayment;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Sellers\SellerProfiles\SellerProfileResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Orders for staff (spec Part 18), M6 part: status, money lines, risk, and the payment review queue
 * (finance approves → paid, or rejects → cancelled). Buyer inputs are never shown here.
 */
final class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static string|UnitEnum|null $navigationGroup = 'Orders & risk';

    protected static ?string $slug = 'orders';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $review = Order::query()->where('status', OrderStatus::PaymentReview)->count();

        return $review > 0 ? (string) $review : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['buyer', 'seller', 'item']))
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('buyer.username')->label('Buyer')->searchable(),
                TextColumn::make('seller.store_name')->label('Store')
                    ->url(fn (Order $record): string => SellerProfileResource::getUrl('edit', ['record' => $record->seller_profile_id])),
                TextColumn::make('item_title')->label('Item')->limit(40)
                    ->state(fn (Order $record): string => is_string($record->item->snapshot['title'] ?? null) ? $record->item->snapshot['title'] : ''),
                TextColumn::make('total_amount')->label('Total')->money('USD', divideBy: 100)->sortable(),
                TextColumn::make('paid_credits_amount')->label('Credits')->money('USD', divideBy: 100)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (OrderStatus $state): string => ucfirst(str_replace('_', ' ', $state->value)))
                    ->color(fn (OrderStatus $state): string => match ($state) {
                        OrderStatus::Paid, OrderStatus::Completed, OrderStatus::Delivered => 'success',
                        OrderStatus::PaymentReview, OrderStatus::PendingPayment => 'warning',
                        OrderStatus::Cancelled, OrderStatus::Refunded => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('risk_decision')->label('Risk')->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof BackedEnum ? (string) $state->value : '—'),
                TextColumn::make('risk_score')->label('Score')->sortable(),
                TextColumn::make('ip')->label('IP')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(OrderStatus::cases())->mapWithKeys(fn (OrderStatus $s): array => [$s->value => ucfirst(str_replace('_', ' ', $s->value))])->all()),
            ])
            ->recordActions([
                ViewAction::make()->label('Open'),
                ...self::orderActions(),
                // Timeline (spec Part 18 "Orders (timeline …)"): every status change with actor and reason.
                Action::make('timeline')->icon(Heroicon::OutlinedClock)->color('gray')
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn (Order $record): HtmlString => self::timeline($record)),
            ]);
    }

    /**
     * Staff decisions on one order, shared by the list and the order page (header actions).
     *
     * @return list<Action>
     */
    public static function orderActions(): array
    {
        return [
            Action::make('approve')
                ->label('Approve payment')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (Order $record): bool => $record->status === OrderStatus::PaymentReview && Gate::allows('review', $record))
                ->action(fn (Order $record) => self::run(fn () => app(ReviewPayment::class)->approve($record, SellerProfileResource::actor()), 'Order paid')),
            Action::make('reject')
                ->label('Reject')
                ->color('danger')
                ->visible(fn (Order $record): bool => $record->status === OrderStatus::PaymentReview && Gate::allows('review', $record))
                ->schema([Textarea::make('reason')->label('Reason (internal)')->required()->maxLength(400)])
                ->action(fn (Order $record, array $data) => self::run(fn () => app(ReviewPayment::class)->reject($record, SellerProfileResource::actor(), SellerProfileResource::reason($data)), 'Order cancelled; captured amount flagged for refund')),
            // Staff cancel with a full refund to the original sources: paid, nothing delivered (spec 6.1).
            Action::make('staff_cancel')->label('Cancel & refund')->color('danger')
                ->visible(fn (Order $record): bool => $record->status === OrderStatus::Paid && $record->item->delivered_qty === 0 && self::canCancel())
                ->schema([Textarea::make('reason')->label('Reason (shown in the order timeline)')->required()->maxLength(400)])
                ->action(fn (Order $record, array $data) => self::run(fn () => app(OrderStateMachine::class)->cancel($record, 'staff: '.SellerProfileResource::reason($data), ActorType::Staff, SellerProfileResource::actor()), 'Order cancelled and refunded')),
        ];
    }

    /**
     * @param  callable(): mixed  $decision
     */
    private static function run(callable $decision, string $message): void
    {
        try {
            $decision();
            Notification::make()->title($message)->success()->send();
        } catch (CheckoutException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    private static function canCancel(): bool
    {
        $user = Auth::user();

        return $user instanceof User && ($user->hasStaffRole(StaffRole::Support) || $user->hasStaffRole(StaffRole::Finance) || $user->hasStaffRole(StaffRole::Admin));
    }

    private static function timeline(Order $order): HtmlString
    {
        $rows = OrderStatusHistory::query()->where('order_id', $order->id)->orderBy('id')->get();
        $html = '<table class="w-full text-sm"><thead><tr><th class="text-start">When</th><th class="text-start">Status</th><th class="text-start">Actor</th><th class="text-start">Reason</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr><td>'.e($row->created_at->toDateTimeString()).'</td><td>'.e(($row->from_status->value ?? '—').' → '.$row->to_status->value).'</td><td>'.e($row->actor_type->value.($row->actor_id !== null ? ' #'.$row->actor_id : '')).'</td><td>'.e((string) $row->reason).'</td></tr>';
        }

        return new HtmlString($html.'</tbody></table>');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }
}
