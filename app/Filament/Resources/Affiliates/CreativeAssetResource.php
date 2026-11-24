<?php

declare(strict_types=1);

namespace App\Filament\Resources\Affiliates;

use App\Domains\Affiliate\Models\CreativeAsset;
use App\Filament\Resources\Affiliates\Pages\CreateCreativeAsset;
use App\Filament\Resources\Affiliates\Pages\EditCreativeAsset;
use App\Filament\Resources\Affiliates\Pages\ListCreativeAssets;
use App\Filament\Support\Translatable;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Creative Assets (spec Part 15): banners, logos, videos and copy affiliates download, by theme, size and language. */
final class CreativeAssetResource extends Resource
{
    protected static ?string $model = CreativeAsset::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static string|UnitEnum|null $navigationGroup = 'Affiliates';

    protected static ?string $slug = 'creative-assets';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Translatable::input('title', 'Title'),
            Select::make('type')->options(array_combine(CreativeAsset::TYPES, ['Banner', 'Logo', 'Video', 'Copy']))->required()->default('banner'),
            TextInput::make('theme')->default('universal')->required()->maxLength(40),
            TextInput::make('size')->placeholder('728x90')->maxLength(20),
            Select::make('locale')->label('Language (empty = all)')->options(['en' => 'English', 'fr' => 'Français', 'ar' => 'العربية']),
            FileUpload::make('file_path')->label('File')->disk('public')->directory('creative-assets')->maxSize(30720)
                ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml', 'video/mp4']),
            Textarea::make('copy_text')->label('Text (copy assets)')->maxLength(2000),
            Toggle::make('is_active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('title')->state(fn (CreativeAsset $record): string => $record->translate('title', 'en'))->limit(40),
                TextColumn::make('type')->badge(),
                TextColumn::make('theme'),
                TextColumn::make('size')->placeholder('—'),
                TextColumn::make('locale')->placeholder('all'),
                TextColumn::make('is_active')->label('Active')->badge()->formatStateUsing(fn (bool $state): string => $state ? 'yes' : 'no'),
            ])
            ->recordActions([EditAction::make()]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListCreativeAssets::route('/'),
            'create' => CreateCreativeAsset::route('/create'),
            'edit' => EditCreativeAsset::route('/{record}/edit'),
        ];
    }
}
