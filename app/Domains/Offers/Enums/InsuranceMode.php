<?php

declare(strict_types=1);

namespace App\Domains\Offers\Enums;

/**
 * Free insurance (spec 1.8 #10): insured offers rank first. A game×category makes it compulsory with
 * `policy_override.compulsory_insurance_days`; the seller must then offer at least that many days.
 */
enum InsuranceMode: string
{
    case None = 'none';
    case Optional = 'optional';
    case Compulsory = 'compulsory';
}
