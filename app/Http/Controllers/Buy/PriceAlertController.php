<?php

declare(strict_types=1);

namespace App\Http\Controllers\Buy;

use App\Domains\Engagement\Actions\PriceAlerts;
use App\Domains\Engagement\Models\PriceAlert;
use App\Domains\Identity\Models\User;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Price alerts: set from product / listing pages, list and remove in the buyer dashboard. */
final class PriceAlertController
{
    public function index(Request $request): Response
    {
        $user = self::user($request);
        $locale = $user->locale ?: app()->getLocale();
        $alerts = PriceAlert::query()->where('user_id', $user->id)->with(['product.gameCategory', 'gameCategory.game', 'gameCategory.type'])->latest()->get();

        return Inertia::render('Buy/PriceAlerts', [
            'alerts' => $alerts->map(static fn (PriceAlert $a): array => [
                'id' => $a->id,
                'title' => PriceAlerts::title($a, $locale),
                'href' => PriceAlerts::href($a, $locale),
                'target' => $a->target_amount,
                'lowest' => PriceAlerts::lowest($a),
                'notifiedAt' => $a->notified_at?->toIso8601String(),
            ])->values()->all(),
            'max' => PriceAlerts::MAX_PER_USER,
        ]);
    }

    public function store(Request $request, PriceAlerts $alerts): RedirectResponse
    {
        $request->validate([
            'type' => ['required', 'in:product,category'],
            'id' => ['required', 'string', 'max:40'],
            'target' => ['required', 'string', 'regex:/^\d{1,7}(\.\d{1,2})?$/'],
        ]);

        [$whole, $fraction] = array_pad(explode('.', $request->string('target')->toString()), 2, '0');
        $cents = (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        try {
            $alerts->set(self::user($request), $request->string('type')->toString(), $request->string('id')->toString(), $cents);
        } catch (DomainException $e) {
            return back()->withErrors(['target' => $e->getMessage()]);
        }

        return back()->with('success', __('price_alert.saved'));
    }

    public function destroy(Request $request, PriceAlerts $alerts, int $alert): RedirectResponse
    {
        $alerts->remove(self::user($request), $alert);

        return back()->with('success', __('price_alert.removed'));
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
