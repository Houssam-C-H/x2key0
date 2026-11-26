<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Seller;

use App\Domains\Orders\Models\Order;
use App\Http\Controllers\Api\Seller\Concerns\ApiSeller;
use Brick\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Seller API › sold orders (ADR-038): read-only, no buyer contact data and never any delivered secrets. */
final class OrdersController
{
    use ApiSeller;

    public function index(Request $request): JsonResponse
    {
        $page = Order::query()->where('seller_profile_id', self::profile($request)->id)->whereNotNull('paid_at')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->with('item')->latest('paid_at')->paginate(min(100, max(1, $request->integer('per_page', 50))));

        return response()->json([
            'data' => array_map(self::row(...), $page->items()),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, string $publicId): JsonResponse
    {
        $order = Order::query()->where('seller_profile_id', self::profile($request)->id)->where('public_id', $publicId)->with('item')->first();
        abort_if($order === null, 404);

        return response()->json(['data' => self::row($order)]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(Order $o): array
    {
        $usd = static fn (int $c): string => (string) Money::ofMinor($c, 'USD')->getAmount();

        return [
            'id' => $o->public_id,
            'number' => $o->number,
            'status' => $o->status->value,
            'offer_id' => $o->item->offer_id,
            'title' => is_string($t = $o->item->snapshot['title'] ?? null) ? $t : null,
            'quantity' => $o->item->quantity,
            'delivered_qty' => $o->item->delivered_qty,
            'unit_price' => $usd($o->item->unit_price_amount),
            'seller_net' => $usd($o->seller_net_amount),
            'currency' => 'USD',
            'paid_at' => $o->paid_at?->toIso8601String(),
        ];
    }
}
