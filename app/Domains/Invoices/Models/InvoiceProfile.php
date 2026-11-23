<?php

declare(strict_types=1);

namespace App\Domains\Invoices\Models;

use App\Domains\Identity\Models\User;
use App\Shared\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Invoice info" (spec 5.16): legal name, tax id (encrypted at rest), address and e-mail printed on invoices.
 *
 * @property int $id
 * @property int $user_id
 * @property string $legal_name
 * @property string|null $tax_id_encrypted
 * @property array{line1?: string, line2?: string|null, city?: string, postal_code?: string|null, country?: string} $address
 * @property string $email
 */
final class InvoiceProfile extends Model
{
    use Auditable;

    protected $fillable = ['user_id', 'legal_name', 'tax_id_encrypted', 'address', 'email'];

    protected $hidden = ['tax_id_encrypted'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['tax_id_encrypted' => 'encrypted', 'address' => 'array'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
