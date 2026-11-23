<?php

declare(strict_types=1);

namespace App\Filament\Resources\Certifications;

use App\Domains\Certification\Enums\CertificationStatus;
use App\Domains\Certification\Models\Certification;
use App\Filament\Resources\Certifications\Pages\ListCertifications;
use App\Filament\Resources\Certifications\Tables\CertificationsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Certification review queue (spec Part 18). Approve/reject only; data is read-only.
 */
final class CertificationResource extends Resource
{
    protected static ?string $model = Certification::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Users';

    protected static ?string $navigationLabel = 'Certification queue';

    public static function getNavigationBadge(): ?string
    {
        $pending = Certification::query()->where('status', CertificationStatus::Pending)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function table(Table $table): Table
    {
        return CertificationsTable::configure($table);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListCertifications::route('/'),
        ];
    }
}
