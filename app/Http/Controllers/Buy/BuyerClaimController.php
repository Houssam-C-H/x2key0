<?php

declare(strict_types=1);

namespace App\Http\Controllers\Buy;

use App\Domains\Disputes\Support\OpenCases;
use App\Domains\Identity\Models\User;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Models\Order;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Get help with an order" (buyer protection claim flow): the buyer's paid orders, newest first, each with
 * "Open a claim" leading to the dispute form (which applies the full rules), or a link to the open case.
 */
final class BuyerClaimController
{
    private const array CLAIMABLE = [OrderStatus::Paid, OrderStatus::Delivering, OrderStatus::Delivered, OrderStatus::Completed];

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $orders = Order::query()
            ->where('buyer_id', $user->id)
            ->whereNotNull('paid_at')
            ->with(['item', 'seller'])
            ->latest('paid_at')
            ->limit(30)
            ->get();

        return Inertia::render('Buy/Claim', [
            'orders' => $orders->map(static function (Order $o): array {
                $open = OpenCases::blocks($o->id);

                return [
                    'publicId' => $o->public_id,
                    'number' => $o->number,
                    'title' => is_string($t = $o->item->snapshot['title'] ?? null) ? $t : $o->number,
                    'seller' => $o->seller->store_name,
                    'status' => $o->status->value,
                    'paidAt' => $o->paid_at?->toIso8601String(),
                    'total' => $o->total_amount,
                    'openCase' => $open,
                    'claimable' => ! $open && in_array($o->status, self::CLAIMABLE, true),
                ];
            })->values()->all(),
        ]);
    }
}
