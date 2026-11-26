<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Tables;

use App\Domains\Identity\Actions\ModerateUser;
use App\Domains\Identity\Enums\UserStatus;
use App\Domains\Identity\Models\User;
use App\Filament\Resources\Sellers\SellerProfiles\SellerProfileResource;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

final class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('username')->searchable()->copyable(),
                TextColumn::make('email')->searchable()->toggleable(),
                IconColumn::make('email_verified_at')->label('Email ✓')->boolean(),
                IconColumn::make('two_factor_confirmed_at')->label('2FA')->boolean(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (UserStatus $state): string => ucfirst($state->value))
                    ->color(fn (UserStatus $state): string => match ($state) {
                        UserStatus::Active => 'success',
                        UserStatus::Suspended, UserStatus::Deleting => 'warning',
                        UserStatus::Banned, UserStatus::Deleted => 'danger',
                    }),
                TextColumn::make('roles.name')->label('Staff roles')->badge(),
                TextColumn::make('last_login_at')->dateTime()->sortable()->toggleable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(UserStatus::cases())->mapWithKeys(fn (UserStatus $s): array => [$s->value => ucfirst($s->value)])->all()),
                SelectFilter::make('roles')->relationship('roles', 'name')->label('Staff role'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->label('Roles'),
                ActionGroup::make([
                    self::status('suspend', UserStatus::Suspended, 'warning', fn (User $u): bool => $u->status === UserStatus::Active),
                    self::status('ban', UserStatus::Banned, 'danger', fn (User $u): bool => in_array($u->status, [UserStatus::Active, UserStatus::Suspended], true)),
                    self::status('reactivate', UserStatus::Active, 'success', fn (User $u): bool => in_array($u->status, [UserStatus::Suspended, UserStatus::Banned], true)),
                    Action::make('reset_2fa')->label('Reset 2FA')->color('warning')
                        ->visible(fn (User $record): bool => $record->two_factor_confirmed_at !== null && Gate::allows('update', $record))
                        ->schema([Textarea::make('reason')->label('Reason (identity checked how?)')->required()->maxLength(500)])
                        ->action(fn (User $record, array $data) => self::run(fn () => app(ModerateUser::class)->resetTwoFactor($record, SellerProfileResource::actor(), SellerProfileResource::reason($data)), 'Two-factor authentication reset')),
                    Action::make('sign_out')->label('Sign out everywhere')->requiresConfirmation()
                        ->visible(fn (User $record): bool => Gate::allows('update', $record))
                        ->action(fn (User $record) => self::run(fn () => app(ModerateUser::class)->signOut($record, SellerProfileResource::actor(), 'staff'), 'All sessions revoked')),
                ]),
            ]);
    }

    /**
     * @param  callable(User): bool  $visible
     */
    private static function status(string $name, UserStatus $to, string $color, callable $visible): Action
    {
        return Action::make($name)->label(ucfirst($name))->color($color)
            ->visible(fn (User $record): bool => $visible($record) && Gate::allows('update', $record))
            ->schema([Textarea::make('reason')->label('Reason (kept in the audit log)')->required()->maxLength(500)])
            ->action(fn (User $record, array $data) => self::run(fn () => app(ModerateUser::class)->setStatus($record, $to, SellerProfileResource::actor(), SellerProfileResource::reason($data)), 'Account '.$to->value));
    }

    private static function run(callable $action, string $message): void
    {
        try {
            $action();
            Notification::make()->success()->title($message)->send();
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }
}
