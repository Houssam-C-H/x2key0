<?php

declare(strict_types=1);

namespace App\Domains\Affiliate\Actions;

use App\Domains\Affiliate\Exceptions\AffiliateException;
use App\Domains\Affiliate\Models\Affiliate;
use App\Domains\Affiliate\Models\AffiliateLink;
use App\Domains\Identity\Models\User;
use App\Domains\Ledger\Actions\PostJournal;
use App\Domains\Ledger\Data\AccountRef;
use App\Domains\Ledger\Data\JournalDraft;
use App\Domains\Ledger\Enums\AccountCode;
use App\Domains\Ledger\Enums\JournalType;
use App\Domains\Ledger\Exceptions\BalanceOverdrawn;
use App\Shared\Support\Settings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The affiliate's own actions (spec Part 15 "Manage Affiliate Program"): enrol (an invited user's inviter becomes
 * the parent, so level 2 works), choose a custom code, build promotional links to any X2KEY page, and transfer the
 * available balance to the Credits Balance (AFFILIATE_TO_CREDITS, minimum `affiliate_transfer_min`).
 */
final class AffiliateAccounts
{
    public const int MAX_LINKS = 50;

    public function __construct(
        private readonly PostJournal $post,
        private readonly Settings $settings,
    ) {}

    public function enrol(User $user): Affiliate
    {
        $existing = Affiliate::query()->where('user_id', $user->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $parent = $user->referred_by_affiliate_id === null ? null : Affiliate::query()->whereKey($user->referred_by_affiliate_id)->where('status', Affiliate::ACTIVE)->first();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $affiliate = new Affiliate;
                $affiliate->forceFill([
                    'user_id' => $user->id,
                    'code' => strtoupper(Str::random(8)),
                    'parent_affiliate_id' => $parent?->id,
                    'status' => Affiliate::ACTIVE,
                ])->save();

                return $affiliate->refresh();
            } catch (UniqueConstraintViolationException) {
                if (Affiliate::query()->where('user_id', $user->id)->exists()) {
                    return Affiliate::query()->where('user_id', $user->id)->firstOrFail();
                }
            }
        }

        throw AffiliateException::because('affiliate.error.code_taken');
    }

    public function setCode(Affiliate $affiliate, string $code): Affiliate
    {
        $code = strtoupper(trim($code));
        if (preg_match('/^[A-Z0-9]{4,16}$/', $code) !== 1) {
            throw AffiliateException::because('affiliate.error.code_format');
        }
        if (Affiliate::query()->where('code', $code)->whereKeyNot($affiliate->id)->exists()) {
            throw AffiliateException::because('affiliate.error.code_taken');
        }

        $affiliate->forceFill(['code' => $code])->save();

        return $affiliate->refresh();
    }

    /** A promotional link to an X2KEY page: only paths on this site (never another domain). */
    public function createLink(Affiliate $affiliate, string $target): AffiliateLink
    {
        $path = self::sitePath($target);
        if ($path === null) {
            throw AffiliateException::because('affiliate.error.link_target');
        }
        if (AffiliateLink::query()->where('affiliate_id', $affiliate->id)->count() >= self::MAX_LINKS) {
            throw AffiliateException::because('affiliate.error.too_many_links', ['max' => self::MAX_LINKS]);
        }

        $link = new AffiliateLink;
        $link->forceFill(['affiliate_id' => $affiliate->id, 'slug' => strtolower(Str::random(10)), 'target_path' => $path])->save();

        return $link;
    }

    public function transferToCredits(Affiliate $affiliate, int $amount, string $idempotencyKey, string $currency = 'USD'): void
    {
        $min = $this->settings->int('affiliate_transfer_min');
        if ($amount < $min) {
            throw AffiliateException::because('affiliate.error.transfer_min', ['amount' => number_format($min / 100, 2)]);
        }

        $available = AccountRef::user(AccountCode::AffiliateAvailable, $affiliate->user_id, $currency);

        try {
            DB::transaction(fn () => $this->post->handle(new JournalDraft(
                type: JournalType::AffiliateToCredits,
                idempotencyKey: 'AFFILIATE_TO_CREDITS:user:'.$affiliate->user_id.':'.mb_substr($idempotencyKey, 0, 40),
                lines: [
                    [$available, $amount],
                    [AccountRef::user(AccountCode::BuyerCredits, $affiliate->user_id, $currency), -$amount],
                ],
                sourceType: 'affiliate',
                sourceId: $affiliate->id,
                description: 'Affiliate balance to Credits Balance',
                noOverdraw: [$available],
                createdByType: 'user',
                createdById: $affiliate->user_id,
            )));
        } catch (BalanceOverdrawn) {
            throw AffiliateException::because('affiliate.error.transfer_balance');
        }
    }

    /**
     * `/en/game/x`, `https://this-site/en/x?y=1` → the path (with query); anything off-site → null.
     */
    public static function sitePath(string $target): ?string
    {
        $target = trim($target);
        if ($target === '' || mb_strlen($target) > 500) {
            return null;
        }

        $parts = parse_url($target);
        if ($parts === false) {
            return null;
        }
        if (isset($parts['host'])) {
            $own = parse_url(config()->string('app.url'), PHP_URL_HOST);
            if (! is_string($own) || strcasecmp($parts['host'], $own) !== 0) {
                return null;
            }
        }

        $path = $parts['path'] ?? '/';
        if (! str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
            return null;
        }

        return $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
