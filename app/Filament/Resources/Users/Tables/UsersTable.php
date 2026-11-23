<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Tables;

use App\Domains\Identity\Enums\UserStatus;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

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
                EditAction::make(),
            ]);
    }
}
