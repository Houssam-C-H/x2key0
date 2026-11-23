<?php

declare(strict_types=1);

namespace App\Domains\Offers\Enums;

use App\Domains\Catalog\Enums\CategoryTypeCode;
use App\Domains\Storage\Enums\StorageItemType;

/**
 * How the seller delivers (spec 8.2) — the "Attribute" / delivery-method filter on product pages.
 */
enum DeliveryMethod: string
{
    case DigitalKey = 'digital_key';
    case AccountTransfer = 'account_transfer';
    case ManualTopUp = 'manual_top_up';
    case SendGift = 'send_gift';
    case Service = 'service';
    case Code = 'code';
    case File = 'file';

    public function labelKey(): string
    {
        return 'offer.delivery.'.$this->value;
    }

    /**
     * Storage item types an auto-delivered offer of this method can draw from; empty = manual only.
     *
     * @return list<StorageItemType>
     */
    public function storageTypes(): array
    {
        return match ($this) {
            self::DigitalKey => [StorageItemType::Key, StorageItemType::Code, StorageItemType::GiftCard],
            self::Code => [StorageItemType::Code, StorageItemType::Key, StorageItemType::GiftCard, StorageItemType::Text],
            self::AccountTransfer => [StorageItemType::Account],
            self::File => [StorageItemType::Text],
            self::ManualTopUp, self::SendGift, self::Service => [],
        };
    }

    public function supportsAutoDelivery(): bool
    {
        return $this->storageTypes() !== [];
    }

    /**
     * Methods allowed when a game×category does not restrict them (`allowed_delivery_methods` empty).
     *
     * @return list<self>
     */
    public static function defaultsFor(CategoryTypeCode $type): array
    {
        return match ($type) {
            CategoryTypeCode::Currency, CategoryTypeCode::Items => [self::SendGift, self::ManualTopUp, self::Code],
            CategoryTypeCode::TopUp, CategoryTypeCode::MobileRecharges => [self::ManualTopUp, self::Code],
            CategoryTypeCode::Boosting => [self::Service],
            CategoryTypeCode::Accounts => [self::AccountTransfer],
            CategoryTypeCode::VideoGames, CategoryTypeCode::GameDlc, CategoryTypeCode::SoftwareApps => [self::DigitalKey, self::AccountTransfer, self::SendGift],
            CategoryTypeCode::GiftCards, CategoryTypeCode::EgiftCards, CategoryTypeCode::RetailGiftCards,
            CategoryTypeCode::GamingEcards, CategoryTypeCode::CouponsSavings => [self::Code, self::DigitalKey],
            CategoryTypeCode::Subscriptions => [self::Code, self::AccountTransfer, self::ManualTopUp],
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $m): string => $m->value, self::cases());
    }
}
