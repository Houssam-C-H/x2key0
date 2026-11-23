<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sellers\SellerProfiles\RelationManagers;

use App\Domains\Sellers\Enums\SellerStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Read-only status history written by SellerProfileStateMachine. */
final class StatusChangesRelationManager extends RelationManager
{
    protected static string $relationship = 'statusChanges';

    protected static ?string $title = 'Status history';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->dateTime(),
                TextColumn::make('from_status')->label('From')->formatStateUsing(fn (SellerStatus $state): string => ucfirst($state->value)),
                TextColumn::make('to_status')->label('To')->badge()->formatStateUsing(fn (SellerStatus $state): string => ucfirst($state->value)),
                TextColumn::make('actor.username')->label('By')->placeholder('system'),
                TextColumn::make('reason')->wrap()->placeholder('—'),
            ]);
    }
}
