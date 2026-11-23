<?php

declare(strict_types=1);

namespace App\Domains\Orders\Enums;

/** Order timers (spec 5.6). M6 processes `payment_expiry`; the others are created at `paid` and processed from M8. */
enum OrderTimerType: string
{
    case PaymentExpiry = 'payment_expiry';
    case AcceptDue = 'accept_due';
    case DeliveryDue = 'delivery_due';
    case AutoCancel = 'auto_cancel';
    case ConfirmDue = 'confirm_due';
    case Settle = 'settle';
    case RecoveryProtectionEnd = 'recovery_protection_end';
}
