<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\CategoryTypes\RelationManagers;

use App\Domains\Catalog\Models\CategoryType;
use App\Domains\Catalog\Models\ServiceType;
use App\Filament\Support\Translatable;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

/**
 * Service types of a category (ADR-032), e.g. "Rank boost" under Boosting. Switching one off stops new listings
 * under it and takes its offers off the storefront until it is switched back on. A service with offers cannot be
 * deleted (switch it off instead).
 */
final class ServiceTypesRelationManager extends RelationManager
{
    protected static string $relationship = 'serviceTypes';

    protected static ?string $title = 'Service types';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->required()
                ->maxLength(48)
                ->regex('/^[a-z0-9_]+$/')
                ->helperText('Lowercase letters, digits and underscores, e.g. rank_boost. Used in links (?service=…).')
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('category_type_id', $this->ownerId())),
            TextInput::make('sort')->numeric()->default(0)->required(),
            Toggle::make('is_enabled')->label('Enabled (sellers can list, buyers can see)')->default(true),
            Translatable::input('name', 'Name'),
            Translatable::input('description', 'Description shown to sellers', required: false, multiline: true),
        ]);
    }

    private function ownerId(): int
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof CategoryType ? $owner->id : 0;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('name.en')->label('Name'),
                TextColumn::make('code')->color('gray'),
                ToggleColumn::make('is_enabled')->label('Enabled'),
                TextColumn::make('offers_count')->counts('offers')->label('Offers'),
            ])
            ->headerActions([CreateAction::make()->label('Add service type')])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->visible(fn (ServiceType $record): bool => ! $record->offers()->exists()),
            ])
            ->emptyStateHeading('No service types')
            ->emptyStateDescription('Sellers list in this category without choosing a service type.');
    }
}
