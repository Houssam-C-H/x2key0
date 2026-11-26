<?php

declare(strict_types=1);

namespace App\Filament\Resources\Points;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Points\Actions\PointsAdjustments;
use App\Domains\Points\Models\PointsAdjustment;
use App\Domains\Points\Support\Points;
use App\Filament\Resources\Points\Pages\ListPointsAdjustments;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\Staff;
use App\Shared\Support\Settings;
use BackedEnum;
use DomainException;
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
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Manual X2K Score adjustments (docs/ADMIN_PLAN.md item 7) through the points ledger; large ones wait for a second
 * approver (PointsAdjustments). Finance and admins.
 */
final class PointsAdjustmentResource extends Resource
{
    protected static ?string $model = PointsAdjustment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Points';

    protected static ?string $navigationLabel = 'Adjustments';

    protected static ?string $slug = 'points/adjustments';

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
        $pending = PointsAdjustment::query()->where('status', PointsAdjustment::PENDING)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    /** Header action on the list: request an adjustment for one user. */
    public static function requestAction(): Action
    {
        return Action::make('adjust')->label('Adjust a user’s points')->icon(Heroicon::OutlinedPlus)
            ->schema([
                Select::make('user_id')->label('User')->required()->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => User::query()
                        ->where(fn (Builder $q) => $q->where('username', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                        ->limit(20)->pluck('username', 'id')->all())
                    ->getOptionLabelUsing(fn (mixed $value): ?string => is_numeric($value) ? User::query()->find((int) $value)?->username : null)
                    ->live()
                    ->helperText(fn (mixed $state): ?string => is_numeric($state) ? 'Current balance: '.number_format(Points::balance((int) $state)).' points' : null),
                TextInput::make('points')->label('Points (negative to take back)')->required()->integer()->notIn(['0'])
                    ->helperText(fn (): string => 'From '.number_format(app(Settings::class)->int('points_adjustment_dual_approval')).' points a second approver is needed.'),
                Textarea::make('reason')->required()->minLength(10)->maxLength(1000),
            ])
            ->action(function (array $data): void {
                $user = User::query()->find(is_numeric($data['user_id'] ?? null) ? (int) $data['user_id'] : 0);
                if ($user === null) {
                    Notification::make()->danger()->title('User not found')->send();

                    return;
                }
                self::run(fn (): PointsAdjustment => app(PointsAdjustments::class)->request(Staff::current(), $user, is_numeric($data['points'] ?? null) ? (int) $data['points'] : 0, is_string($data['reason'] ?? null) ? $data['reason'] : ''));
            });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'requester', 'approver']))
            ->columns([
                TextColumn::make('created_at')->label('Requested')->dateTime()->sortable(),
                TextColumn::make('user.username')->label('User')->searchable()
                    ->url(fn (PointsAdjustment $record): string => UserResource::getUrl('view', ['record' => $record->user_id])),
                TextColumn::make('points')->numeric()->color(fn (int $state): string => $state > 0 ? 'success' : 'danger'),
                TextColumn::make('reason')->wrap()->limit(80),
                TextColumn::make('requester.username')->label('By'),
                TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                    PointsAdjustment::POSTED => 'success',
                    PointsAdjustment::PENDING => 'warning',
                    default => 'danger',
                }),
                TextColumn::make('approver.username')->label('Decided by')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->options([PointsAdjustment::PENDING => 'Pending', PointsAdjustment::POSTED => 'Posted', PointsAdjustment::REJECTED => 'Rejected']),
            ])
            ->recordActions([
                Action::make('approve')->color('success')->requiresConfirmation()
                    ->visible(fn (PointsAdjustment $record): bool => $record->status === PointsAdjustment::PENDING && $record->requested_by !== Staff::user()?->id)
                    ->action(fn (PointsAdjustment $record) => self::run(fn (): PointsAdjustment => app(PointsAdjustments::class)->approve($record, Staff::current()))),
                Action::make('reject')->color('danger')
                    ->visible(fn (PointsAdjustment $record): bool => $record->status === PointsAdjustment::PENDING)
                    ->schema([Textarea::make('why')->label('Reason')->required()->maxLength(500)])
                    ->action(fn (PointsAdjustment $record, array $data) => self::run(fn (): PointsAdjustment => app(PointsAdjustments::class)->reject($record, Staff::current(), is_string($data['why'] ?? null) ? $data['why'] : ''))),
            ]);
    }

    /**
     * @param  callable(): PointsAdjustment  $action
     */
    private static function run(callable $action): void
    {
        try {
            $adj = $action();
            Notification::make()->success()->title(match ($adj->status) {
                PointsAdjustment::POSTED => 'Points posted',
                PointsAdjustment::PENDING => 'Waiting for a second approver',
                default => 'Adjustment rejected',
            })->send();
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return ['index' => ListPointsAdjustments::route('/')];
    }
}
