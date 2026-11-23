<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Games\RelationManagers;

use App\Domains\Catalog\Models\GameCategory;
use App\Filament\Resources\Catalog\GameCategories\GameCategoryResource;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

/**
 * The game's category pages, with a link to the full game×category editor (schemas, notice).
 */
final class CategoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'categories';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type.name.en')->label('Category type'),
                TextColumn::make('label_override.en')->label('Label')->placeholder('—'),
                TextColumn::make('slug'),
                TextColumn::make('product_count_cached')->label('Products'),
                ToggleColumn::make('is_enabled')->label('Enabled'),
            ])
            ->headerActions([
                Action::make('add')
                    ->label('Add category')
                    ->url(fn (): string => GameCategoryResource::getUrl('create', ['game' => $this->getOwnerRecord()->getKey()])),
            ])
            ->recordActions([
                Action::make('edit')
                    ->url(fn (GameCategory $record): string => GameCategoryResource::getUrl('edit', ['record' => $record])),
            ]);
    }
}
