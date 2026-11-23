<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sellers\SellerProfiles\RelationManagers;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Enums\ContactChannel;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Merchant Services Contacts shown to the seller (spec 1.8). */
final class ContactsRelationManager extends RelationManager
{
    protected static string $relationship = 'contacts';

    protected static ?string $title = 'Merchant contacts';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('channel')->options(ContactChannel::options())->required(),
            TextInput::make('value')->required()->maxLength(191),
            TextInput::make('hours')->maxLength(64)->placeholder('Mon–Fri 09:00–18:00 UTC'),
            Select::make('staff_user_id')
                ->label('Staff member')
                ->options(fn (): array => User::role([StaffRole::MerchantManager->value, StaffRole::Support->value])->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->nullable(),
            Toggle::make('is_primary')->label('Primary'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('channel')->badge()->formatStateUsing(fn (ContactChannel $state): string => ucfirst($state->value)),
                TextColumn::make('value'),
                TextColumn::make('hours')->placeholder('—'),
                TextColumn::make('staff.name')->label('Staff')->placeholder('—'),
                IconColumn::make('is_primary')->label('Primary')->boolean(),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
