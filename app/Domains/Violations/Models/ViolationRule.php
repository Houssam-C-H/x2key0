<?php

declare(strict_types=1);

namespace App\Domains\Violations\Models;

use App\Domains\Violations\Enums\Penalty;
use App\Shared\Audit\Auditable;
use App\Shared\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;

/**
 * A rule of the Violation Center (spec 5.17 / 17.2). `auto` rules are raised by the platform (dispute lost,
 * seller cancel, late delivery, chargeback lost); the others are raised by staff.
 *
 * @property int $id
 * @property string $code
 * @property array<string, string> $name
 * @property int $points
 * @property Penalty $penalty
 * @property bool $auto
 * @property bool $is_enabled
 */
final class ViolationRule extends Model
{
    use Auditable;
    use HasTranslations;

    protected $fillable = ['code', 'name', 'points', 'penalty', 'auto', 'is_enabled'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => 'array',
            'points' => 'integer',
            'penalty' => Penalty::class,
            'auto' => 'boolean',
            'is_enabled' => 'boolean',
        ];
    }
}
