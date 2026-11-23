<?php

declare(strict_types=1);

namespace App\Filament\Resources\Violations;

use App\Domains\Identity\Models\User;
use App\Domains\Orders\Models\Order;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Violations\Actions\DecideViolation;
use App\Domains\Violations\Actions\RecordViolation;
use App\Domains\Violations\Enums\ViolationStatus;
use App\Domains\Violations\Exceptions\ViolationException;
use App\Domains\Violations\Models\Violation;
use App\Domains\Violations\Models\ViolationRule;
use App\Filament\Resources\Violations\Pages\ListViolations;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
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
 * Violation Center for staff (spec 17.2, Part 18): appeal queue first, uphold or revoke with a note, and raise
 * staff violations (prohibited listing, fake delivery evidence, fraud…). Penalties follow automatically.
 */
final class ViolationResource extends Resource
{
    protected static ?string $model = Violation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|UnitEnum|null $navigationGroup = 'Sellers';

    protected static ?string $slug = 'sellers/violations';

    protected static ?int $navigationSort = 4;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $appealed = Violation::query()->where('status', ViolationStatus::Appealed)->count();

        return $appealed > 0 ? (string) $appealed : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['seller', 'rule', 'order'])
                ->orderByRaw("case status when 'appealed' then 0 when 'open' then 1 else 2 end")->orderByDesc('id'))
            ->columns([
                TextColumn::make('seller.store_name')->label('Store')->searchable(),
                TextColumn::make('rule.code')->label('Rule')->badge(),
                TextColumn::make('points'),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (ViolationStatus $state): string => ucfirst($state->value))
                    ->color(fn (ViolationStatus $state): string => match ($state) {
                        ViolationStatus::Appealed => 'warning',
                        ViolationStatus::Upheld => 'danger',
                        ViolationStatus::Revoked => 'success',
                        ViolationStatus::Open => 'gray',
                    }),
                TextColumn::make('order.number')->label('Order')->placeholder('—'),
                TextColumn::make('details')->wrap()->limit(80)->placeholder('—'),
                TextColumn::make('appeal_text')->label('Appeal')->wrap()->limit(80)->placeholder('—'),
                TextColumn::make('created_at')->since(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(ViolationStatus::cases())->mapWithKeys(fn (ViolationStatus $s): array => [$s->value => ucfirst($s->value)])->all()),
            ])
            ->headerActions([
                Action::make('raise')
                    ->label('Raise violation')
                    ->visible(fn (): bool => Gate::allows('create', Violation::class))
                    ->schema([
                        Select::make('store')->label('Store')->required()->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => SellerProfile::query()->where('store_name', 'like', "%{$search}%")->limit(20)->pluck('store_name', 'id')->all()),
                        Select::make('rule')->required()->options(fn (): array => ViolationRule::query()->where('is_enabled', true)->orderBy('code')->get()
                            ->mapWithKeys(fn (ViolationRule $r): array => [$r->id => "{$r->translate('name', 'en')} ({$r->points} pts, {$r->penalty->value})"])->all()),
                        TextInput::make('order')->label('Order number (optional)'),
                        Textarea::make('details')->label('Details shown to the seller')->required()->maxLength(500),
                    ])
                    ->action(function (array $data): void {
                        $seller = SellerProfile::query()->whereKey($data['store'] ?? 0)->first();
                        $rule = ViolationRule::query()->whereKey($data['rule'] ?? 0)->first();
                        if ($seller === null || $rule === null) {
                            return;
                        }
                        $orderId = is_string($data['order'] ?? null) && $data['order'] !== ''
                            ? Order::query()->where('number', trim($data['order']))->where('seller_profile_id', $seller->id)->value('id')
                            : null;

                        app(RecordViolation::class)->manual($rule, $seller, self::actor(), is_string($data['details'] ?? null) ? $data['details'] : null, is_int($orderId) ? $orderId : null);
                        Notification::make()->title('Violation raised; the seller can appeal')->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('uphold')->color('danger')
                    ->visible(fn (Violation $record): bool => in_array($record->status, [ViolationStatus::Open, ViolationStatus::Appealed], true) && Gate::allows('decide', $record))
                    ->schema([Textarea::make('note')->label('Note shown to the seller')->maxLength(500)])
                    ->action(fn (Violation $record, array $data) => self::decide(fn () => app(DecideViolation::class)->uphold($record, self::actor(), self::note($data)), 'Violation upheld')),
                Action::make('revoke')->color('success')
                    ->visible(fn (Violation $record): bool => in_array($record->status, [ViolationStatus::Open, ViolationStatus::Appealed], true) && Gate::allows('decide', $record))
                    ->schema([Textarea::make('note')->label('Note shown to the seller')->maxLength(500)])
                    ->action(fn (Violation $record, array $data) => self::decide(fn () => app(DecideViolation::class)->revoke($record, self::actor(), self::note($data)), 'Violation revoked')),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return ['index' => ListViolations::route('/')];
    }

    private static function decide(callable $action, string $title): void
    {
        try {
            $action();
        } catch (ViolationException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($title)->success()->send();
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function note(array $data): ?string
    {
        return is_string($data['note'] ?? null) && $data['note'] !== '' ? $data['note'] : null;
    }

    private static function actor(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
