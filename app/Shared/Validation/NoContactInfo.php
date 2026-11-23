<?php

declare(strict_types=1);

namespace App\Shared\Validation;

use App\Shared\Support\ContactInfo;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class NoContactInfo implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && ContactInfo::found($value)) {
            $fail('validation.no_contact_info')->translate();
        }
    }
}
