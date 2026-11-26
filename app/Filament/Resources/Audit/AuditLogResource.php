<?php

declare(strict_types=1);

namespace App\Filament\Resources\Audit;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Filament\Resources\Audit\Pages\ListAuditLogs;
use App\Shared\Audit\AuditLog;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Audit log (docs/ADMIN_PLAN.md item 2): read-only list of every recorded change — who, what, which record,
 * before/after — filterable by staff member, action, record and date. Admins only. Rows are append-only and
 * secrets are already redacted when written (AuditLogger::redact).
 */
final class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Audit log';

    protected static ?string $slug = 'audit-log';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->hasStaffRole(StaffRole::Admin);
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

    /** Link to the audit entries of one record, e.g. from a 360° page. */
    public static function urlFor(Model $subject): string
    {
        $key = $subject->getKey();

        return self::getUrl('index', ['filters' => ['subject' => ['type' => $subject->getMorphClass(), 'id' => is_scalar($key) ? (string) $key : '']]]);
    }

    public static function table(Table $table): Table
    {
        $userMorph = (new User)->getMorphClass();

        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('actor'))
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('actor')->label('Who')
                    ->state(fn (AuditLog $record): string => $record->actor instanceof User ? $record->actor->username : ($record->actor_id === null ? 'system' : "#{$record->actor_id}")),
                TextColumn::make('action')->badge()->searchable(),
                TextColumn::make('subject')->label('Record')
                    ->state(fn (AuditLog $record): string => $record->subject_type === null ? '—' : class_basename($record->subject_type).($record->subject_id !== null ? " #{$record->subject_id}" : '')),
                TextColumn::make('change')->label('Change')->wrap()
                    ->state(fn (AuditLog $record): string => self::summary($record->before, $record->after)),
                TextColumn::make('ip')->label('IP')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('actor_id')->label('Staff member')->searchable()
                    ->options(fn (): array => User::staffQuery()->orderBy('username')->pluck('username', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $query->where('actor_type', $userMorph)->where('actor_id', $data['value']) : $query),
                SelectFilter::make('action')->searchable()->options(fn (): array => self::distinct('action')),
                TernaryFilter::make('staff')->label('Done by')->trueLabel('Staff only')->falseLabel('Users and system')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('actor_type', $userMorph)->whereIn('actor_id', User::staffQuery()->select('id')),
                        false: fn (Builder $query): Builder => $query->where(fn (Builder $w) => $w->whereNull('actor_id')->orWhereNotIn('actor_id', User::staffQuery()->select('id'))),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                Filter::make('subject')->label('Record')
                    ->schema([
                        Select::make('type')->label('Record type')->searchable()
                            ->options(fn (): array => collect(self::distinct('subject_type'))->mapWithKeys(fn (string $t): array => [$t => class_basename($t)])->all()),
                        TextInput::make('id')->label('Record ID')->integer(),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(filled($data['type'] ?? null), fn (Builder $w) => $w->where('subject_type', $data['type']))
                        ->when(filled($data['id'] ?? null), fn (Builder $w) => $w->where('subject_id', is_numeric($data['id']) ? (int) $data['id'] : 0)))
                    ->indicateUsing(fn (array $data): ?string => filled($data['type'] ?? null) || filled($data['id'] ?? null)
                        ? trim('Record: '.class_basename(is_string($data['type'] ?? null) ? $data['type'] : '').' '.(is_scalar($data['id'] ?? null) ? '#'.$data['id'] : ''))
                        : null),
                Filter::make('date')
                    ->schema([DatePicker::make('from'), DatePicker::make('until')])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(is_string($data['from'] ?? null) && $data['from'] !== '', fn (Builder $w) => $w->where('created_at', '>=', Carbon::parse((string) $data['from'])->startOfDay()))
                        ->when(is_string($data['until'] ?? null) && $data['until'] !== '', fn (Builder $w) => $w->where('created_at', '<', Carbon::parse((string) $data['until'])->addDay()->startOfDay()))),
            ])
            ->recordActions([
                Action::make('details')->icon(Heroicon::OutlinedEye)->color('gray')
                    ->modalHeading(fn (AuditLog $record): string => $record->action)
                    ->modalSubmitAction(false)
                    ->modalContent(fn (AuditLog $record): HtmlString => new HtmlString(view('filament.audit.details', ['log' => $record])->render())),
            ]);
    }

    /**
     * "key: before → after" for every key that differs, truncated for the list.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public static function summary(?array $before, ?array $after): string
    {
        $before ??= [];
        $after ??= [];
        $parts = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $b = $before[$key] ?? null;
            $a = $after[$key] ?? null;
            if ($b === $a) {
                continue;
            }
            $parts[] = array_key_exists($key, $before)
                ? "{$key}: ".self::short($b).' → '.self::short($a)
                : "{$key}: ".self::short($a);
        }

        return mb_strimwidth(implode(' · ', $parts), 0, 220, '…');
    }

    public static function short(mixed $value): string
    {
        return match (true) {
            $value === null => '∅',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => mb_strimwidth((string) $value, 0, 60, '…'),
            default => mb_strimwidth((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 60, '…'),
        };
    }

    /**
     * @return array<string, string>
     */
    private static function distinct(string $column): array
    {
        /** @var array<string, string> */
        return Cache::remember("admin.audit.distinct.{$column}", 300, fn (): array => AuditLog::query()
            ->whereNotNull($column)->distinct()->orderBy($column)->pluck($column, $column)->all());
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return ['index' => ListAuditLogs::route('/')];
    }
}
