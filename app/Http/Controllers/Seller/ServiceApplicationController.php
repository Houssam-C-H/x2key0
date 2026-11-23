<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Sellers\Actions\ApplyForService;
use App\Domains\Sellers\Enums\ServiceApplicationStatus;
use App\Domains\Sellers\Exceptions\SellerException;
use App\Domains\Sellers\Models\SellerServiceApplication;
use App\Http\Controllers\Seller\Concerns\ResolvesSeller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Store Management › Service Application Status (spec 1.8 / 6.9): one application per game×category;
 * a passed application unlocks listing offers there (M5).
 */
final class ServiceApplicationController
{
    use ResolvesSeller;

    public function index(Request $request): Response
    {
        $profile = $this->seller($request);
        $locale = app()->getLocale();

        $applications = $profile->serviceApplications()
            ->with(['gameCategory.game', 'gameCategory.type'])
            ->latest('updated_at')
            ->get();

        /** @var array<int, string> $blocking game_category_id => status, for pending/passed */
        $blocking = $applications
            ->filter(static fn (SellerServiceApplication $a): bool => $a->status !== ServiceApplicationStatus::Refused)
            ->mapWithKeys(static fn (SellerServiceApplication $a): array => [$a->game_category_id => $a->status->value])
            ->all();

        $games = [];
        $categories = GameCategory::query()->visible()->with(['game', 'type'])->get()
            ->sortBy(static fn (GameCategory $gc): string => mb_strtolower($gc->game->translate('name', $locale)).'|'.str_pad((string) $gc->type->sort, 3, '0', STR_PAD_LEFT));

        foreach ($categories as $gameCategory) {
            $gameId = $gameCategory->game_id;
            $games[$gameId] ??= ['id' => $gameId, 'name' => $gameCategory->game->translate('name', $locale), 'categories' => []];
            $games[$gameId]['categories'][] = [
                'id' => $gameCategory->id,
                'label' => $gameCategory->label($locale),
                'applied' => isset($blocking[$gameCategory->id]),
            ];
        }

        return Inertia::render('Seller/Services', [
            'applications' => $applications->map(static fn (SellerServiceApplication $a): array => [
                'id' => $a->id,
                'gameCategoryId' => $a->game_category_id,
                'title' => $a->gameCategory->title($locale),
                'href' => $a->gameCategory->path($locale),
                'status' => $a->status->value,
                'rejectReason' => $a->reject_reason,
                'createdAt' => $a->created_at->toIso8601String(),
                'auditedAt' => $a->audited_at?->toIso8601String(),
            ])->values()->all(),
            'games' => array_values($games),
            'autoApprove' => $profile->level?->auto_approve === true,
        ]);
    }

    public function store(Request $request, ApplyForService $action): RedirectResponse
    {
        $request->validate(['game_category_id' => ['required', 'integer']]);

        try {
            $application = $action->handle($this->seller($request), $request->integer('game_category_id'));
        } catch (SellerException $e) {
            return back()->withErrors(['game_category_id' => $e->getMessage()]);
        }

        return back()->with('success', __($application->status === ServiceApplicationStatus::Passed
            ? 'seller.services.passed_now'
            : 'seller.services.submitted'));
    }
}
