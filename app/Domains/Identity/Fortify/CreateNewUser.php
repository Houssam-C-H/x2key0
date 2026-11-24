<?php

declare(strict_types=1);

namespace App\Domains\Identity\Fortify;

use App\Domains\Identity\Actions\RegisterUser;
use App\Domains\Identity\Models\User;
use App\Http\Middleware\ResolveLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

final class CreateNewUser implements CreatesNewUsers
{
    /** Usernames that could impersonate staff or collide with routes. */
    private const array RESERVED = [
        'admin', 'administrator', 'root', 'support', 'staff', 'system', 'moderator', 'x2key',
        'help', 'security', 'billing', 'finance', 'dashboard', 'seller', 'store', 'api', 'null',
    ];

    public function __construct(
        private readonly RegisterUser $register,
        private readonly Request $request,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): User
    {
        /** @var array{username: string, email: string, password: string, referral_code?: string|null} $data */
        $data = Validator::make($input, [
            'username' => [
                'required', 'string', 'min:3', 'max:32', 'regex:/^[a-zA-Z0-9_]+$/',
                Rule::notIn(self::RESERVED),
                Rule::unique(User::class, 'username'),
            ],
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique(User::class, 'email')],
            'password' => PasswordRules::rules(),
            'terms' => ['accepted'],
            'referral_code' => ['nullable', 'string', 'max:64', 'alpha_dash'],
        ])->validate();

        // A typed referral code; the Registered listener applies it when no link was clicked (M15, TrackReferrals).
        if (! empty($data['referral_code']) && $this->request->hasSession()) {
            $this->request->session()->put('referral_code', $data['referral_code']);
        }

        return $this->register->handle([
            'username' => $data['username'],
            'email' => $data['email'],
            'password' => $data['password'],
            'locale' => app()->getLocale(),
            'display_currency' => ResolveLocale::displayCurrency($this->request),
        ]);
    }
}
