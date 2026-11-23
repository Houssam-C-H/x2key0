<?php

declare(strict_types=1);

namespace App\Shared\Support;

/**
 * Detects off-platform contact details in seller-written text (store description, notices, offers):
 * e-mail addresses, links/domains, messenger invite links and phone numbers. Trading off-platform
 * removes buyer protection, so these are refused (spec 1.8 "no contact info allowed").
 */
final class ContactInfo
{
    private const array PATTERNS = [
        // e-mail, also "name [at] domain [dot] com"
        '/[\p{L}\p{N}._%+-]+\s*(?:@|\[at\]|\(at\))\s*[\p{L}\p{N}-]+\s*(?:\.|\[dot\]|\(dot\))\s*[a-z]{2,}/iu',
        // explicit links and bare domains
        '/\b(?:https?:\/\/|www\.)\S+/iu',
        '/\b[a-z0-9-]{2,}\.(?:com|net|org|io|gg|me|ru|cn|co|shop|store|xyz|info|biz)\b/iu',
        // messenger invites and handles
        '/\b(?:t\.me|wa\.me|discord\.gg|discord\.com\/invite)\b/iu',
        '/\b(?:telegram|whatsapp|wechat|skype|discord|viber|signal)\s*[:@]\s*\S+/iu',
        // phone numbers: 8+ digits, separators allowed
        '/(?:\+|\b)\d(?:[\s().-]?\d){7,}\b/u',
    ];

    public static function found(string $text): bool
    {
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
