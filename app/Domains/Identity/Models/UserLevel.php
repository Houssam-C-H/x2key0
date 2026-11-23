<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Buyer level by lifetime completed spend (spec 3.3). Recomputed nightly from M16.
 *
 * @property int $id
 * @property string $code
 * @property array<string, string> $name
 * @property int $min_spend_amount
 * @property int $points_multiplier_bps
 * @property int $sort
 */
final class UserLevel extends Model
{
    protected $fillable = ['code', 'name', 'min_spend_amount', 'points_multiplier_bps', 'icon_path', 'sort'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['name' => 'array', 'min_spend_amount' => 'integer', 'points_multiplier_bps' => 'integer', 'sort' => 'integer'];
    }

    public function label(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $this->name[$locale] ?? $this->name['en'] ?? $this->code;
    }

    /** Entry level assigned to new users. */
    public static function entry(): ?self
    {
        return self::query()->orderBy('min_spend_amount')->orderBy('sort')->first();
    }
}
