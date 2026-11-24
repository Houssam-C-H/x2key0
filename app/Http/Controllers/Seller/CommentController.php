<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Domains\Reviews\Actions\OrderComments;
use App\Domains\Reviews\Exceptions\ReviewException;
use App\Domains\Reviews\Models\OrderComment;
use App\Domains\Reviews\Support\ReviewPresenter;
use App\Domains\Reviews\Support\SellerRatings;
use App\Http\Controllers\Seller\Concerns\ResolvesSeller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sell Order Comments (spec 1.8): feedback score table, % positive (90 days and lifetime), transaction level with
 * Delivery Speed · Service Quality · Item Description, filter by rating, reply once; buyer names masked.
 */
final class CommentController
{
    use ResolvesSeller;

    public function index(Request $request): Response
    {
        $seller = $this->seller($request);
        $locale = app()->getLocale();
        $rating = in_array($r = $request->query('rating'), OrderComment::RATINGS, true) ? $r : null;

        $comments = OrderComment::query()->where('seller_profile_id', $seller->id)->visible()->with(['order.item', 'buyer:id,name'])
            ->when($rating !== null, fn ($q) => $q->where('rating', $rating))->orderByDesc('created_at')->limit(200)->get();
        $stats = $seller->stats()->first();

        return Inertia::render('Seller/Comments', [
            'rating' => $rating,
            'comments' => array_values($comments->map(static fn (OrderComment $c): array => ReviewPresenter::row($c, 'seller', $locale))->all()),
            'scores' => SellerRatings::scoreTable('seller', $seller->id),
            'subScores' => SellerRatings::subScores($seller->id),
            'positive90' => $stats?->positive_rate_90d_bps,
            'maxLength' => OrderComments::MAX_LENGTH,
        ]);
    }

    public function reply(Request $request, OrderComments $comments, int $comment): RedirectResponse
    {
        $request->validate(['reply' => ['required', 'string', 'max:'.OrderComments::MAX_LENGTH]]);
        $seller = $this->seller($request);
        $row = OrderComment::query()->whereKey($comment)->where('seller_profile_id', $seller->id)->firstOrFail();

        try {
            $comments->reply($row, $seller, $request->string('reply')->toString());
        } catch (ReviewException $e) {
            return back()->withErrors(['reply' => $e->getMessage()]);
        }

        return back()->with('success', __('review.replied'));
    }
}
