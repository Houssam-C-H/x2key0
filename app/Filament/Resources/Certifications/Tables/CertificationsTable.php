<?php

declare(strict_types=1);

namespace App\Filament\Resources\Certifications\Tables;

use App\Domains\Certification\Actions\ReviewCertification;
use App\Domains\Certification\Enums\CertificationStatus;
use App\Domains\Certification\Enums\CertificationType;
use App\Domains\Certification\Models\Certification;
use App\Domains\Identity\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

final class CertificationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'asc')
            ->columns([
                TextColumn::make('id'),
                TextColumn::make('user.username')->label('User')->searchable(),
                TextColumn::make('type')->badge()->formatStateUsing(fn (CertificationType $state): string => ucfirst($state->value)),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (CertificationStatus $state): string => ucfirst($state->value))
                    ->color(fn (CertificationStatus $state): string => match ($state) {
                        CertificationStatus::Verified => 'success',
                        CertificationStatus::Pending => 'warning',
                        CertificationStatus::Rejected => 'danger',
                        CertificationStatus::None => 'gray',
                    }),
                TextColumn::make('provider'),
                TextColumn::make('provider_ref')->label('Provider ref')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Submitted')->since()->sortable(),
                TextColumn::make('rejected_reason')->wrap()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(CertificationStatus::cases())->mapWithKeys(fn (CertificationStatus $s): array => [$s->value => ucfirst($s->value)])->all())
                    ->default(CertificationStatus::Pending->value),
                SelectFilter::make('type')
                    ->options(collect(CertificationType::cases())->mapWithKeys(fn (CertificationType $t): array => [$t->value => ucfirst($t->value)])->all()),
            ])
            ->recordActions([
                Action::make('approve')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Certification $record): bool => $record->status === CertificationStatus::Pending && Gate::allows('review', $record))
                    ->action(function (Certification $record): void {
                        app(ReviewCertification::class)->approve($record, self::reviewer());
                        Notification::make()->title('Certification approved')->success()->send();
                    }),
                Action::make('reject')
                    ->color('danger')
                    ->visible(fn (Certification $record): bool => $record->status === CertificationStatus::Pending && Gate::allows('review', $record))
                    ->schema([
                        Textarea::make('reason')->label('Reason shown to the user')->required()->maxLength(500),
                    ])
                    ->action(function (Certification $record, array $data): void {
                        $reason = $data['reason'] ?? '';
                        app(ReviewCertification::class)->reject($record, self::reviewer(), is_string($reason) ? $reason : '');
                        Notification::make()->title('Certification rejected')->warning()->send();
                    }),
            ]);
    }

    private static function reviewer(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}
