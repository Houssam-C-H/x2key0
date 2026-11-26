<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sellers\SellerProfiles\Pages;

use App\Domains\Admin\Actions\StaffNotes;
use App\Domains\Admin\Support\SellerOverview;
use App\Domains\Identity\Actions\RevokeApiToken;
use App\Domains\Sellers\Models\SellerProfile;
use App\Filament\Resources\Audit\AuditLogResource;
use App\Filament\Resources\Sellers\SellerProfiles\SellerProfileResource;
use App\Filament\Support\Staff;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use LogicException;

/**
 * Store 360° (docs/ADMIN_PLAN.md item 10). Status and Storage switches reuse the list's actions (state machine,
 * SetStorageAccess); API tokens are revoked with RevokeApiToken; notes with StaffNotes. All audited.
 */
final class ViewSellerProfile extends ViewRecord
{
    protected static string $resource = SellerProfileResource::class;

    protected string $view = 'filament.sellers.overview';

    public string $revokeReason = '';

    public function getTitle(): string
    {
        return $this->profile()->store_name;
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        return app(SellerOverview::class)->for($this->profile());
    }

    public function auditUrl(): ?string
    {
        return AuditLogResource::canAccess() ? AuditLogResource::urlFor($this->profile()) : null;
    }

    public function revokeToken(int $tokenId): void
    {
        try {
            app(RevokeApiToken::class)->byStaff(Staff::current(), $this->profile()->user, $tokenId, $this->revokeReason);
            $this->revokeReason = '';
            Notification::make()->success()->title('Token revoked')->send();
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    /**
     * @return array<int, Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        $listActions = collect(SellerProfileResource::statusActions())
            ->map(fn (Action $a): Action => $a->after(fn () => $this->profile()->refresh()))
            ->all();

        return [
            Action::make('note')->label('Add note')->icon('heroicon-o-pencil-square')->color('gray')
                ->schema([Textarea::make('body')->label('Internal note (never shown to the seller)')->required()->maxLength(5000)->rows(4)])
                ->action(function (array $data): void {
                    try {
                        app(StaffNotes::class)->add(Staff::current(), 'seller', $this->profile()->id, is_string($data['body'] ?? null) ? $data['body'] : '');
                        Notification::make()->success()->title('Note added')->send();
                    } catch (DomainException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
            ActionGroup::make($listActions)->label('Store')->button()->color('gray'),
            EditAction::make()->label('Level, manager, payouts'),
        ];
    }

    private function profile(): SellerProfile
    {
        $record = $this->getRecord();

        return $record instanceof SellerProfile ? $record : throw new LogicException('ViewSellerProfile expects a SellerProfile.');
    }
}
