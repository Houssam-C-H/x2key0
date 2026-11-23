<?php

declare(strict_types=1);

namespace App\Domains\Certification\Enums;

enum CertificationStatus: string
{
    case None = 'none';
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
}
