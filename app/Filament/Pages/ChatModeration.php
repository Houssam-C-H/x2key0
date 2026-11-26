<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Actions\StaffChatAccess;
use App\Domains\Messaging\Models\Message;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use LogicException;
use UnitEnum;

/**
 * Flagged chat messages (docs/ADMIN_PLAN.md item 6): messages whose contact details were hidden from the other
 * party, newest first, until staff mark them reviewed. Each row opens the transcript (audited).
 */
final class ChatModeration extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftEllipsis;

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected static ?string $navigationLabel = 'Chat moderation';

    protected static ?string $slug = 'chat-moderation';

    protected string $view = 'filament.pages.table-only';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && StaffChatAccess::allowed($user);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Message::query()->whereNotNull('body_redacted')->whereNull('reviewed_at')->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Message::query()->with(['sender', 'conversation.order', 'conversation.seller'])->whereNotNull('body_redacted'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('sender.username')->label('Sender')->placeholder('system')->searchable(),
                TextColumn::make('sender_role')->label('Role')->badge(),
                TextColumn::make('body')->label('Message')->wrap()->limit(160)->searchable(),
                TextColumn::make('conversation.order.number')->label('Order')->placeholder('pre-sale'),
                TextColumn::make('conversation.seller.store_name')->label('Store')->placeholder('—'),
                TextColumn::make('reviewed_at')->label('Reviewed')->since()->placeholder('waiting'),
            ])
            ->filters([
                TernaryFilter::make('reviewed')->label('Reviewed')->default(false)
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('reviewed_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('reviewed_at'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->recordActions([
                Action::make('transcript')->label('Transcript')->icon(Heroicon::OutlinedChatBubbleLeftRight)->color('gray')
                    ->url(fn (Message $record): string => ChatTranscript::getUrl(['conversation' => $record->conversation->public_id, 'context' => 'flagged', 'ref' => (string) $record->id])),
                Action::make('review')->label('Mark reviewed')->icon(Heroicon::OutlinedCheck)->color('success')
                    ->visible(fn (Message $record): bool => $record->reviewed_at === null)
                    ->action(function (Message $record): void {
                        app(StaffChatAccess::class)->markReviewed($this->staff(), $record);
                        Notification::make()->success()->title('Marked reviewed')->send();
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('review_all')->label('Mark reviewed')->icon(Heroicon::OutlinedCheck)
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records): void {
                        foreach ($records as $message) {
                            if ($message instanceof Message) {
                                app(StaffChatAccess::class)->markReviewed($this->staff(), $message);
                            }
                        }
                        Notification::make()->success()->title($records->count().' messages marked reviewed')->send();
                    }),
            ]);
    }

    private function staff(): User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : throw new LogicException('Staff only.');
    }
}
