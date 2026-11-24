<?php

declare(strict_types=1);

namespace App\Http\Controllers\Buy;

use App\Domains\Identity\Models\User;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Reviews\Actions\OrderComments;
use App\Domains\Reviews\Exceptions\ReviewException;
use App\Domains\Reviews\Models\OrderComment;
use App\Domains\Reviews\Support\ReviewPresenter;
use App\Domains\Reviews\Support\SellerRatings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Purchase Order Comment (spec 1.7 / Part 13): To comment (completed orders inside the window) and Commented
 * (with the seller's reply), the buyer's feedback score table, leave and edit a comment.
 */
final class CommentController
{
    public function index(Request $request, OrderComments $comments): Response
    {
        $user = self::user($request);
        $locale = app()->getLocale();
        $tab = $request->query('tab') === 'commented' ? 'commented' : 'pending';
        $rating = in_array($r = $request->query('rating'), OrderComment::RATINGS, true) ? $r : null;

        $pending = Order::query()->where('buyer_id', $user->id)->where('status', OrderStatus::Completed)
            ->where('completed_at', '>=', now()->subDays($comments->windowDays()))
            ->whereDoesntHave('comment')->with(['item', 'seller'])->orderByDesc('completed_at')->limit(100)->get();

        $given = OrderComment::query()->where('buyer_id', $user->id)->with(['order.item', 'seller'])
            ->when($rating !== null, fn ($q) => $q->where('rating', $rating))->orderByDesc('created_at')->limit(200)->get();

        return Inertia::render('Buy/Comments', [
            'tab' => $tab,
            'rating' => $rating,
            'pending' => array_values($pending->map(static fn (Order $o): array => [
                'orderId' => $o->public_id,
                'number' => $o->number,
                'item' => is_string($title = $o->item->snapshot['title'] ?? null) ? $title : '',
                'store' => $o->seller->store_name,
                'completedAt' => $o->completed_at?->toIso8601String(),
                'deadline' => $o->completed_at?->copy()->addDays($comments->windowDays())->toIso8601String(),
            ])->all()),
            'comments' => array_values($given->map(fn (OrderComment $c): array => [
                ...ReviewPresenter::row($c, 'buyer', $locale),
                'editable' => $c->replied_at === null && $comments->inWindow($c->order),
            ])->all()),
            'scores' => SellerRatings::scoreTable('buyer', $user->id),
            'open' => is_string($o = $request->query('order')) ? $o : null,
            'maxLength' => OrderComments::MAX_LENGTH,
        ]);
    }

    public function store(Request $request, OrderComments $comments, string $publicId): RedirectResponse
    {
        $order = Order::query()->where('public_id', $publicId)->where('buyer_id', self::user($request)->id)->firstOrFail();

        try {
            $comments->leave($order, self::user($request), self::data($request));
        } catch (ReviewException $e) {
            return back()->withErrors(['comment' => $e->getMessage()]);
        }

        return redirect('/buy/comments?tab=commented')->with('success', __('review.thanks'));
    }

    public function update(Request $request, OrderComments $comments, int $comment): RedirectResponse
    {
        $row = OrderComment::query()->whereKey($comment)->where('buyer_id', self::user($request)->id)->firstOrFail();

        try {
            $comments->edit($row, self::user($request), self::data($request));
        } catch (ReviewException $e) {
            return back()->withErrors(['comment' => $e->getMessage()]);
        }

        return back()->with('success', __('review.updated'));
    }

    /**
     * @return array{rating: string, score_delivery: int|null, score_service: int|null, score_description: int|null, body: string|null}
     */
    private static function data(Request $request): array
    {
        $request->validate([
            'rating' => ['required', 'string', 'in:positive,neutral,negative'],
            'score_delivery' => ['nullable', 'integer', 'between:1,5'],
            'score_service' => ['nullable', 'integer', 'between:1,5'],
            'score_description' => ['nullable', 'integer', 'between:1,5'],
            'body' => ['nullable', 'string', 'max:'.OrderComments::MAX_LENGTH],
        ]);
        $score = static fn (string $k): ?int => $request->filled($k) ? $request->integer($k) : null;

        return [
            'rating' => $request->string('rating')->toString(),
            'score_delivery' => $score('score_delivery'),
            'score_service' => $score('score_service'),
            'score_description' => $score('score_description'),
            'body' => $request->filled('body') ? $request->string('body')->toString() : null,
        ];
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
