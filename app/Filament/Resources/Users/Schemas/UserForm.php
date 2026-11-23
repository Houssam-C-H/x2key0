<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Schemas;

use App\Domains\Identity\Enums\UserStatus;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Staff can change account status and staff roles only. Profile data belongs to the user.
 * Every change is audited (User is Auditable; role changes are logged in EditUser).
 */
final class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Account')
                ->columns(2)
                ->schema([
                    TextInput::make('username')->disabled()->dehydrated(false),
                    TextInput::make('email')->disabled()->dehydrated(false),
                    TextInput::make('public_id')->label('Public ID')->disabled()->dehydrated(false),
                    TextInput::make('name')->label('Display name')->disabled()->dehydrated(false),
                ]),
            Section::make('Access')
                ->columns(2)
                ->schema([
                    Select::make('status')
                        ->options(collect(UserStatus::cases())->mapWithKeys(fn (UserStatus $s): array => [$s->value => ucfirst($s->value)])->all())
                        ->required()
                        ->helperText('Suspended and banned users are signed out on their next request.'),
                    CheckboxList::make('roles')
                        ->relationship('roles', 'name')
                        ->label('Staff roles')
                        ->helperText('Staff must turn on 2FA before the admin panel opens for them.'),
                ]),
        ]);
    }
}
