<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Domains\Sellers\Models\MerchantContact;
use App\Http\Controllers\Seller\Concerns\ResolvesSeller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Merchant Services Contacts (spec 1.8): the seller's assigned merchant manager and channels,
 * the merchant services e-mail and a link to open a ticket (tickets ship in M12).
 */
final class MerchantContactsController
{
    use ResolvesSeller;

    public function __invoke(Request $request): Response
    {
        $profile = $this->seller($request)->load(['merchantManager', 'contacts.staff']);

        return Inertia::render('Seller/Contacts', [
            'manager' => $profile->merchantManager === null ? null : ['name' => $profile->merchantManager->name],
            'contacts' => $profile->contacts
                ->sortByDesc('is_primary')
                ->map(static fn (MerchantContact $c): array => [
                    'id' => $c->id,
                    'channel' => $c->channel->value,
                    'value' => $c->value,
                    'hours' => $c->hours,
                    'isPrimary' => $c->is_primary,
                    'staff' => $c->staff?->name,
                ])->values()->all(),
            'merchantEmail' => config()->string('x2key.support.merchant_email'),
            'ticketUrl' => '/tickets',
        ]);
    }
}
