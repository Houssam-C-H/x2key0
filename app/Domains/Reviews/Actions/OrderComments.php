<?php

declare(strict_types=1);

namespace App\Domains\Reviews\Actions;

use App\Domains\Announcements\Support\Notifier;
use App\Domains\Identity\Models\User;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Points\Actions\PostPointsJournal;
use App\Domains\Points\Enums\PointsAccountCode;
use App\Domains\Points\Enums\PointsJournalType;
use App\Domains\Points\Models\PointsRule;
use App\Domains\Reviews\Exceptions\ReviewException;
use App\Domains\Reviews\Models\OrderComment;
use App\Domains\Reviews\Support\SellerRatings;
use App\Domains\Sellers\Models\SellerProfile;
use App\Shared\Support\ContactInfo;
use App\Shared\Support\Settings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Purchase order comments (spec Part 13):
 *
 * - leave — the buyer of a completed order, within `comment_window_days` (30) of completion, once per order:
 *   positive / neutral / negative, optional Delivery Speed · Service Quality · Item Description scores (1–5) and
 *   text (no contact details — reviews are public). Earns the `comment` points rule once;
 * - edit — the buyer, inside the window and until the seller has replied;
 * - reply — the seller, once;
 * - moderate — staff hide (with a reason) or show a comment, and feature it in Community ratings.
 *
 * The seller's ratings are refreshed after every change.
 */
final class OrderComments
{
    public const int MAX_LENGTH = 1000;

    public function __construct(
        private readonly Settings $settings,
        private readonly PostPointsJournal $points,
        private readonly SellerRatings $ratings,
    ) {}

    /**
     * @param  array{rating: string, score_delivery?: int|null, score_service?: int|null, score_description?: int|null, body?: string|null}  $data
     */
    public function leave(Order $order, User $buyer, array $data): OrderComment
    {
        if ($order->buyer_id !== $buyer->id) {
            throw ReviewException::because('review.error.not_buyer');
        }
        if (! $this->canComment($order)) {
            throw ReviewException::because('review.error.window', ['days' => $this->windowDays()]);
        }
        $clean = self::clean($data);

        try {
            $comment = DB::transaction(function () use ($order, $buyer, $clean): OrderComment {
                $order->loadMissing('item');
                $comment = new OrderComment;
                $comment->forceFill([
                    ...$clean,
                    'order_id' => $order->id,
                    'buyer_id' => $buyer->id,
                    'seller_profile_id' => $order->seller_profile_id,
                    'product_id' => $order->item->product_id,
                    'offer_id' => $order->item->offer_id,
                    'status' => 'visible',
                ])->save();

                $this->award($comment, $buyer);

                return $comment;
            });
        } catch (UniqueConstraintViolationException) {
            throw ReviewException::because('review.error.already');
        }

        $this->ratings->refresh($order->seller_profile_id);
        $sellerUserId = SellerProfile::query()->whereKey($order->seller_profile_id)->value('user_id');
        if (is_numeric($sellerUserId)) {
            Notifier::notify((int) $sellerUserId, 'comment_received', 'order', ['number' => $order->number, 'rating' => $comment->rating, 'href' => '/seller/comments']);
        }

        return $comment->refresh();
    }

    /**
     * @param  array{rating: string, score_delivery?: int|null, score_service?: int|null, score_description?: int|null, body?: string|null}  $data
     */
    public function edit(OrderComment $comment, User $buyer, array $data): OrderComment
    {
        if ($comment->buyer_id !== $buyer->id) {
            throw ReviewException::because('review.error.not_buyer');
        }
        $comment->loadMissing('order');
        if ($comment->replied_at !== null || ! $this->inWindow($comment->order)) {
            throw ReviewException::because('review.error.locked');
        }

        $comment->forceFill([...self::clean($data), 'edited_at' => now()])->save();
        $this->ratings->refresh($comment->seller_profile_id);

        return $comment->refresh();
    }

    public function reply(OrderComment $comment, SellerProfile $seller, string $reply): OrderComment
    {
        $reply = trim($reply);
        if ($comment->seller_profile_id !== $seller->id) {
            throw ReviewException::because('review.error.not_seller');
        }
        if ($comment->replied_at !== null) {
            throw ReviewException::because('review.error.replied');
        }
        if ($reply === '' || mb_strlen($reply) > self::MAX_LENGTH || ContactInfo::found($reply)) {
            throw ReviewException::because('review.error.text', ['max' => self::MAX_LENGTH]);
        }

        $comment->forceFill(['seller_reply' => $reply, 'replied_at' => now()])->save();
        $comment->loadMissing('order');
        Notifier::notify($comment->buyer_id, 'comment_replied', 'order', ['number' => $comment->order->number, 'href' => '/buy/comments?tab=commented']);

        return $comment->refresh();
    }

    public function hide(OrderComment $comment, User $staff, string $reason): OrderComment
    {
        if (trim($reason) === '') {
            throw ReviewException::because('review.error.reason');
        }

        $comment->forceFill(['status' => 'hidden', 'hidden_reason' => mb_substr(trim($reason), 0, 500), 'hidden_by' => $staff->id, 'featured_in_community' => false])->save();
        $this->ratings->refresh($comment->seller_profile_id);

        return $comment->refresh();
    }

    public function show(OrderComment $comment): OrderComment
    {
        $comment->forceFill(['status' => 'visible', 'hidden_reason' => null, 'hidden_by' => null])->save();
        $this->ratings->refresh($comment->seller_profile_id);

        return $comment->refresh();
    }

    public function feature(OrderComment $comment, bool $featured): OrderComment
    {
        if ($featured && ($comment->status !== 'visible' || $comment->rating !== 'positive')) {
            throw ReviewException::because('review.error.feature');
        }

        $comment->forceFill(['featured_in_community' => $featured])->save();

        return $comment->refresh();
    }

    /** Completed, inside the window, no comment yet. */
    public function canComment(Order $order): bool
    {
        return $this->inWindow($order) && ! OrderComment::query()->where('order_id', $order->id)->exists();
    }

    public function inWindow(Order $order): bool
    {
        return $order->status === OrderStatus::Completed && $order->completed_at !== null
            && $order->completed_at->copy()->addDays($this->windowDays())->isFuture();
    }

    public function windowDays(): int
    {
        return $this->settings->int('comment_window_days');
    }

    /**
     * @param  array{rating: string, score_delivery?: int|null, score_service?: int|null, score_description?: int|null, body?: string|null}  $data
     * @return array{rating: string, score_delivery: int|null, score_service: int|null, score_description: int|null, body: string|null}
     */
    private static function clean(array $data): array
    {
        if (! in_array($data['rating'], OrderComment::RATINGS, true)) {
            throw ReviewException::because('review.error.rating');
        }

        $scores = [];
        foreach (['score_delivery', 'score_service', 'score_description'] as $key) {
            $value = $data[$key] ?? null;
            if ($value !== null && ($value < 1 || $value > 5)) {
                throw ReviewException::because('review.error.score');
            }
            $scores[$key] = $value;
        }

        $body = trim((string) ($data['body'] ?? ''));
        if (mb_strlen($body) > self::MAX_LENGTH || ($body !== '' && ContactInfo::found($body))) {
            throw ReviewException::because('review.error.text', ['max' => self::MAX_LENGTH]);
        }

        return ['rating' => $data['rating'], ...$scores, 'body' => $body === '' ? null : $body];
    }

    /** `comment` points rule (spec 5.13), once per comment. */
    private function award(OrderComment $comment, User $buyer): void
    {
        $rule = PointsRule::query()->where('code', 'comment')->where('is_enabled', true)->first();
        $points = $rule->fixed ?? 0;
        if ($points <= 0) {
            return;
        }

        $this->points->handle(PointsJournalType::EarnTask, "comment:order:{$comment->order_id}", [
            [PointsAccountCode::User, $buyer->id, $points],
            [PointsAccountCode::Issued, 0, -$points],
        ], 'order_comment', $comment->id, 'Order comment');
    }
}
