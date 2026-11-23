<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Tickets\Models\CannedReply;
use Illuminate\Database\Seeder;

/** Starter canned replies (spec Part 11) in the three languages; staff edit them in the admin. */
final class CannedReplySeeder extends Seeder
{
    public function run(): void
    {
        $replies = [
            ['Need order details', null, [
                'en' => "Thanks for contacting X2KEY support. Could you send the order number and a screenshot of the problem? We'll check right away.",
                'fr' => "Merci d'avoir contacté le support X2KEY. Pouvez-vous nous envoyer le numéro de commande et une capture du problème ? Nous vérifions tout de suite.",
                'ar' => 'شكرًا لتواصلك مع دعم X2KEY. هل يمكنك إرسال رقم الطلب ولقطة شاشة للمشكلة؟ سنتحقق فورًا.',
            ]],
            ['Refund timing', 'refund', [
                'en' => 'Your refund has been issued. Credits Balance refunds are instant; card refunds usually reach your bank within 5–10 business days.',
                'fr' => 'Votre remboursement a été émis. Les remboursements en crédits sont immédiats ; sur carte, comptez généralement 5 à 10 jours ouvrés.',
                'ar' => 'تم إصدار الاسترداد. الاسترداد إلى رصيد الأرصدة فوري؛ أما إلى البطاقة فيصل عادةً خلال 5 إلى 10 أيام عمل.',
            ]],
            ['Catalog request received', 'catalog_request', [
                'en' => "Thanks for the suggestion! Our catalog team reviews new games and categories every week; we'll let you know when it is live.",
                'fr' => 'Merci pour la suggestion ! Notre équipe catalogue examine les nouveaux jeux et catégories chaque semaine ; nous vous préviendrons dès sa mise en ligne.',
                'ar' => 'شكرًا على الاقتراح! يراجع فريق الكتالوج الألعاب والفئات الجديدة كل أسبوع، وسنبلغك عند إضافتها.',
            ]],
        ];

        foreach ($replies as [$title, $topic, $body]) {
            CannedReply::query()->updateOrCreate(['title' => $title], ['topic' => $topic, 'body' => $body, 'is_active' => true]);
        }
    }
}
