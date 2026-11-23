<?php

declare(strict_types=1);

namespace App\Domains\Delivery\Models;

use App\Domains\Storage\Vault\SealedSecret;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A code or credentials typed by the seller (spec 5.8), sealed with SecretVault; only revealed to the buyer.
 *
 * @property int $id
 * @property int $delivery_id
 * @property string $kind
 * @property string $ciphertext
 * @property string $dek_wrapped
 * @property Carbon $created_at
 */
final class DeliverySecret extends Model
{
    public const null UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['ciphertext', 'dek_wrapped'];

    public function sealed(): SealedSecret
    {
        return new SealedSecret($this->ciphertext, $this->dek_wrapped);
    }
}
