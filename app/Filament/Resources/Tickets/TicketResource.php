<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tickets;

use App\Domains\Disputes\Enums\DisputeOutcome;
use App\Domains\Disputes\Enums\DisputeReason;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Tickets\Actions\TicketDesk;
use App\Domains\Tickets\Enums\TicketPriority;
use App\Domains\Tickets\Enums\TicketStatus;
use App\Domains\Tickets\Enums\TicketTopic;
use App\Domains\Tickets\Exceptions\TicketException;
use App\Domains\Tickets\Models\CannedReply;
use App\Domains\Tickets\Models\Ticket;
use App\Filament\Pages\ChatTranscript;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Support\CsvExport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Ticket queue (spec Part 11 / Part 18): waiting tickets first by SLA target (overdue in red), assignment,
 * priority, replies with canned answers, internal notes, close, and conversion of order tickets to disputes.
 */
final class TicketResource extends Resource
{
    protected static ?string $model = Ticket::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected static ?string $slug = 'tickets';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = Ticket::query()->where('status', TicketStatus::Pending)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'assignee', 'order'])
                ->orderByRaw("case status when 'pending' then 0 when 'awaiting_reply' then 1 else 2 end")
                ->orderByRaw('case when first_response_due_at is null then 1 else 0 end')
                ->orderBy('first_response_due_at')
                ->orderByDesc('id'))
            ->columns([
                TextColumn::make('subject')->searchable()->limit(50)
                    ->description(fn (Ticket $record): string => str_replace('_', ' ', $record->topic->value).($record->order ? ' · '.$record->order->number : '')),
                TextColumn::make('user.username')->label('User')->searchable(),
                TextColumn::make('priority')->badge()
                    ->formatStateUsing(fn (TicketPriority $state): string => ucfirst($state->value))
                    ->color(fn (TicketPriority $state): string => match ($state) {
                        TicketPriority::High => 'danger',
                        TicketPriority::Normal => 'warning',
                        TicketPriority::Low => 'gray',
                    }),
                TextColumn::make('status')->badge()->formatStateUsing(fn (TicketStatus $state): string => str_replace('_', ' ', $state->value))
                    ->color(fn (TicketStatus $state): string => match ($state) {
                        TicketStatus::Pending => 'warning',
                        TicketStatus::AwaitingReply => 'info',
                        TicketStatus::Closed => 'gray',
                    }),
                TextColumn::make('first_response_due_at')->label('SLA')->since()->placeholder('—')
                    ->color(fn (Ticket $record): ?string => $record->isOverdue() ? 'danger' : null),
                TextColumn::make('assignee.name')->label('Assigned')->placeholder('—'),
                TextColumn::make('last_reply_at')->label('Last reply')->since(),
                TextColumn::make('satisfaction')->label('CSAT')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(TicketStatus::cases())->mapWithKeys(fn (TicketStatus $s): array => [$s->value => str_replace('_', ' ', $s->value)])->all()),
                SelectFilter::make('priority')->options(TicketPriority::options()),
                SelectFilter::make('topic')->options(collect(TicketTopic::cases())->mapWithKeys(fn (TicketTopic $t): array => [$t->value => str_replace('_', ' ', $t->value)])->all()),
                Filter::make('mine')->label('Assigned to me')->query(fn (Builder $query) => $query->where('assigned_to', Auth::id())),
                Filter::make('overdue')->label('SLA overdue')->query(fn (Builder $query) => $query->where('status', TicketStatus::Pending->value)->where('first_response_due_at', '<', now())),
            ])
            ->recordActions([
                Action::make('chat')->label('Order chat')->icon(Heroicon::OutlinedChatBubbleOvalLeft)->color('gray')
                    ->visible(fn (Ticket $record): bool => ChatTranscript::urlForOrder($record->order_id, 'ticket', $record->public_id) !== null)
                    ->url(fn (Ticket $record): ?string => ChatTranscript::urlForOrder($record->order_id, 'ticket', $record->public_id)),
                Action::make('thread')->label('Open')->icon(Heroicon::OutlinedChatBubbleLeftRight)
                    ->modalHeading(fn (Ticket $record): string => $record->subject)
                    ->modalContent(fn (Ticket $record) => view('filament.tickets.thread', ['ticket' => $record->load(['messages.author', 'messages.attachments', 'history', 'order', 'user'])]))
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close'),
                Action::make('reply')->icon(Heroicon::OutlinedPaperAirplane)->color('primary')
                    ->visible(fn (Ticket $record): bool => Gate::allows('update', $record))
                    ->schema([
                        Select::make('canned')->label('Canned reply')->live()
                            ->options(fn (Ticket $record): array => CannedReply::query()->where('is_active', true)
                                ->where(fn (Builder $q) => $q->whereNull('topic')->orWhere('topic', $record->topic->value))
                                ->orderBy('title')->pluck('title', 'id')->all())
                            ->afterStateUpdated(function (Set $set, mixed $state, Ticket $record): void {
                                $reply = CannedReply::query()->whereKey(is_numeric($state) ? (int) $state : 0)->first();
                                if ($reply !== null) {
                                    $set('body', $reply->translate('body', $record->user->locale));
                                }
                            }),
                        Textarea::make('body')->label('Message')->required()->rows(6)->maxLength(5000),
                        Toggle::make('internal')->label('Internal note (not sent to the user)'),
                    ])
                    ->action(function (Ticket $record, array $data): void {
                        self::run(fn () => app(TicketDesk::class)->staffReply($record, self::actor(), is_string($data['body'] ?? null) ? $data['body'] : '', (bool) ($data['internal'] ?? false)), 'Sent');
                    }),
                Action::make('assign')->label('Assign')->icon(Heroicon::OutlinedUserPlus)
                    ->visible(fn (Ticket $record): bool => Gate::allows('update', $record))
                    ->schema([
                        Select::make('staff')->label('Staff member')->required()
                            ->options(fn (): array => User::role([StaffRole::Support->value, StaffRole::Admin->value])->orderBy('name')->pluck('name', 'id')->all())
                            ->default(fn (): ?int => is_int($id = Auth::id()) ? $id : null),
                        Select::make('priority')->options(TicketPriority::options())->default(fn (Ticket $record): string => $record->priority->value)->required(),
                    ])
                    ->action(function (Ticket $record, array $data): void {
                        $staff = User::query()->whereKey($data['staff'] ?? 0)->first();
                        app(TicketDesk::class)->assign($record, $staff);
                        app(TicketDesk::class)->prioritise($record->refresh(), TicketPriority::from(is_string($data['priority'] ?? null) ? $data['priority'] : 'normal'));
                        Notification::make()->title('Updated')->success()->send();
                    }),
                Action::make('close')->color('gray')->requiresConfirmation()
                    ->visible(fn (Ticket $record): bool => $record->status !== TicketStatus::Closed && Gate::allows('update', $record))
                    ->action(fn (Ticket $record) => self::run(fn () => app(TicketDesk::class)->close($record, 'staff', self::actor()), 'Ticket closed')),
                Action::make('convert')->label('Convert to dispute')->color('danger')->icon(Heroicon::OutlinedScale)
                    ->visible(fn (Ticket $record): bool => $record->order_id !== null && $record->dispute_id === null && $record->status !== TicketStatus::Closed && Gate::allows('update', $record))
                    ->schema([
                        Select::make('reason')->required()->options(collect(DisputeReason::cases())->mapWithKeys(fn (DisputeReason $r): array => [$r->value => str_replace('_', ' ', $r->value)])->all()),
                        Select::make('resolution')->required()->default('refund_full')->options(collect(DisputeOutcome::requestableValues())->mapWithKeys(fn (string $v): array => [$v => str_replace('_', ' ', $v)])->all()),
                        TextInput::make('qty')->label('Units')->numeric()->minValue(1)->default(1)->required(),
                    ])
                    ->action(function (Ticket $record, array $data): void {
                        self::run(fn () => app(TicketDesk::class)->convertToDispute(
                            $record,
                            self::actor(),
                            DisputeReason::from(is_string($data['reason'] ?? null) ? $data['reason'] : 'other'),
                            DisputeOutcome::from(is_string($data['resolution'] ?? null) ? $data['resolution'] : 'refund_full'),
                            is_numeric($data['qty'] ?? null) ? (int) $data['qty'] : 1,
                        ), 'Dispute opened; ticket closed');
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('assignSelected')->label('Assign selected')->icon(Heroicon::OutlinedUserPlus)
                    ->schema([
                        Select::make('staff')->label('Staff member')->required()
                            ->options(fn (): array => User::staffQuery()->orderBy('username')->pluck('username', 'id')->all())
                            ->default(fn (): ?int => is_int($id = Auth::id()) ? $id : null),
                    ])
                    ->deselectRecordsAfterCompletion()
                    ->action(function (EloquentCollection $records, array $data): void {
                        $staff = User::staffQuery()->whereKey(is_numeric($data['staff'] ?? null) ? (int) $data['staff'] : 0)->first();
                        $done = 0;
                        foreach ($records as $record) {
                            if ($staff !== null && $record instanceof Ticket && Gate::allows('update', $record)) {
                                app(TicketDesk::class)->assign($record, $staff);
                                $done++;
                            }
                        }
                        Notification::make()->title("{$done} ticket(s) assigned")->success()->send();
                    }),
                CsvExport::bulkAction('tickets', [
                    'Ticket' => fn (Model $r): string => $r instanceof Ticket ? $r->public_id : '',
                    'Subject' => fn (Model $r): string => $r instanceof Ticket ? $r->subject : '',
                    'User' => fn (Model $r): string => $r instanceof Ticket ? $r->user->username : '',
                    'Topic' => fn (Model $r): string => $r instanceof Ticket ? $r->topic->value : '',
                    'Priority' => fn (Model $r): string => $r instanceof Ticket ? $r->priority->value : '',
                    'Status' => fn (Model $r): string => $r instanceof Ticket ? $r->status->value : '',
                    'Created' => fn (Model $r): string => $r instanceof Ticket ? $r->created_at->toDateTimeString() : '',
                ]),
            ]);
    }

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['subject', 'public_id', 'user.username'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return $record instanceof Ticket ? 'Ticket: '.$record->subject : '';
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return $record instanceof Ticket ? ['From' => $record->user->username, 'Status' => str_replace('_', ' ', $record->status->value)] : [];
    }

    public static function getGlobalSearchResultUrl(Model $record): string
    {
        return self::getUrl('index', ['search' => $record instanceof Ticket ? $record->subject : '']);
    }

    /**
     * @return Builder<Model>
     */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('user');
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return ['index' => ListTickets::route('/')];
    }

    private static function run(callable $action, string $title): void
    {
        try {
            $action();
        } catch (TicketException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($title)->success()->send();
    }

    private static function actor(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
