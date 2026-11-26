<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Seller;

use App\Domains\Offers\Actions\BulkUpdateOffers;
use App\Domains\Offers\Actions\SaveOffer;
use App\Domains\Offers\Exceptions\OfferException;
use App\Domains\Offers\Models\Offer;
use App\Domains\Offers\Support\OfferInputBuilder;
use App\Http\Controllers\Api\Seller\Concerns\ApiSeller;
use Brick\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Seller API › offers (ADR-038): list, read, create, update (blank fields keep values), deactivate / relist. */
final class OffersController
{
    use ApiSeller;

    private const array FIELDS = ['game_category_id', 'product_id', 'service_type_id', 'title', 'unit_price', 'min_qty', 'max_qty', 'manual_stock_qty', 'delivery_time_minutes', 'delivery_method'];

    public function index(Request $request): JsonResponse
    {
        $page = Offer::query()->where('seller_profile_id', self::profile($request)->id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->orderBy('id')->paginate(min(100, max(1, $request->integer('per_page', 50))));

        return response()->json([
            'data' => array_map(self::row(...), $page->items()),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, string $publicId): JsonResponse
    {
        return response()->json(['data' => self::row($this->own($request, $publicId))]);
    }

    public function store(Request $request, SaveOffer $save): JsonResponse
    {
        try {
            $offer = $save->create(self::profile($request), self::user($request), OfferInputBuilder::from(self::fields($request), null));
        } catch (OfferException $e) {
            return response()->json(['message' => $e->getMessage(), 'field' => $e->field], 422);
        }

        return response()->json(['data' => self::row($offer->refresh())], 201);
    }

    public function update(Request $request, SaveOffer $save, string $publicId): JsonResponse
    {
        $offer = $this->own($request, $publicId);
        try {
            $offer = $save->update($offer, self::user($request), OfferInputBuilder::from(self::fields($request), $offer));
        } catch (OfferException $e) {
            return response()->json(['message' => $e->getMessage(), 'field' => $e->field], 422);
        }

        return response()->json(['data' => self::row($offer->refresh())]);
    }

    public function operate(Request $request, BulkUpdateOffers $bulk, string $publicId, string $operation): JsonResponse
    {
        $offer = $this->own($request, $publicId);
        try {
            $result = $bulk->apply(self::profile($request), self::user($request), $operation, [$offer->id], $operation === 'relist' ? 30 : 0);
        } catch (OfferException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['done' => $result['done'], 'failed' => $result['failed'], 'data' => self::row($offer->refresh())], $result['done'] > 0 ? 200 : 422);
    }

    private function own(Request $request, string $publicId): Offer
    {
        $offer = Offer::query()->where('seller_profile_id', self::profile($request)->id)->where('public_id', $publicId)->first();
        abort_if($offer === null, 404);

        return $offer;
    }

    /**
     * Body fields as strings ('' = not given), like a CSV row.
     *
     * @return array<string, string>
     */
    private static function fields(Request $request): array
    {
        $out = [];
        foreach (self::FIELDS as $f) {
            $v = $request->input($f);
            $out[$f] = is_scalar($v) ? trim((string) $v) : '';
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(Offer $o): array
    {
        return [
            'id' => $o->public_id,
            'status' => $o->status->value,
            'game_category_id' => $o->game_category_id,
            'product_id' => $o->product_id,
            'service_type_id' => $o->service_type_id,
            'title' => $o->title,
            'unit_price' => (string) Money::ofMinor($o->unit_price_amount, 'USD')->getAmount(),
            'currency' => 'USD',
            'min_qty' => $o->min_qty,
            'max_qty' => $o->max_qty,
            'manual_stock_qty' => $o->manual_stock_qty,
            'stock' => $o->fulfillment->value === 'auto' ? null : $o->manual_stock_qty,
            'fulfillment' => $o->fulfillment->value,
            'delivery_method' => $o->delivery_method->value,
            'delivery_time_minutes' => $o->delivery_time_minutes,
            'expires_at' => $o->expires_at?->toIso8601String(),
            'url' => $o->path('en'),
        ];
    }
}
