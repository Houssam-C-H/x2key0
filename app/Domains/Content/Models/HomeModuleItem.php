<?php

declare(strict_types=1);

namespace App\Domains\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An item staff pinned into a home module (spec 5.17): shown first, in `sort` order, before the automatic picks.
 *
 * @property int $id
 * @property int $home_module_id
 * @property string $item_type
 * @property int $item_id
 * @property bool $pinned
 * @property int $sort
 * @property-read HomeModule $module
 */
final class HomeModuleItem extends Model
{
    protected $fillable = ['home_module_id', 'item_type', 'item_id', 'pinned', 'sort'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['pinned' => 'boolean', 'sort' => 'integer', 'item_id' => 'integer'];
    }

    /** @return BelongsTo<HomeModule, $this> */
    public function module(): BelongsTo
    {
        return $this->belongsTo(HomeModule::class, 'home_module_id');
    }
}
