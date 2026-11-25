<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domains\Identity\Actions\DeleteAccount;
use App\Domains\Identity\Exceptions\AccountDeletionException;
use App\Domains\Identity\Models\AccountDeletionRequest;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserSecurity;
use App\Shared\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › Delete Account (spec 1.7 / 6.8): irreversible notice, agreement, two verification factors, then the
 * request is scheduled (cool-off) or blocked with the reasons; a scheduled deletion can be cancelled.
 */
final class DeleteAccountController
{
    public function show(Request $request, DeleteAccount $delete, Settings $settings): Response
    {
        $user = self::user($request);
        $open = $delete->open($user);
        $last = AccountDeletionRequest::query()->where('user_id', $user->id)->latest('id')->first();

        return Inertia::render('Settings/DeleteAccount', [
            'scheduled' => $open === null ? null : ['at' => $open->scheduled_for?->toIso8601String()],
            'blocked' => $last?->status === AccountDeletionRequest::BLOCKED ? array_map(static fn (string $k): string => (string) __($k), $last->blockers ?? []) : null,
            'blockersNow' => array_map(static fn (string $k): string => (string) __($k), $delete->blockers($user)),
            'factors' => [
                'email' => true,
                'privacy' => is_string(UserSecurity::query()->where('user_id', $user->id)->value('privacy_password_hash')),
                'authenticator' => $user->hasTwoFactorEnabled(),
            ],
            'coolOffDays' => $settings->int('account_deletion_cooloff_days'),
        ]);
    }

    public function code(Request $request, DeleteAccount $delete): RedirectResponse
    {
        $delete->sendEmailCode(self::user($request));

        return back()->with('success', __('account.delete.code_sent'));
    }

    public function store(Request $request, DeleteAccount $delete): RedirectResponse
    {
        $request->validate([
            'agree' => ['accepted'],
            'reason' => ['nullable', 'string', 'max:500'],
            'email' => ['nullable', 'string', 'max:10'],
            'privacy' => ['nullable', 'string', 'max:200'],
            'authenticator' => ['nullable', 'string', 'max:10'],
        ]);
        $proofs = [];
        foreach (DeleteAccount::FACTORS as $factor) {
            if ($request->filled($factor)) {
                $proofs[$factor] = $request->string($factor)->toString();
            }
        }

        try {
            $result = $delete->request(self::user($request), $proofs, $request->filled('reason') ? $request->string('reason')->toString() : null);
        } catch (AccountDeletionException $e) {
            return back()->withErrors(['factors' => $e->getMessage()]);
        }

        return back()->with($result->status === AccountDeletionRequest::SCHEDULED ? 'success' : 'error', __($result->status === AccountDeletionRequest::SCHEDULED ? 'account.delete.scheduled' : 'account.delete.blocked'));
    }

    public function cancel(Request $request, DeleteAccount $delete): RedirectResponse
    {
        $delete->cancel(self::user($request));

        return back()->with('success', __('account.delete.cancelled'));
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
