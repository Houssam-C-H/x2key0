<?php

declare(strict_types=1);

namespace App\Domains\Storage\Enums;

/** What a storage group holds (spec 5.5). Accounts are structured; the others are one secret per line. */
enum StorageItemType: string
{
    case Key = 'key';
    case Code = 'code';
    case Account = 'account';
    case GiftCard = 'gift_card';
    case Text = 'text';

    public function labelKey(): string
    {
        return 'storage.type.'.$this->value;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
