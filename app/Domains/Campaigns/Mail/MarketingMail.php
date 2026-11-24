<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Mail;

use App\Domains\Campaigns\Models\MarketingEmail;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\Identity\Models\User;
use App\Domains\Offers\Models\Offer;
use App\Domains\Sellers\Models\SellerProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One recipient's copy of a seller's marketing e-mail (spec 14.3), in the recipient's language: the store, the
 * offers (with links), the private coupon code if any, and how to stop these e-mails (Settings › Account).
 */
final class MarketingMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $emailId, public readonly int $userId) {}

    public function envelope(): Envelope
    {
        [$email, $seller, $user] = $this->load();

        return new Envelope(subject: (string) __('marketing.mail.subject.'.$email->type, ['store' => $seller->store_name], $user->locale));
    }

    public function content(): Content
    {
        [$email, $seller, $user] = $this->load();
        $locale = $user->locale;
        $offers = Offer::query()->whereIn('id', $email->offer_ids)->get();
        $coupon = $email->coupon_id === null ? null : Coupon::query()->find($email->coupon_id);

        return new Content(view: 'mail.marketing', with: [
            'locale' => $locale,
            'type' => $email->type,
            'store' => ['name' => $seller->store_name, 'url' => url($seller->path($locale))],
            'offers' => $offers->map(static fn (Offer $o): array => [
                'title' => $o->title,
                'url' => url($o->path($locale)),
                'price' => number_format($o->unit_price_amount / 100, 2),
            ])->all(),
            'coupon' => $coupon === null ? null : ['code' => $coupon->code, 'label' => $coupon->label(), 'validTo' => $coupon->valid_to?->toDateString()],
            'name' => $user->name,
            'settingsUrl' => url('/settings/account'),
        ]);
    }

    /**
     * @return array{0: MarketingEmail, 1: SellerProfile, 2: User}
     */
    private function load(): array
    {
        $email = MarketingEmail::query()->findOrFail($this->emailId);

        return [$email, SellerProfile::query()->findOrFail($email->seller_profile_id), User::query()->findOrFail($this->userId)];
    }
}
