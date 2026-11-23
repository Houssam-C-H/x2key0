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
        // phone numbers: 8+ digits (any script, Arabic-Indic digits too), separators allowed
        '/(?:\+|\b)\p{Nd}(?:[\s().-]?\p{Nd}){7,}\b/u',
    ];

    /** Extra patterns for chat: spelled-out addresses and messenger names (French / Arabic / English). */
    private const array CHAT_PATTERNS = [
        '/[\p{L}\p{N}._-]+\s*(?:arobase|أت|آت)\s*[\p{L}\p{N}-]+\s*(?:point|dot|نقطة|\.)\s*\p{L}{2,}/iu',
        '/(?:واتساب|واتس\s?اب|تيليجرام|تلغرام|تليجرام|سناب|ديسكورد|فايبر|انستغرام|إنستغرام|انستقرام)\s*(?:[:@]+\s*)?@?[\p{L}\p{N}_.+-]{3,}/u',
        '/\b(?:whatsapp|telegram|discord|snap(?:chat)?|insta(?:gram)?|wechat|skype|viber|signal)\b\s*(?:(?:[:@=]+|id|me|moi|au|number|num[eé]ro)\s*)?@?[\p{L}\p{N}_.+-]{3,}/iu',
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

    /** Hides every off-platform contact detail behind a mask (chat, spec 12.1); clean text comes back unchanged. */
    public static function redact(string $text, string $mask = '••••'): string
    {
        foreach ([...self::PATTERNS, ...self::CHAT_PATTERNS] as $pattern) {
            $text = preg_replace($pattern, $mask, $text) ?? $text;
        }

        return $text;
    }
}
