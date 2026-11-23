<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use Inertia\Inertia;
use Inertia\Response;

final class HomeController
{
    /**
     * Shell only in M1. The 12 CMS-driven home modules land in M17 (spec 1.3).
     */
    public function __invoke(): Response
    {
        return Inertia::render('Public/Home');
    }
}
