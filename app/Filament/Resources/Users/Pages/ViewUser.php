<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Domains\Admin\Actions\StaffNotes;
use App\Domains\Admin\Support\UserOverview;
use App\Domains\Identity\Actions\ModerateUser;
use App\Domains\Identity\Actions\RevokeSessions;
use App\Domains\Identity\Enums\UserStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserSession;
use App\Filament\Resources\Audit\AuditLogResource;
use App\Filament\Resources\Users\UserResource;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * User 360° (docs/ADMIN_PLAN.md item 3). Status, 2FA reset, sign-outs and notes go through their domain actions
 * (ModerateUser, RevokeSessions, StaffNotes), each audited and gated by UserPolicy::update.
 */
final class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected string $view = 'filament.users.overview';

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        return app(UserOverview::class)->for($this->user());
    }

    public function auditUrl(): ?string
    {
        return AuditLogResource::canAccess() ? AuditLogResource::urlFor($this->user()) : null;
    }

    public function revokeSession(int $sessionId): void
    {
        $user = $this->user();
        abort_unless(Gate::allows('update', $user), 403);

        $session = UserSession::query()->where('user_id', $user->id)->findOrFail($sessionId);
        app(RevokeSessions::class)->one($user, $session);
        Notification::make()->success()->title('Session signed out')->send();
    }

    /**
     * @return array<int, Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('note')->label('Add note')->icon('heroicon-o-pencil-square')->color('gray')
                ->schema([Textarea::make('body')->label('Internal note (never shown to the user)')->required()->maxLength(5000)->rows(4)])
                ->action(fn (array $data) => $this->run(fn () => app(StaffNotes::class)->add($this->staff(), 'user', $this->user()->id, is_string($data['body'] ?? null) ? $data['body'] : ''), 'Note added')),
            ActionGroup::make([
                $this->status('suspend', UserStatus::Suspended, 'warning', [UserStatus::Active]),
                $this->status('ban', UserStatus::Banned, 'danger', [UserStatus::Active, UserStatus::Suspended]),
                $this->status('reinstate', UserStatus::Active, 'success', [UserStatus::Suspended, UserStatus::Banned]),
                Action::make('reset_2fa')->label('Reset two-step verification')->color('warning')
                    ->visible(fn (): bool => $this->user()->two_factor_confirmed_at !== null && Gate::allows('update', $this->user()))
                    ->schema([Textarea::make('reason')->label('Reason (how was the identity checked?)')->required()->maxLength(500)])
                    ->action(fn (array $data) => $this->run(fn () => app(ModerateUser::class)->resetTwoFactor($this->user(), $this->staff(), self::reason($data)), 'Two-step verification reset')),
                Action::make('sign_out')->label('Sign out everywhere')->requiresConfirmation()
                    ->visible(fn (): bool => Gate::allows('update', $this->user()))
                    ->action(fn () => $this->run(fn () => app(ModerateUser::class)->signOut($this->user(), $this->staff(), 'staff'), 'All sessions signed out')),
            ])->label('Account')->button()->color('gray'),
            EditAction::make()->label('Roles'),
        ];
    }

    /**
     * @param  list<UserStatus>  $from
     */
    private function status(string $name, UserStatus $to, string $color, array $from): Action
    {
        return Action::make($name)->label(ucfirst($name))->color($color)
            ->visible(fn (): bool => in_array($this->user()->status, $from, true) && Gate::allows('update', $this->user()))
            ->schema([Textarea::make('reason')->label('Reason (kept in the audit log)')->required()->maxLength(500)])
            ->action(fn (array $data) => $this->run(fn () => app(ModerateUser::class)->setStatus($this->user(), $to, $this->staff(), self::reason($data)), 'Account '.$to->value));
    }

    /**
     * @param  array<mixed>  $data
     */
    private static function reason(array $data): string
    {
        return is_string($data['reason'] ?? null) ? $data['reason'] : '';
    }

    private function run(callable $action, string $message): void
    {
        try {
            $action();
            $this->user()->refresh();
            Notification::make()->success()->title($message)->send();
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    private function user(): User
    {
        $record = $this->getRecord();

        return $record instanceof User ? $record : throw new LogicException('ViewUser expects a User record.');
    }

    private function staff(): User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : throw new LogicException('Staff only.');
    }
}
