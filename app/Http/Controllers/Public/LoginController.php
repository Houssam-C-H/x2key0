<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use Inertia\Inertia;
use Inertia\Response;

/**
 * Placeholder login screen so the `login` route exists for the auth middleware.
 * Real authentication (register, 2FA, social) is Milestone 2.
 */
final class LoginController
{
    public function __invoke(): Response
    {
        return Inertia::render('Public/Login');
    }
}
