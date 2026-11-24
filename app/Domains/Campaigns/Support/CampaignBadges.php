<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Support;

use App\Domains\Campaigns\Models\Campaign;
use App\Domains\Campaigns\Models\CampaignParticipation;

/**
 * Offer badges from running campaigns (spec 14.2 "approved offers get badges"). Approved participations of
 * running campaigns are few, so they are loaded once per request and mapped offer id → campaign type.
 */
final class CampaignBadges
{
    /** @var array<int, string>|null */
    private ?array $map = null;

    public function for(int $offerId): ?string
    {
        return $this->map()[$offerId] ?? null;
    }

    /** Forget the per-request map (tests, long-running workers). */
    public function flush(): void
    {
        $this->map = null;
    }

    /**
     * @return array<int, string>
     */
    private function map(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        $this->map = [];
        $running = [];
        foreach (Campaign::query()->running()->get(['id', 'type']) as $campaign) {
            $running[$campaign->id] = $campaign->type;
        }
        if ($running === []) {
            return $this->map;
        }

        $rows = CampaignParticipation::query()->where('status', CampaignParticipation::APPROVED)->whereIn('campaign_id', array_keys($running))->get(['campaign_id', 'offer_ids']);
        foreach ($rows as $row) {
            foreach ($row->offer_ids as $offerId) {
                $this->map[$offerId] ??= $running[$row->campaign_id];
            }
        }

        return $this->map;
    }
}
