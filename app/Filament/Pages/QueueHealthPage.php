<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Support\Staff;
use App\Shared\Ops\FailedJob;
use App\Shared\Ops\MailLog;
use App\Shared\Ops\QueueHealth;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Queues and e-mail (docs/ADMIN_PLAN.md item 12): queue sizes, failed jobs with retry / forget, sent-mail log and
 * push subscription counts — so a stuck queue is visible. Admins only.
 */
final class QueueHealthPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Queues & e-mail';

    protected static ?string $title = 'Queues & e-mail';

    protected static ?string $slug = 'queues';

    protected string $view = 'filament.pages.queue-health';

    public static function canAccess(): bool
    {
        return Staff::has();
    }

    public static function getNavigationBadge(): ?string
    {
        $failed = FailedJob::query()->where('failed_at', '>=', now()->subDay())->count();

        return $failed > 0 ? (string) $failed : null;
    }

    /**
     * @return array{sizes: array<string, int|null>, counters: array<string, int>, mails: list<array{to: string, subject: string, mailable: string, at: string}>}
     */
    public function health(): array
    {
        $health = app(QueueHealth::class);

        return [
            'sizes' => $health->sizes(),
            'counters' => $health->counters(),
            'mails' => array_values(MailLog::query()->latest('id')->limit(30)->get()->map(fn (MailLog $m): array => [
                'to' => $m->to,
                'subject' => $m->subject,
                'mailable' => $m->mailable ?? '—',
                'at' => $m->sent_at->format('Y-m-d H:i'),
            ])->all()),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Failed jobs')
            ->query(FailedJob::query())
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('failed_at')->label('Failed')->dateTime()->sortable(),
                TextColumn::make('queue')->badge()->color('gray'),
                TextColumn::make('job')->label('Job')->state(fn (FailedJob $record): string => $record->jobName()),
                TextColumn::make('error')->label('Error')->wrap()->state(fn (FailedJob $record): string => $record->shortError()),
            ])
            ->filters([
                SelectFilter::make('queue')->options(fn (): array => FailedJob::query()->distinct()->orderBy('queue')->pluck('queue', 'queue')->all()),
            ])
            ->recordActions([
                Action::make('trace')->icon(Heroicon::OutlinedCodeBracket)->color('gray')
                    ->modalHeading(fn (FailedJob $record): string => $record->jobName())
                    ->modalSubmitAction(false)
                    ->modalContent(fn (FailedJob $record): HtmlString => new HtmlString('<pre class="text-xs whitespace-pre-wrap break-all">'.e(mb_substr($record->exception, 0, 8000)).'</pre>')),
                Action::make('retry')->icon(Heroicon::OutlinedArrowPath)->color('warning')->requiresConfirmation()
                    ->action(fn (FailedJob $record) => $this->run(fn () => app(QueueHealth::class)->retry(Staff::current(), $record->uuid), 'Job pushed back on its queue')),
                Action::make('forget')->icon(Heroicon::OutlinedTrash)->color('danger')->requiresConfirmation()
                    ->action(fn (FailedJob $record) => $this->run(fn () => app(QueueHealth::class)->forget(Staff::current(), $record->uuid), 'Failed job removed')),
            ])
            ->toolbarActions([
                BulkAction::make('retry_all')->label('Retry selected')->icon(Heroicon::OutlinedArrowPath)->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records): void {
                        foreach ($records as $record) {
                            if ($record instanceof FailedJob) {
                                app(QueueHealth::class)->retry(Staff::current(), $record->uuid);
                            }
                        }
                        Notification::make()->success()->title($records->count().' jobs pushed back')->send();
                    }),
            ]);
    }

    private function run(callable $action, string $message): void
    {
        try {
            $action();
            Notification::make()->success()->title($message)->send();
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }
}
