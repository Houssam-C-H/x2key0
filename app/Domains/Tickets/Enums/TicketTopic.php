<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Enums;

/**
 * "Please answer some questions…" topics of a new ticket (spec 1.9 / 5.10), with their follow-up form fields
 * and default priority.
 */
enum TicketTopic: string
{
    case Registration = 'registration';
    case Account = 'account';
    case BuyingPaying = 'buying_paying';
    case Selling = 'selling';
    case Refund = 'refund';
    case ReportAfterSales = 'report_after_sales';
    case Other = 'other';
    case CatalogRequest = 'catalog_request';

    /**
     * Follow-up fields: key => [required, max length]. Catalog requests follow spec 1.9 ("new game/category request").
     *
     * @return array<string, array{0: bool, 1: int}>
     */
    public function fields(): array
    {
        return match ($this) {
            self::CatalogRequest => [
                'game_name' => [true, 100],
                'website' => [false, 191],
                'platform' => [true, 60],
                'area' => [true, 60],
                'server' => [true, 60],
                'product_type' => [true, 60],
                'keywords' => [true, 191],
                'delivery_method' => [false, 60],
                'monthly_demand' => [false, 30],
                'remark' => [false, 500],
            ],
            default => [],
        };
    }

    public function defaultPriority(bool $withOrder): TicketPriority
    {
        if ($withOrder && in_array($this, [self::Refund, self::ReportAfterSales, self::BuyingPaying], true)) {
            return TicketPriority::High;
        }

        return $this === self::CatalogRequest ? TicketPriority::Low : TicketPriority::Normal;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
