<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Domains\Identity\Models\User;
use App\Shared\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Seller Center › API tokens (ADR-038): create (password re-confirmed; `storage:write` needs two-step
 * verification), shown once in full, list with last use, revoke. Every change is audited.
 */
final class ApiTokenController
{
    public const array ABILITIES = ['offers:read', 'offers:write', 'orders:read', 'storage:write'];

    public const int MAX_TOKENS = 10;

    public function index(Request $request): Response
    {
        $user = self::user($request);

        return Inertia::render('Seller/ApiTokens', [
            'tokens' => $user->tokens()->latest()->get()->map(static fn (PersonalAccessToken $t): array => [
                'id' => $t->id,
                'name' => $t->name,
                'abilities' => $t->abilities,
                'lastUsedAt' => $t->last_used_at?->toIso8601String(),
                'createdAt' => $t->created_at?->toIso8601String(),
            ])->values()->all(),
            'abilities' => self::ABILITIES,
            'twoFactor' => $user->hasTwoFactorEnabled(),
            'baseUrl' => rtrim(config()->string('app.url'), '/').'/api/v1/seller',
            'max' => self::MAX_TOKENS,
        ]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $user = self::user($request);
        $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in(self::ABILITIES)],
        ]);
        $chosen = $request->input('abilities');
        $abilities = array_values(array_filter(self::ABILITIES, static fn (string $a): bool => is_array($chosen) && in_array($a, $chosen, true)));

        if (in_array('storage:write', $abilities, true) && ! $user->hasTwoFactorEnabled()) {
            return back()->withErrors(['abilities' => __('api.error.storage_2fa')]);
        }
        if ($user->tokens()->count() >= self::MAX_TOKENS) {
            return back()->withErrors(['name' => __('api.error.limit', ['max' => self::MAX_TOKENS])]);
        }

        $token = $user->createToken($request->string('name')->toString(), $abilities);
        $audit->log('api_token.created', $token->accessToken, after: ['name' => $token->accessToken->name, 'abilities' => $abilities]);

        return back()->with('success', __('api.created'))->with('newToken', $token->plainTextToken);
    }

    public function destroy(Request $request, AuditLogger $audit, int $token): RedirectResponse
    {
        $model = self::user($request)->tokens()->whereKey($token)->first();
        if ($model !== null) {
            $audit->log('api_token.revoked', $model, after: ['name' => $model->name]);
            $model->delete();
        }

        return back()->with('success', __('api.revoked'));
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
