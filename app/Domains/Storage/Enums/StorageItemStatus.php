<?php

declare(strict_types=1);

namespace App\Domains\Storage\Enums;

/**
 * Storage item status (spec 5.5). `available` items feed auto delivery; `reserved`/`assigned` belong to orders
 * (M6/M8) and are never shown to the seller again; `recycled` = Recycle Station (restore or purge).
 */
enum StorageItemStatus: string
{
    case Available = 'available';
    case Reserved = 'reserved';
    case Assigned = 'assigned';
    case Revoked = 'revoked';
    case Invalid = 'invalid';
    case Recycled = 'recycled';

    /** The seller may still read the secret (spec 8.1: assigned items are never re-read). */
    public function revealable(): bool
    {
        return in_array($this, [self::Available, self::Invalid, self::Recycled], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
