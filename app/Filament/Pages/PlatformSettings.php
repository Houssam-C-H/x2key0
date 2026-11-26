<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domains\Admin\Actions\ChangePolicySetting;
use App\Domains\Admin\Models\SettingChange;
use App\Domains\Admin\Support\PolicyCatalog;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Shared\Support\Settings;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use LogicException;
use UnitEnum;

/**
 * Platform settings (docs/ADMIN_PLAN.md item 1): every Part 27 value on five tabs with its default, a reason for
 * each save, and money settings held until a second admin approves them. Admins only; writes go through
 * ChangePolicySetting.
 *
 * @property-read Schema $form
 */
final class PlatformSettings extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Platform settings';

    protected static ?string $slug = 'platform-settings';

    protected string $view = 'filament.pages.platform-settings';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->hasStaffRole(StaffRole::Admin);
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = SettingChange::query()->where('status', SettingChange::PENDING)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public function mount(): void
    {
        $this->form->fill($this->currentValues());
    }

    public function form(Schema $schema): Schema
    {
        $settings = app(Settings::class);
        $tabs = [];
        foreach (PolicyCatalog::GROUPS as $group => $title) {
            $tabs[] = Tab::make($title)->schema(array_map(fn (string $key): Component => self::field($key, $settings), PolicyCatalog::keysIn($group)))->columns(2);
        }

        return $schema
            ->statePath('data')
            ->components([
                Tabs::make('groups')->tabs($tabs)->persistTabInQueryString(),
                Section::make('Save')->schema([
                    Textarea::make('reason')->label('Reason for this change (kept with every changed value)')->required()->minLength(5)->maxLength(500)->rows(2),
                ]),
            ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $reason = is_string($state['reason'] ?? null) ? $state['reason'] : '';
        $current = $this->currentValues();
        $change = app(ChangePolicySetting::class);
        $applied = $pending = 0;
        $errors = [];

        foreach (array_keys(PolicyCatalog::all()) as $key) {
            $typed = $state[$key] ?? null;
            if (self::sameInput($typed, $current[$key] ?? null)) {
                continue;
            }
            $value = is_array($typed)
                ? array_map(fn (mixed $v): mixed => is_string($v) && ctype_digit($v) ? (int) $v : $v, $typed)
                : PolicyCatalog::fromInput($key, $typed);

            try {
                $result = $change->request($this->staff(), $key, $value, $reason);
                $result->status === SettingChange::APPLIED ? $applied++ : $pending++;
            } catch (DomainException $e) {
                $errors[] = $e->getMessage();
            }
        }

        foreach ($errors as $error) {
            Notification::make()->danger()->title($error)->send();
        }
        if ($applied + $pending === 0 && $errors === []) {
            Notification::make()->warning()->title('Nothing changed.')->send();

            return;
        }
        if ($applied + $pending > 0) {
            Notification::make()->success()
                ->title("{$applied} applied, {$pending} waiting for a second admin")
                ->send();
        }

        $this->form->fill([...$this->currentValues(), 'reason' => null]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Changes')
            ->query(SettingChange::query()->with(['requester', 'decider']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Requested')->dateTime()->sortable(),
                TextColumn::make('key')->label('Setting')->formatStateUsing(fn (string $state): string => PolicyCatalog::label($state))->description(fn (SettingChange $r): string => $r->key)->wrap(),
                TextColumn::make('old_value')->label('From')->state(fn (SettingChange $r): string => PolicyCatalog::display($r->key, $r->old_value))->wrap(),
                TextColumn::make('new_value')->label('To')->state(fn (SettingChange $r): string => PolicyCatalog::display($r->key, $r->new_value).($r->is_reset ? ' (default)' : ''))->wrap(),
                TextColumn::make('reason')->wrap()->limit(80),
                TextColumn::make('requester.username')->label('By'),
                TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                    SettingChange::PENDING => 'warning',
                    SettingChange::APPLIED => 'success',
                    default => 'danger',
                }),
                TextColumn::make('decider.username')->label('Decided by')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->options([SettingChange::PENDING => 'Pending', SettingChange::APPLIED => 'Applied', SettingChange::REJECTED => 'Rejected']),
                SelectFilter::make('key')->label('Setting')->options(collect(PolicyCatalog::all())->map(fn (array $d): string => $d[2])->all())->searchable(),
            ])
            ->recordActions([
                Action::make('approve')->color('success')->requiresConfirmation()
                    ->modalDescription(fn (SettingChange $record): string => PolicyCatalog::label($record->key).': '.PolicyCatalog::display($record->key, $record->old_value).' → '.PolicyCatalog::display($record->key, $record->new_value))
                    ->visible(fn (SettingChange $record): bool => $record->status === SettingChange::PENDING && $record->requested_by !== $this->staff()->id)
                    ->action(fn (SettingChange $record) => $this->run(fn () => app(ChangePolicySetting::class)->approve($record, $this->staff()), 'Change applied')),
                Action::make('reject')->color('danger')
                    ->visible(fn (SettingChange $record): bool => $record->status === SettingChange::PENDING)
                    ->schema([Textarea::make('why')->label('Reason')->required()->maxLength(500)])
                    ->action(fn (SettingChange $record, array $data) => $this->run(fn () => app(ChangePolicySetting::class)->reject($record, $this->staff(), is_string($data['why'] ?? null) ? $data['why'] : ''), 'Change rejected')),
            ]);
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('reset')->label('Reset a setting to its default')->color('gray')
                ->schema([
                    Select::make('key')->label('Setting')->required()->searchable()
                        ->options(fn (): array => collect(array_keys(PolicyCatalog::all()))
                            ->filter(fn (string $k): bool => app(Settings::class)->isOverridden($k))
                            ->mapWithKeys(fn (string $k): array => [$k => PolicyCatalog::label($k).' (now '.PolicyCatalog::display($k, app(Settings::class)->get($k)).', default '.PolicyCatalog::display($k, config("x2key.policy.{$k}")).')'])
                            ->all()),
                    Textarea::make('reason')->required()->minLength(5)->maxLength(500),
                ])
                ->action(function (array $data): void {
                    $key = is_string($data['key'] ?? null) ? $data['key'] : '';
                    $this->run(fn () => app(ChangePolicySetting::class)->request($this->staff(), $key, null, is_string($data['reason'] ?? null) ? $data['reason'] : '', reset: true), 'Reset requested');
                    $this->form->fill($this->currentValues());
                }),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function currentValues(): array
    {
        $settings = app(Settings::class);
        $values = [];
        foreach (array_keys(PolicyCatalog::all()) as $key) {
            $values[$key] = PolicyCatalog::toInput($key, $settings->get($key));
        }

        return $values;
    }

    private static function field(string $key, Settings $settings): Component
    {
        $kind = PolicyCatalog::kind($key);
        $default = config("x2key.policy.{$key}");
        $hint = 'Default: '.PolicyCatalog::display($key, $default).(PolicyCatalog::needsSecondApproval($key) ? ' · needs a second admin' : '');
        $label = PolicyCatalog::label($key);

        if ($kind === 'days_map') {
            /** @var array<string, int> $default */
            $fields = [];
            foreach ($default as $type => $days) {
                $fields[] = TextInput::make("{$key}.{$type}")->label(str_replace('_', ' ', $type))->integer()->minValue(0)->suffix('days')->helperText("Default: {$days}");
            }

            return Fieldset::make($label)->schema($fields)->columns(3)->columnSpanFull();
        }

        $field = match (true) {
            $kind === 'bool' => Toggle::make($key),
            str_starts_with($kind, 'choice:') => Select::make($key)->options(collect(explode('|', substr($kind, 7)))->mapWithKeys(fn (string $o): array => [$o => str_replace('_', ' ', $o)])->all())->selectablePlaceholder(false),
            PolicyCatalog::decimals($key) > 0 => TextInput::make($key)->prefix(PolicyCatalog::kind($key) === 'bps' ? null : 'USD')->suffix(PolicyCatalog::kind($key) === 'bps' ? '%' : null)->regex('/^\d+(\.\d{0,'.PolicyCatalog::decimals($key).'})?$/'),
            default => TextInput::make($key)->integer()->minValue(0)->suffix(PolicyCatalog::UNITS[ltrim($kind, '?')] ?? null),
        };

        $overridden = $settings->isOverridden($key);

        return $field->label($label)->helperText($hint)->hint($overridden ? 'changed' : null)->hintColor('warning');
    }

    private static function sameInput(mixed $typed, mixed $current): bool
    {
        if (is_array($typed) && is_array($current)) {
            return array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $typed) == array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $current);
        }
        if (is_bool($current)) {
            return (bool) $typed === $current;
        }

        return (is_scalar($typed) ? (string) $typed : '') === (is_scalar($current) ? (string) $current : '');
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

    private function staff(): User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : throw new LogicException('Staff only.');
    }
}
