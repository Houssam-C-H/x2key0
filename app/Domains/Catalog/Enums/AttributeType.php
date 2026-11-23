<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Enums;

/**
 * Field types of a game×category attribute schema (spec 1.4.1 filter sidebar).
 */
enum AttributeType: string
{
    case Select = 'select';            // one value, checkbox facet with counts
    case MultiSelect = 'multiselect';  // several values, checkbox facet with counts
    case Number = 'number';            // range facet (from–to)
    case Boolean = 'boolean';          // YES / NO facet
    case Text = 'text';                // shown on the product, not filterable

    public function isFacet(): bool
    {
        return in_array($this, [self::Select, self::MultiSelect, self::Boolean], true);
    }
}
