<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Enums;

enum TicketPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';

    /** Settings key of the first-response target (hours). */
    public function slaKey(): string
    {
        return "ticket_sla_hours_{$this->value}";
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High'];
    }
}
