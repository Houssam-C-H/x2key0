<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Payments\Actions\ReapplyPaymentEvent;
use App\Domains\Payments\Models\PaymentEvent;
use App\Filament\Resources\Payments\Pages\ListPaymentEvents;
use App\Filament\Support\Staff;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Payment provider event log (docs/ADMIN_PLAN.md item 5): every webhook as received, whether its signature was
 * valid, when it was applied and with which error. Stuck or `unknown_payment` events can be re-applied safely
 * (ReapplyPaymentEvent). Finance and admins.
 */
final class PaymentEventResource extends Resource
{
    protected static ?string $model = PaymentEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static string|UnitEnum|null $navigationGroup = 'Payments';

    protected static ?string $navigationLabel = 'Provider events';

    protected static ?string $slug = 'payment-events';

    protected static ?int $navigationSort = 2;

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

    public static function getNavigationBadge(): ?string
    {
        $stuck = PaymentEvent::query()->whereNull('processed_at')->where('signature_valid', true)->where('received_at', '<', now()->subMinutes(10))->count();

        return $stuck > 0 ? (string) $stuck : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('received_at')->label('Received')->dateTime()->sortable(),
                TextColumn::make('provider'),
                TextColumn::make('type')->searchable(),
                TextColumn::make('provider_event_id')->label('Event ID')->searchable()->copyable()->limit(24),
                IconColumn::make('signature_valid')->label('Signed')->boolean(),
                TextColumn::make('processed_at')->label('Applied')->since()->placeholder('not yet'),
                TextColumn::make('error')->badge()->color('danger')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('provider')->options(fn (): array => PaymentEvent::query()->distinct()->orderBy('provider')->pluck('provider', 'provider')->all()),
                Filter::make('stuck')->label('Not applied')->query(fn (Builder $query): Builder => $query->whereNull('processed_at')),
                Filter::make('errors')->label('With an error')->query(fn (Builder $query): Builder => $query->whereNotNull('error')),
                Filter::make('unsigned')->label('Invalid signature')->query(fn (Builder $query): Builder => $query->where('signature_valid', false)),
            ])
            ->recordActions([
                Action::make('payload')->icon(Heroicon::OutlinedCodeBracket)->color('gray')
                    ->modalHeading(fn (PaymentEvent $record): string => $record->type.' · '.$record->provider_event_id)
                    ->modalSubmitAction(false)
                    ->modalContent(fn (PaymentEvent $record): HtmlString => new HtmlString('<pre class="text-xs whitespace-pre-wrap break-all">'.e((string) json_encode($record->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)).'</pre>')),
                Action::make('reapply')->label('Re-apply')->icon(Heroicon::OutlinedArrowPath)->color('warning')
                    ->visible(fn (PaymentEvent $record): bool => ReapplyPaymentEvent::canRetry($record))
                    ->requiresConfirmation()
                    ->modalDescription('Only events that never changed anything are re-run. Applying is idempotent.')
                    ->action(function (PaymentEvent $record): void {
                        try {
                            $event = app(ReapplyPaymentEvent::class)->handle($record, Staff::current());
                            $event->error === null
                                ? Notification::make()->success()->title('Event applied')->send()
                                : Notification::make()->warning()->title('Applied with result: '.$event->error)->send();
                        } catch (DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return ['index' => ListPaymentEvents::route('/')];
    }
}
