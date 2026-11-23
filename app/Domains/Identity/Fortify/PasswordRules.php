<?php

declare(strict_types=1);

namespace App\Domains\Identity\Fortify;

use Illuminate\Validation\Rules\Password;

final class PasswordRules
{
    /**
     * @return list<mixed>
     */
    public static function rules(): array
    {
        $rule = Password::min(10)->letters()->numbers();

        // The breach check calls the Have I Been Pwned range API; production only (no network in tests/offline dev).
        if (app()->isProduction()) {
            $rule = $rule->uncompromised();
        }

        return ['required', 'string', $rule, 'confirmed'];
    }
}
