<?php

declare(strict_types=1);

namespace App\Domains\Affiliate\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A promotional link to any X2KEY page (`/r/{code}/{slug}`), with its own counters.
 *
 * @property int $id
 * @property int $affiliate_id
 * @property string $slug
 * @property string $target_path
 * @property int $clicks
 * @property int $signups
 * @property Carbon $created_at
 */
final class AffiliateLink extends Model
{
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['clicks' => 'integer', 'signups' => 'integer'];
    }
}
