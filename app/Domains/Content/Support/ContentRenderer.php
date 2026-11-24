<?php

declare(strict_types=1);

namespace App\Domains\Content\Support;

use Illuminate\Support\Str;

/**
 * Staff write content in Markdown; it is rendered to HTML with raw HTML stripped and unsafe links dropped
 * (CommonMark `html_input: strip`, `allow_unsafe_links: false`), so CMS text can never inject script into pages.
 */
final class ContentRenderer
{
    public static function html(string $markdown): string
    {
        return (string) Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 10,
        ]);
    }

    /** Plain-text summary for meta descriptions. */
    public static function summary(string $markdown, int $length = 160): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags(self::html($markdown))));

        return Str::limit($text, $length, '…');
    }
}
