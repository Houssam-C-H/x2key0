<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Catalog\Models\Game;
use App\Domains\Content\Models\HelpArticle;
use App\Domains\Content\Models\NewsArticle;
use App\Domains\Content\Models\Page;
use App\Domains\Content\Models\SeoLinkRow;
use Illuminate\Database\Seeder;

/**
 * Local demo content (spec 1.3 / Part 20): footer pages (legal texts are placeholders until written with a lawyer —
 * M19), help articles per section, four news articles and an SEO keyword row. Idempotent by slug.
 */
final class DemoContentSeeder extends Seeder
{
    private const string DRAFT = "> **Draft.** This text is a placeholder until the final version is written with a lawyer (launch hardening, M19).\n\n";

    public function run(): void
    {
        $pages = [
            ['about', 'company', ['en' => 'About X2KEY', 'fr' => 'À propos de X2KEY', 'ar' => 'عن X2KEY'], 'X2KEY is a marketplace where independent sellers offer game currency, top-ups, items, accounts, keys and gift cards. Every order is protected: money is held until delivery is confirmed.'],
            ['contact', 'company', ['en' => 'Contact', 'fr' => 'Contact', 'ar' => 'اتصل بنا'], 'Questions about an order? Open a **service ticket** from your dashboard. Business enquiries: use the contact form in the Support Center.'],
            ['how-to-buy', 'support', ['en' => 'How to buy', 'fr' => 'Comment acheter', 'ar' => 'كيفية الشراء'], "1. Find your game and category.\n2. Compare offers and pick a seller.\n3. Pay with card or your Credits Balance.\n4. Confirm when you have received your item."],
            ['how-to-sell', 'support', ['en' => 'How to sell', 'fr' => 'Comment vendre', 'ar' => 'كيفية البيع'], 'Verify your e-mail, phone and identity in the Certification Center, open your store, apply for the game categories you sell and publish offers.'],
            ['terms', 'legal', ['en' => 'Terms of service', 'fr' => "Conditions d'utilisation", 'ar' => 'شروط الخدمة'], self::DRAFT.'These terms govern the use of X2KEY by buyers and sellers.'],
            ['privacy', 'legal', ['en' => 'Privacy policy', 'fr' => 'Politique de confidentialité', 'ar' => 'سياسة الخصوصية'], self::DRAFT.'How X2KEY collects, uses and protects personal data.'],
            ['cookies', 'legal', ['en' => 'Cookie policy', 'fr' => 'Politique relative aux cookies', 'ar' => 'سياسة ملفات تعريف الارتباط'], self::DRAFT.'Which cookies X2KEY uses and why.'],
            ['refund', 'policies', ['en' => 'Refund policy', 'fr' => 'Politique de remboursement', 'ar' => 'سياسة الاسترداد'], self::DRAFT.'If an order is not delivered or not as described, open a dispute: refunds go back to the way you paid, or to your Credits Balance.'],
            ['buyer-protection', 'policies', ['en' => 'Buyer protection', 'fr' => 'Protection acheteur', 'ar' => 'حماية المشتري'], self::DRAFT.'Your payment is held by X2KEY until you confirm delivery or the confirmation window ends.'],
            ['seller-agreement', 'policies', ['en' => 'Seller agreement', 'fr' => 'Contrat vendeur', 'ar' => 'اتفاقية البائع'], self::DRAFT.'Rules for sellers: fees, holds, deliveries, disputes and violations.'],
            ['prohibited-items', 'policies', ['en' => 'Prohibited items', 'fr' => 'Articles interdits', 'ar' => 'المنتجات المحظورة'], self::DRAFT.'Items that cannot be sold on X2KEY.'],
            ['ip-policy', 'policies', ['en' => 'Intellectual property', 'fr' => 'Propriété intellectuelle', 'ar' => 'الملكية الفكرية'], self::DRAFT.'How rights holders can report content.'],
        ];
        foreach ($pages as $i => [$slug, $column, $title, $body]) {
            Page::query()->firstOrCreate(['slug' => $slug], ['title' => $title, 'body' => ['en' => $body], 'footer_column' => $column, 'sort' => $i, 'is_published' => true]);
        }

        $help = [
            ['buy-first-order', 'how_to_buy', 'How do I place my first order?', 'Open an offer, choose the quantity, fill in what the seller needs (for example your player ID) and pay. You can follow the order in **Purchase Orders**.'],
            ['buy-confirm', 'how_to_buy', 'When should I confirm an order?', 'Confirm only when you have received and checked your item. If you do nothing, the order is confirmed automatically at the end of the confirmation window.'],
            ['sell-open-store', 'how_to_sell', 'How do I open my store?', 'Complete e-mail, phone and identity verification in the Certification Center, then activate your store from the Seller Center.'],
            ['cancel-order', 'how_to_cancel', 'Can I cancel an order?', 'Before delivery you can cancel once the delivery deadline has passed. After delivery, open a dispute if something is wrong.'],
            ['payments-methods', 'payments', 'Which payment methods can I use?', 'Cards and other methods shown at checkout, plus your Credits Balance. A service charge is shown before you pay.'],
            ['account-2fa', 'account', 'How do I turn on two-factor authentication?', 'Go to **Settings › Security** and scan the QR code with an authenticator app.'],
            ['faq-safe', 'faq', 'Is it safe to buy on X2KEY?', 'Yes: never pay or talk outside X2KEY. The platform holds the money until you confirm, and disputes are handled by our staff.'],
        ];
        foreach ($help as $i => [$slug, $section, $title, $body]) {
            HelpArticle::query()->firstOrCreate(['slug' => $slug], ['section' => $section, 'title' => ['en' => $title], 'body' => ['en' => $body], 'sort' => $i, 'is_published' => true]);
        }

        $game = Game::query()->orderBy('id')->first();
        $news = [
            ['season-update', 'New season: what to expect', 'Patch notes, new items and the best time to stock up on currency.'],
            ['gift-card-guide', 'Gift cards: choosing the right region', 'A key or card only works in its region. Here is how to check before you buy.'],
            ['account-security', 'Keep your game account safe', 'Change your password and enable two-factor authentication after every account purchase.'],
            ['top-up-tips', 'Top-ups: player ID and server', 'Most failed top-ups come from a wrong player ID. Check it twice.'],
        ];
        foreach ($news as $i => [$slug, $title, $excerpt]) {
            NewsArticle::query()->firstOrCreate(['slug' => $slug], [
                'title' => ['en' => $title],
                'excerpt' => ['en' => $excerpt],
                'body' => ['en' => $excerpt."\n\nMore details will follow."],
                'game_id' => $game?->id,
                'published_at' => now()->subDays($i + 1),
                'status' => 'published',
            ]);
        }

        foreach (['Buy game currency' => '/en/catalog/currency', 'Game top-up' => '/en/catalog/top-up', 'Cheap game keys' => '/en/catalog/video-games', 'Gift cards' => '/en/catalog/gift-cards'] as $i => $url) {
            SeoLinkRow::query()->firstOrCreate(['url' => $url], ['label' => ['en' => $i], 'sort' => 0, 'is_enabled' => true]);
        }
    }
}
