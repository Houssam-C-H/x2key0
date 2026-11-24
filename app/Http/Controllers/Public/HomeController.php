<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domains\Reviews\Support\ReviewPresenter;
use Inertia\Inertia;
use Inertia\Response;

final class HomeController
{
    /**
     * Shell only in M1. The 12 CMS-driven home modules land in M17 (spec 1.3).
     */
    public function __invoke(): Response
    {
        // Community ratings (spec 1.3 #7, Part 13); the other modules arrive with the home CMS (M17).
        return Inertia::render('Public/Home', ['communityRatings' => ReviewPresenter::community(4, app()->getLocale())]);
    }
}
