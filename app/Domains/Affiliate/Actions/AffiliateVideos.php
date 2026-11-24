<?php

declare(strict_types=1);

namespace App\Domains\Affiliate\Actions;

use App\Domains\Affiliate\Exceptions\AffiliateException;
use App\Domains\Affiliate\Models\Affiliate;
use App\Domains\Affiliate\Models\AffiliateVideoRecord;
use App\Domains\Announcements\Support\Notifier;
use App\Domains\Identity\Models\User;
use App\Domains\Ledger\Actions\PostJournal;
use App\Domains\Ledger\Data\AccountRef;
use App\Domains\Ledger\Data\JournalDraft;
use App\Domains\Ledger\Enums\AccountCode;
use App\Domains\Ledger\Enums\JournalType;
use Illuminate\Support\Facades\DB;

/**
 * Promotional video records (spec Part 15): the affiliate submits a public video URL; staff approve it with a reward
 * (AFFILIATE_VIDEO_REWARD: EXP_AFFILIATE +, AFFILIATE_AVAILABLE −, available at once) or reject it with a note.
 */
final class AffiliateVideos
{
    private const array HOSTS = [
        'youtube' => ['youtube.com', 'youtu.be'],
        'tiktok' => ['tiktok.com'],
        'twitch' => ['twitch.tv'],
    ];

    public function __construct(private readonly PostJournal $post) {}

    public function submit(Affiliate $affiliate, string $url, int $views): AffiliateVideoRecord
    {
        $url = trim($url);
        $platform = self::platform($url) ?? throw AffiliateException::because('affiliate.error.video_url');
        if (AffiliateVideoRecord::query()->where('affiliate_id', $affiliate->id)->where('url', $url)->exists()) {
            throw AffiliateException::because('affiliate.error.video_duplicate');
        }

        $record = new AffiliateVideoRecord;
        $record->forceFill([
            'affiliate_id' => $affiliate->id,
            'platform' => $platform,
            'url' => $url,
            'views_reported' => max(0, min($views, 1_000_000_000)),
            'status' => 'submitted',
        ])->save();

        return $record->refresh();
    }

    public function approve(AffiliateVideoRecord $record, int $reward, User $staff, ?string $note = null): AffiliateVideoRecord
    {
        if ($reward < 0) {
            throw AffiliateException::because('affiliate.error.reward');
        }

        return DB::transaction(function () use ($record, $reward, $staff, $note): AffiliateVideoRecord {
            /** @var AffiliateVideoRecord $locked */
            $locked = AffiliateVideoRecord::query()->with('affiliate')->lockForUpdate()->findOrFail($record->id);
            if ($locked->status !== 'submitted') {
                throw AffiliateException::because('affiliate.error.reviewed');
            }

            if ($reward > 0) {
                $this->post->handle(new JournalDraft(
                    type: JournalType::AffiliateVideoReward,
                    idempotencyKey: "AFFILIATE_VIDEO_REWARD:video:{$locked->id}",
                    lines: [
                        [AccountRef::platform(AccountCode::ExpAffiliate, 'USD'), $reward],
                        [AccountRef::user(AccountCode::AffiliateAvailable, $locked->affiliate->user_id, 'USD'), -$reward],
                    ],
                    sourceType: 'affiliate_video',
                    sourceId: $locked->id,
                    description: 'Promotional video reward',
                    createdByType: 'staff',
                    createdById: $staff->id,
                ));
            }

            $locked->forceFill(['status' => 'approved', 'reward_amount' => $reward, 'review_note' => $note, 'reviewed_by' => $staff->id, 'reviewed_at' => now()])->save();
            Notifier::notify($locked->affiliate->user_id, 'affiliate_video_approved', 'announcement', ['amount' => number_format($reward / 100, 2).' USD', 'href' => '/affiliate/videos']);

            return $locked->refresh();
        });
    }

    public function reject(AffiliateVideoRecord $record, string $note, User $staff): AffiliateVideoRecord
    {
        if (trim($note) === '') {
            throw AffiliateException::because('affiliate.error.reason');
        }
        if ($record->status !== 'submitted') {
            throw AffiliateException::because('affiliate.error.reviewed');
        }

        $record->forceFill(['status' => 'rejected', 'review_note' => mb_substr(trim($note), 0, 500), 'reviewed_by' => $staff->id, 'reviewed_at' => now()])->save();
        $record->loadMissing('affiliate');
        Notifier::notify($record->affiliate->user_id, 'affiliate_video_rejected', 'announcement', ['href' => '/affiliate/videos']);

        return $record->refresh();
    }

    public static function platform(string $url): ?string
    {
        if (! str_starts_with($url, 'https://') || mb_strlen($url) > 500) {
            return null;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host)) {
            return null;
        }
        $host = strtolower((string) preg_replace('/^(www\.|m\.)/', '', $host));

        foreach (self::HOSTS as $platform => $hosts) {
            foreach ($hosts as $h) {
                if ($host === $h || str_ends_with($host, '.'.$h)) {
                    return $platform;
                }
            }
        }

        return 'other';
    }
}
