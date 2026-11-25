<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Support;

use App\Domains\Catalog\Models\CategoryType;
use App\Domains\Catalog\Models\Game;
use App\Domains\Catalog\Models\GameCategory;

/**
 * Search-as-you-type and typo tolerance for game names (spec 1.2 header search). Matches every locale's name, the
 * staff-written aliases ("cod", "fifa") and the slug; scores exact / prefix / word-prefix / contains matches and,
 * when nothing contains the text, near misses by edit distance ("fortnight" → Fortnite). The game list is small
 * (hundreds), cached per locale with the catalog cache, so this never hits the database per keystroke.
 */
final class SearchSuggest
{
    /**
     * @return list<array{name: string, href: string, icon: string|null, score: int}>
     */
    public function games(string $text, string $locale, int $limit = 6): array
    {
        $q = self::normalise($text);
        if (mb_strlen($q) < 2) {
            return [];
        }

        $scored = [];
        foreach ($this->index($locale) as $game) {
            $score = 0;
            foreach ($game['terms'] as $term) {
                $score = max($score, self::score($q, $term));
            }
            if ($score > 0) {
                $scored[] = ['name' => $game['name'], 'href' => $game['href'], 'icon' => $game['icon'], 'score' => $score + min(9, $game['weight'])];
            }
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: strcmp($a['name'], $b['name']));

        return array_slice($scored, 0, $limit);
    }

    /** The closest game name when the text only matches by a typo, for "Did you mean …?". */
    public function didYouMean(string $text, string $locale): ?string
    {
        $best = $this->games($text, $locale, 1)[0] ?? null;

        return $best !== null && $best['score'] < 50 ? $best['name'] : null;
    }

    /**
     * Category types whose name starts with or contains the text.
     *
     * @return list<array{label: string, href: string}>
     */
    public function categories(string $text, string $locale, int $limit = 3): array
    {
        $q = self::normalise($text);
        if (mb_strlen($q) < 2) {
            return [];
        }

        $out = [];
        foreach (CategoryType::query()->enabled()->orderBy('sort')->get() as $type) {
            $label = $type->translate('name', $locale);
            if (str_contains(self::normalise($label), $q)) {
                $out[] = ['label' => $label, 'href' => "/{$locale}/catalog/{$type->urlSlug()}"];
            }
        }

        return array_slice($out, 0, $limit);
    }

    /**
     * @return list<array{name: string, href: string, icon: string|null, weight: int, terms: list<string>}>
     */
    private function index(string $locale): array
    {
        /** @var list<array{name: string, href: string, icon: string|null, weight: int, terms: list<string>}> */
        return CatalogCache::remember("suggest.{$locale}", static function () use ($locale): array {
            $visible = GameCategory::query()->visible()->select('game_id')->distinct()->pluck('game_id')->all();

            return array_values(Game::query()->where('is_enabled', true)->whereIn('id', $visible)->get()
                ->map(static fn (Game $g): array => [
                    'name' => $g->translate('name', $locale),
                    'href' => "/{$locale}/{$g->slug}",
                    'icon' => $g->iconUrl(),
                    // Popular games win ties: hot flag and listing count, capped.
                    'weight' => ($g->is_hot ? 5 : 0) + min(4, intdiv($g->offer_count_cached, 25)),
                    'terms' => array_values(array_unique(array_filter(array_map(
                        self::normalise(...),
                        [...array_values($g->name), ...($g->search_aliases ?? []), str_replace('-', ' ', $g->slug)],
                    )))),
                ])->all());
        });
    }

    /** 100 exact, 90 prefix, 80 word prefix, 60 contains, 20–40 near miss (edit distance), 0 no match. */
    private static function score(string $q, string $term): int
    {
        if ($term === $q) {
            return 100;
        }
        if (str_starts_with($term, $q)) {
            return 90;
        }
        foreach (explode(' ', $term) as $word) {
            if ($word !== '' && str_starts_with($word, $q)) {
                return 80;
            }
        }
        if (str_contains($term, $q)) {
            return 60;
        }

        // Typos: compare with the whole term and with its start of the same length (for unfinished words).
        $len = mb_strlen($q);
        if ($len < 4) {
            return 0;
        }
        $allowed = $len >= 8 ? 2 : 1;
        $distance = min(levenshtein($q, $term), levenshtein($q, mb_substr($term, 0, $len)));
        if ($distance <= $allowed) {
            return 40 - $distance * 10;
        }

        // Sound-alikes ("fortnight" / "Fortnite"): phonetic keys at most one edit apart.
        $key = metaphone($q);
        if (mb_strlen($key) >= 4) {
            foreach ([$term, ...explode(' ', $term)] as $candidate) {
                if (levenshtein($key, metaphone($candidate)) <= 1) {
                    return 25;
                }
            }
        }

        return 0;
    }

    private static function normalise(string $text): string
    {
        $ascii = mb_strtolower(trim($text));

        return (string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $ascii));
    }
}
