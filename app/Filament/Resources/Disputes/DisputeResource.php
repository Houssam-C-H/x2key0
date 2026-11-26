<?php

declare(strict_types=1);

namespace App\Filament\Resources\Disputes;

use App\Domains\Disputes\Actions\OpenDispute;
use App\Domains\Disputes\Actions\ResolveDispute;
use App\Domains\Disputes\Enums\DisputeOutcome;
use App\Domains\Disputes\Enums\DisputeReason;
use App\Domains\Disputes\Enums\DisputeRole;
use App\Domains\Disputes\Enums\DisputeStatus;
use App\Domains\Disputes\Exceptions\DisputeException;
use App\Domains\Disputes\Models\Dispute;
use App\Domains\Identity\Models\User;
use App\Filament\Pages\ChatTranscript;
use App\Filament\Resources\Disputes\Pages\ListDisputes;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Dispute queue and decision form (spec Part 18 / Part 10): escalated cases first; staff read the full thread
 * (including internal notes and unredacted text), add internal notes, and decide: outcome, units, refund
 * destination, seller fault, public rationale and internal notes. Executed by ResolveDispute.
 */
final class DisputeResource extends Resource
{
    protected static ?string $model = Dispute::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Orders & risk';

    protected static ?string $slug = 'disputes';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $escalated = Dispute::query()->where('status', DisputeStatus::Escalated)->count();

        return $escalated > 0 ? (string) $escalated : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['order.buyer', 'order.seller', 'item'])
                ->orderByRaw("case status when 'escalated' then 0 when 'awaiting_counterparty' then 1 when 'negotiating' then 2 else 3 end")
                ->orderBy('id'))
            ->columns([
                TextColumn::make('public_id')->label('Case')->formatStateUsing(fn (string $state): string => strtoupper(substr($state, -8)))->searchable(),
                TextColumn::make('order.number')->label('Order')->searchable(),
                TextColumn::make('order.buyer.username')->label('Buyer'),
                TextColumn::make('order.seller.store_name')->label('Store'),
                TextColumn::make('opened_by_role')->label('Opened by')->formatStateUsing(fn (DisputeRole $state): string => ucfirst($state->value)),
                TextColumn::make('reason')->formatStateUsing(fn (DisputeReason $state): string => str_replace('_', ' ', $state->value)),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (DisputeStatus $state): string => str_replace('_', ' ', $state->value))
                    ->color(fn (DisputeStatus $state): string => match ($state) {
                        DisputeStatus::Escalated => 'danger',
                        DisputeStatus::Resolved => 'success',
                        DisputeStatus::Cancelled => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('requested_resolution')->label('Requested')
                    ->formatStateUsing(fn (Dispute $record): string => str_replace('_', ' ', $record->requested_resolution->value).' × '.$record->requested_qty),
                TextColumn::make('response_due_at')->label('Response due')->since()->placeholder('—'),
                TextColumn::make('updated_at')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(DisputeStatus::cases())->mapWithKeys(fn (DisputeStatus $s): array => [$s->value => str_replace('_', ' ', $s->value)])->all()),
            ])
            ->recordActions([
                Action::make('chat')->label('Chat')->icon(Heroicon::OutlinedChatBubbleOvalLeft)->color('gray')
                    ->visible(fn (Dispute $record): bool => ChatTranscript::urlForOrder($record->order_id, 'dispute', $record->public_id) !== null)
                    ->url(fn (Dispute $record): ?string => ChatTranscript::urlForOrder($record->order_id, 'dispute', $record->public_id)),
                Action::make('thread')
                    ->label('Case')
                    ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                    ->modalHeading(fn (Dispute $record): string => 'Case '.strtoupper(substr($record->public_id, -8)).' · order '.$record->order->number)
                    ->modalContent(fn (Dispute $record) => view('filament.disputes.thread', ['dispute' => $record->load(['messages.author', 'evidence', 'decision', 'history', 'order.item'])]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                Action::make('note')
                    ->label('Internal note')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn (Dispute $record): bool => Gate::allows('decide', $record))
                    ->schema([Textarea::make('body')->label('Note (staff only)')->required()->maxLength(2000)])
                    ->action(function (Dispute $record, array $data): void {
                        OpenDispute::message($record, self::actor(), DisputeRole::Staff, is_string($data['body'] ?? null) ? $data['body'] : '', internal: true);
                        Notification::make()->title('Note added')->success()->send();
                    }),
                Action::make('message')
                    ->label('Message parties')
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->visible(fn (Dispute $record): bool => $record->status->isOpen() && Gate::allows('decide', $record))
                    ->schema([Textarea::make('body')->label('Message shown to buyer and seller')->required()->maxLength(2000)])
                    ->action(function (Dispute $record, array $data): void {
                        OpenDispute::message($record, self::actor(), DisputeRole::Staff, is_string($data['body'] ?? null) ? $data['body'] : '');
                        Notification::make()->title('Message sent')->success()->send();
                    }),
                Action::make('decide')
                    ->color('primary')
                    ->icon(Heroicon::OutlinedCheckBadge)
                    ->visible(fn (Dispute $record): bool => $record->status->isOpen() && Gate::allows('decide', $record))
                    ->schema([
                        Select::make('outcome')->required()->live()->options([
                            DisputeOutcome::RefundFull->value => 'Full refund',
                            DisputeOutcome::RefundPartial->value => 'Partial refund (units)',
                            DisputeOutcome::Replacement->value => 'Replacement (units back to the seller)',
                            DisputeOutcome::Redelivery->value => 'Redelivery (new deadline)',
                            DisputeOutcome::NoRefund->value => 'No refund',
                        ]),
                        TextInput::make('qty')->label('Units')->numeric()->minValue(0)->default(1)
                            ->visible(fn (Get $get): bool => in_array($get('outcome'), ['refund_partial', 'replacement', 'redelivery'], true)),
                        Select::make('destination')->label('Refund to')->default('original')
                            ->options(['original' => 'Original payment sources', 'credits' => 'Credits Balance'])
                            ->visible(fn (Get $get): bool => in_array($get('outcome'), ['refund_full', 'refund_partial'], true)),
                        Toggle::make('seller_fault')->label('Seller at fault (counts as a lost dispute, raises a violation)'),
                        Textarea::make('rationale')->label('Rationale shown to both parties')->required()->maxLength(5000),
                        Textarea::make('internal_notes')->label('Internal notes')->maxLength(5000),
                    ])
                    ->action(function (Dispute $record, array $data): void {
                        try {
                            app(ResolveDispute::class)->handle($record, DisputeOutcome::from(is_string($data['outcome'] ?? null) ? $data['outcome'] : ''), DisputeRole::Staff, self::actor(), [
                                'qty' => is_numeric($data['qty'] ?? null) ? (int) $data['qty'] : $record->requested_qty,
                                'to_credits' => ($data['destination'] ?? 'original') === 'credits',
                                'seller_fault' => (bool) ($data['seller_fault'] ?? false),
                                'rationale' => is_string($data['rationale'] ?? null) ? $data['rationale'] : null,
                                'internal_notes' => is_string($data['internal_notes'] ?? null) ? $data['internal_notes'] : null,
                            ]);
                        } catch (DisputeException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Dispute decided')->success()->send();
                    }),
            ]);
    }

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['public_id', 'order.number'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return $record instanceof Dispute ? 'Dispute on order '.$record->order->number : '';
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return $record instanceof Dispute ? ['Status' => str_replace('_', ' ', $record->status->value)] : [];
    }

    public static function getGlobalSearchResultUrl(Model $record): string
    {
        return self::getUrl('index', ['search' => $record instanceof Dispute ? $record->order->number : '']);
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
        return ['index' => ListDisputes::route('/')];
    }

    private static function actor(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
