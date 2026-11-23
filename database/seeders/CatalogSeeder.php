<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Catalog\Actions\RecountCatalog;
use App\Domains\Catalog\Enums\CategoryTypeCode;
use App\Domains\Catalog\Enums\ProductStatus;
use App\Domains\Catalog\Models\CategoryType;
use App\Domains\Catalog\Models\Game;
use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Catalog\Models\Platform;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\Region;
use App\Shared\Money\Amount;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Catalog seed (spec 24): 15 category types, platforms, regions, ~40 games with label overrides and
 * attribute schemas, and generated products so every catalog screen renders with data locally.
 * Category types/platforms/regions run in every environment; games/products only in local/testing.
 * Game names are used nominatively; all descriptions are original.
 */
final class CatalogSeeder extends Seeder
{
    private const array ICONS = [
        'currency' => 'coin', 'top_up' => 'bolt', 'items' => 'box', 'boosting' => 'trophy', 'accounts' => 'user_check',
        'video_games' => 'gamepad', 'gift_cards' => 'gift', 'coupons_savings' => 'tag',
    ];

    public function run(): void
    {
        $this->categoryTypes();
        $this->platforms();
        $this->regions();

        if (app()->environment('local', 'testing')) {
            mt_srand(2711); // reproducible demo data
            // One transaction: ~1,000 inserts (products, audit rows) would otherwise each fsync on SQLite.
            DB::transaction(fn () => $this->games());
            app(RecountCatalog::class)->handle();
        }
    }

    private function categoryTypes(): void
    {
        $lang = [];
        foreach (['en', 'fr', 'ar'] as $locale) {
            $lang[$locale] = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
        }

        foreach (CategoryTypeCode::cases() as $sort => $code) {
            $name = [];
            foreach ($lang as $locale => $strings) {
                $label = is_array($strings) ? ($strings[$code->labelKey()] ?? null) : null;
                $name[$locale] = is_string($label) ? $label : $code->value;
            }

            CategoryType::query()->updateOrCreate(['code' => $code], [
                'name' => $name,
                'icon' => self::ICONS[$code->value] ?? 'box',
                'sort' => $sort,
                'is_enabled' => true,
            ]);
        }
    }

    private function platforms(): void
    {
        $platforms = [
            'pc' => 'PC', 'steam' => 'Steam', 'epic' => 'Epic Games', 'xbox' => 'Xbox', 'playstation' => 'PlayStation',
            'nintendo' => 'Nintendo Switch', 'battle-net' => 'Battle.net', 'ea-app' => 'EA app', 'ubisoft' => 'Ubisoft Connect',
            'rockstar' => 'Rockstar', 'ios' => 'iOS', 'android' => 'Android',
        ];

        $sort = 0;
        foreach ($platforms as $slug => $name) {
            Platform::query()->updateOrCreate(['slug' => $slug], ['name' => $name, 'sort' => $sort++]);
        }
    }

    private function regions(): void
    {
        $regions = [
            'GLOBAL' => ['🌐', 'Global', 'Monde', 'عالمي'],
            'EU' => ['🇪🇺', 'Europe', 'Europe', 'أوروبا'],
            'US' => ['🇺🇸', 'United States', 'États-Unis', 'الولايات المتحدة'],
            'UK' => ['🇬🇧', 'United Kingdom', 'Royaume-Uni', 'المملكة المتحدة'],
            'TR' => ['🇹🇷', 'Turkey', 'Turquie', 'تركيا'],
            'LATAM' => ['🌎', 'Latin America', 'Amérique latine', 'أمريكا اللاتينية'],
            'BR' => ['🇧🇷', 'Brazil', 'Brésil', 'البرازيل'],
            'MENA' => ['🌍', 'Middle East & North Africa', 'Moyen-Orient et Afrique du Nord', 'الشرق الأوسط وشمال أفريقيا'],
            'IN' => ['🇮🇳', 'India', 'Inde', 'الهند'],
            'ASIA' => ['🌏', 'Asia', 'Asie', 'آسيا'],
        ];

        $sort = 0;
        foreach ($regions as $code => [$flag, $en, $fr, $ar]) {
            Region::query()->updateOrCreate(['code' => $code], ['flag' => $flag, 'name' => ['en' => $en, 'fr' => $fr, 'ar' => $ar], 'sort' => $sort++]);
        }
    }

    private function games(): void
    {
        foreach (self::catalog() as $slug => $game) {
            $model = Game::query()->updateOrCreate(['slug' => $slug], [
                'name' => ['en' => $game['name']],
                'publisher' => $game['publisher'],
                'is_hot' => $game['hot'] ?? false,
                'is_new' => $game['new'] ?? false,
                'is_enabled' => true,
                'first_listed_at' => now()->subDays(mt_rand(1, 900)),
                'search_aliases' => $game['aliases'] ?? [],
            ]);

            foreach ($game['categories'] as $code => $options) {
                $type = CategoryType::query()->where('code', $code)->firstOrFail();
                $label = $options['label'] ?? null;

                $gameCategory = GameCategory::query()->updateOrCreate(
                    ['game_id' => $model->id, 'category_type_id' => $type->id],
                    [
                        'slug' => $options['slug'] ?? ($label !== null ? Str::slug($label) : $type->urlSlug()),
                        'label_override' => $label !== null ? ['en' => $label] : null,
                        'attribute_schema' => self::schemaFor($code),
                        'buyer_input_schema' => self::buyerInputsFor($code),
                        'notice' => self::notice($game['name']),
                        'is_enabled' => true,
                    ],
                );

                $this->products($gameCategory, $game, $code, $options);
            }
        }
    }

    /**
     * @param  array{name: string, platforms?: list<string>}  $game
     * @param  array{products?: int}  $options
     */
    private function products(GameCategory $gameCategory, array $game, string $code, array $options): void
    {
        $count = $options['products'] ?? match ($code) {
            'video_games', 'game_dlc' => 6,
            'gift_cards', 'egift_cards', 'retail_gift_cards' => 8,
            'top_up', 'mobile_recharges', 'subscriptions', 'software_apps', 'gaming_ecards' => 5,
            default => 0,
        };

        $platformIds = Platform::query()->whereIn('slug', $game['platforms'] ?? ['pc'])->pluck('id')->all();
        $regionIds = Region::query()->pluck('id', 'code')->all();

        for ($i = 0; $i < $count; $i++) {
            [$name, $attributes, $msrp] = self::productSpec($game['name'], $code, $gameCategory, $i);

            $product = Product::query()->firstOrNew(['game_category_id' => $gameCategory->id, 'slug' => Str::slug($name)]);
            // Reuse the loaded game×category so the attribute validation hook does not query it per product.
            $product->setRelation('gameCategory', $gameCategory);
            $product->fill([
                'name' => ['en' => $name],
                'description' => ['en' => self::description($name, $game['name'])],
                'platform_id' => $platformIds === [] ? null : $platformIds[$i % count($platformIds)],
                'region_id' => array_values($regionIds)[$i % 4] ?? null,
                'attribute_values' => $attributes,
                'msrp' => $msrp === null ? null : Amount::usd($msrp),
                'status' => ProductStatus::Active,
            ])->save();
        }
    }

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: int|null}
     */
    private static function productSpec(string $game, string $code, GameCategory $gameCategory, int $i): array
    {
        $label = $gameCategory->label_override['en'] ?? null;

        return match ($code) {
            'video_games' => (function () use ($game, $i): array {
                $editions = ['standard', 'deluxe', 'ultimate', 'gold', 'standard', 'deluxe'];
                $edition = $editions[$i % count($editions)];
                $genres = [['action', 'adventure'], ['shooter'], ['rpg', 'action'], ['sports'], ['racing'], ['strategy']];

                return [
                    "{$game} ".ucfirst($edition).' Edition'.($i >= 4 ? ' (Bundle '.($i - 3).')' : ''),
                    ['edition' => $edition, 'genres' => $genres[$i % count($genres)], 'multiplayer' => $i % 3 !== 0, 'release_year' => 2018 + ($i % 8)],
                    [5999, 7999, 9999, 8999, 5999, 6999][$i % 6],
                ];
            })(),
            'game_dlc' => ["{$game} Expansion Pack ".($i + 1), ['dlc_type' => ['expansion', 'cosmetic', 'season_pass'][$i % 3]], [1499, 2499, 3999][$i % 3]],
            'gift_cards', 'egift_cards', 'retail_gift_cards' => (function () use ($game, $i): array {
                $values = [10, 20, 25, 50, 75, 100, 15, 30];
                $currencies = ['usd', 'eur', 'gbp', 'try'];
                $value = $values[$i % count($values)];
                $currency = $currencies[$i % count($currencies)];

                return ["{$game} Gift Card {$value} ".strtoupper($currency), ['face_value' => $value, 'card_currency' => $currency], $currency === 'usd' ? $value * 100 : null];
            })(),
            'top_up', 'mobile_recharges' => (function () use ($game, $label, $i): array {
                $amounts = [500, 1000, 2800, 5000, 13500];
                $amount = $amounts[$i % count($amounts)];

                return ["{$game} {$amount} ".($label ?? 'Credits'), ['amount' => $amount, 'bonus_included' => $i >= 2], null];
            })(),
            'subscriptions' => ["{$game} ".[1, 3, 6, 12, 24][$i % 5].' Month Membership', ['duration_months' => [1, 3, 6, 12, 24][$i % 5]], null],
            'software_apps' => ["{$game} ".['Home', 'Pro', 'Business', 'Education', 'Enterprise'][$i % 5].' License', ['license_type' => ['retail', 'oem', 'volume'][$i % 3]], null],
            default => ["{$game} Card ".($i + 1), [], null],
        };
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private static function schemaFor(string $code): ?array
    {
        $option = static fn (string $value, string $en, string $fr, string $ar): array => ['value' => $value, 'label' => ['en' => $en, 'fr' => $fr, 'ar' => $ar]];

        return match ($code) {
            'video_games' => [
                ['key' => 'edition', 'type' => 'select', 'label' => ['en' => 'Edition', 'fr' => 'Édition', 'ar' => 'الإصدار'], 'options' => [
                    $option('standard', 'Standard', 'Standard', 'قياسي'), $option('deluxe', 'Deluxe', 'Deluxe', 'ديلوكس'),
                    $option('ultimate', 'Ultimate', 'Ultimate', 'ألتيميت'), $option('gold', 'Gold', 'Gold', 'ذهبي'),
                ]],
                ['key' => 'genres', 'type' => 'multiselect', 'label' => ['en' => 'Genre', 'fr' => 'Genre', 'ar' => 'النوع'], 'options' => [
                    $option('action', 'Action', 'Action', 'أكشن'), $option('adventure', 'Adventure', 'Aventure', 'مغامرة'),
                    $option('rpg', 'RPG', 'RPG', 'تقمص أدوار'), $option('shooter', 'Shooter', 'Tir', 'تصويب'),
                    $option('sports', 'Sports', 'Sport', 'رياضة'), $option('racing', 'Racing', 'Course', 'سباق'),
                    $option('strategy', 'Strategy', 'Stratégie', 'استراتيجية'),
                ]],
                ['key' => 'multiplayer', 'type' => 'boolean', 'label' => ['en' => 'Online multiplayer', 'fr' => 'Multijoueur en ligne', 'ar' => 'لعب جماعي عبر الإنترنت']],
                ['key' => 'release_year', 'type' => 'number', 'label' => ['en' => 'Release year', 'fr' => 'Année de sortie', 'ar' => 'سنة الإصدار']],
            ],
            'game_dlc' => [
                ['key' => 'dlc_type', 'type' => 'select', 'label' => ['en' => 'DLC type', 'fr' => 'Type de DLC', 'ar' => 'نوع المحتوى'], 'options' => [
                    $option('expansion', 'Expansion', 'Extension', 'توسعة'), $option('cosmetic', 'Cosmetic', 'Cosmétique', 'تجميلي'),
                    $option('season_pass', 'Season pass', 'Season pass', 'تذكرة الموسم'),
                ]],
            ],
            'gift_cards', 'egift_cards', 'retail_gift_cards' => [
                ['key' => 'face_value', 'type' => 'number', 'label' => ['en' => 'Card value', 'fr' => 'Valeur de la carte', 'ar' => 'قيمة البطاقة']],
                ['key' => 'card_currency', 'type' => 'select', 'label' => ['en' => 'Card currency', 'fr' => 'Devise de la carte', 'ar' => 'عملة البطاقة'], 'options' => [
                    $option('usd', 'USD', 'USD', 'دولار'), $option('eur', 'EUR', 'EUR', 'يورو'), $option('gbp', 'GBP', 'GBP', 'جنيه'), $option('try', 'TRY', 'TRY', 'ليرة'),
                ]],
            ],
            'top_up', 'mobile_recharges' => [
                ['key' => 'amount', 'type' => 'number', 'label' => ['en' => 'Amount', 'fr' => 'Montant', 'ar' => 'الكمية']],
                ['key' => 'bonus_included', 'type' => 'boolean', 'label' => ['en' => 'Bonus included', 'fr' => 'Bonus inclus', 'ar' => 'يشمل مكافأة']],
            ],
            'subscriptions' => [
                ['key' => 'duration_months', 'type' => 'number', 'unit' => 'months', 'label' => ['en' => 'Duration', 'fr' => 'Durée', 'ar' => 'المدة']],
            ],
            'software_apps' => [
                ['key' => 'license_type', 'type' => 'select', 'label' => ['en' => 'License type', 'fr' => 'Type de licence', 'ar' => 'نوع الترخيص'], 'options' => [
                    $option('retail', 'Retail', 'Boîte', 'تجزئة'), $option('oem', 'OEM', 'OEM', 'OEM'), $option('volume', 'Volume', 'Volume', 'جماعي'),
                ]],
            ],
            // Offer attributes for seller listings (used from M5), e.g. the accounts filters in spec 1.4.1.
            'accounts' => [
                ['key' => 'account_type', 'type' => 'select', 'label' => ['en' => 'Account type', 'fr' => 'Type de compte', 'ar' => 'نوع الحساب'], 'options' => [
                    $option('skins', 'Skins account', 'Compte skins', 'حساب أزياء'), $option('currency', 'Currency account', 'Compte monnaie', 'حساب عملات'),
                    $option('starter', 'Starter account', 'Compte débutant', 'حساب مبتدئ'), $option('other', 'Other', 'Autre', 'أخرى'),
                ]],
                ['key' => 'account_level', 'type' => 'number', 'label' => ['en' => 'Account level', 'fr' => 'Niveau du compte', 'ar' => 'مستوى الحساب']],
                ['key' => 'full_mail_access', 'type' => 'boolean', 'label' => ['en' => 'Full email access', 'fr' => 'Accès complet à l\'e-mail', 'ar' => 'وصول كامل للبريد']],
                ['key' => 'linked_platforms', 'type' => 'multiselect', 'label' => ['en' => 'Linked to', 'fr' => 'Lié à', 'ar' => 'مرتبط بـ'], 'options' => [
                    $option('pc', 'PC', 'PC', 'PC'), $option('playstation', 'PlayStation', 'PlayStation', 'PlayStation'),
                    $option('xbox', 'Xbox', 'Xbox', 'Xbox'), $option('mobile', 'Mobile', 'Mobile', 'جوال'),
                ]],
            ],
            default => null,
        };
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private static function buyerInputsFor(string $code): ?array
    {
        return match ($code) {
            'top_up', 'mobile_recharges' => [
                ['key' => 'player_id', 'type' => 'text', 'label' => ['en' => 'Player ID', 'fr' => 'ID joueur', 'ar' => 'معرّف اللاعب'], 'required' => true, 'pattern' => '^[A-Za-z0-9_-]{4,32}$'],
                ['key' => 'server', 'type' => 'text', 'label' => ['en' => 'Server', 'fr' => 'Serveur', 'ar' => 'الخادم'], 'required' => false],
            ],
            'currency', 'items' => [
                ['key' => 'character_name', 'type' => 'text', 'label' => ['en' => 'Character name', 'fr' => 'Nom du personnage', 'ar' => 'اسم الشخصية'], 'required' => true],
            ],
            default => null,
        };
    }

    /**
     * @return array<string, string>
     */
    private static function notice(string $game): array
    {
        return [
            'en' => "Items on this page are listed by independent sellers. X2KEY is not affiliated with or endorsed by the publisher of {$game}. Trading in-game goods may be against the game's terms; you buy at your own risk. All trademarks belong to their owners.",
            'fr' => "Les articles de cette page sont proposés par des vendeurs indépendants. X2KEY n'est ni affilié ni approuvé par l'éditeur de {$game}. L'échange de biens en jeu peut enfreindre les conditions du jeu ; vous achetez à vos risques. Les marques appartiennent à leurs propriétaires.",
            'ar' => "المنتجات في هذه الصفحة معروضة من بائعين مستقلين. X2KEY غير تابع لناشر {$game} ولا يحظى بتأييده. قد يخالف تداول عناصر اللعبة شروطها، والشراء على مسؤوليتك. جميع العلامات التجارية مملوكة لأصحابها.",
        ];
    }

    private static function description(string $product, string $game): string
    {
        return "{$product} for {$game}. Delivered by verified X2KEY sellers with buyer protection: if it doesn't work as described, open a dispute and get your money back.";
    }

    /**
     * @return array<string, array{name: string, publisher: string, hot?: bool, new?: bool, aliases?: list<string>, platforms?: list<string>, categories: array<string, array{label?: string, slug?: string, products?: int}>}>
     */
    private static function catalog(): array
    {
        $vg = ['video_games' => [], 'game_dlc' => []];

        return [
            'fortnite' => ['name' => 'Fortnite', 'publisher' => 'Epic Games', 'hot' => true, 'aliases' => ['fn'], 'platforms' => ['epic', 'playstation', 'xbox', 'nintendo'],
                'categories' => ['accounts' => [], 'items' => [], 'top_up' => ['label' => 'V-Bucks', 'slug' => 'v-bucks'], 'gift_cards' => [], 'boosting' => []]],
            'ea-sports-fc-27' => ['name' => 'EA Sports FC 27', 'publisher' => 'Electronic Arts', 'hot' => true, 'new' => true, 'aliases' => ['fc 27', 'fifa'], 'platforms' => ['ea-app', 'playstation', 'xbox'],
                'categories' => ['currency' => ['label' => 'Coins', 'slug' => 'coins'], 'top_up' => ['label' => 'FC Points', 'slug' => 'fc-points'], 'accounts' => [], 'boosting' => [], 'video_games' => []]],
            'nba-2k27' => ['name' => 'NBA 2K27', 'publisher' => '2K', 'hot' => true, 'new' => true, 'platforms' => ['steam', 'playstation', 'xbox'],
                'categories' => ['currency' => ['label' => 'MT', 'slug' => 'mt'], 'top_up' => ['label' => 'VC', 'slug' => 'vc'], 'accounts' => [], 'video_games' => []]],
            'madden-nfl-27' => ['name' => 'Madden NFL 27', 'publisher' => 'Electronic Arts', 'new' => true, 'platforms' => ['playstation', 'xbox'],
                'categories' => ['currency' => ['label' => 'Coins', 'slug' => 'coins'], 'video_games' => []]],
            'roblox' => ['name' => 'Roblox', 'publisher' => 'Roblox Corporation', 'hot' => true, 'aliases' => ['rbx'], 'platforms' => ['pc', 'ios', 'android', 'xbox'],
                'categories' => ['top_up' => ['label' => 'Robux', 'slug' => 'robux'], 'items' => [], 'accounts' => [], 'gift_cards' => []]],
            'valorant' => ['name' => 'Valorant', 'publisher' => 'Riot Games', 'hot' => true, 'platforms' => ['pc'],
                'categories' => ['accounts' => [], 'top_up' => ['label' => 'VP', 'slug' => 'vp'], 'boosting' => [], 'gift_cards' => []]],
            'league-of-legends' => ['name' => 'League of Legends', 'publisher' => 'Riot Games', 'aliases' => ['lol'], 'platforms' => ['pc'],
                'categories' => ['accounts' => [], 'top_up' => ['label' => 'RP', 'slug' => 'rp'], 'boosting' => []]],
            'genshin-impact' => ['name' => 'Genshin Impact', 'publisher' => 'HoYoverse', 'hot' => true, 'platforms' => ['pc', 'playstation', 'ios', 'android'],
                'categories' => ['top_up' => ['label' => 'Genesis Crystals', 'slug' => 'genesis-crystals'], 'accounts' => []]],
            'honkai-star-rail' => ['name' => 'Honkai: Star Rail', 'publisher' => 'HoYoverse', 'platforms' => ['pc', 'ios', 'android'],
                'categories' => ['top_up' => ['label' => 'Oneiric Shards', 'slug' => 'oneiric-shards'], 'accounts' => []]],
            'zenless-zone-zero' => ['name' => 'Zenless Zone Zero', 'publisher' => 'HoYoverse', 'new' => true, 'platforms' => ['pc', 'playstation', 'ios', 'android'],
                'categories' => ['top_up' => ['label' => 'Monochrome', 'slug' => 'monochrome'], 'accounts' => []]],
            'pubg-mobile' => ['name' => 'PUBG Mobile', 'publisher' => 'Krafton', 'hot' => true, 'platforms' => ['ios', 'android'],
                'categories' => ['top_up' => ['label' => 'UC', 'slug' => 'uc'], 'accounts' => []]],
            'free-fire' => ['name' => 'Free Fire', 'publisher' => 'Garena', 'platforms' => ['ios', 'android'],
                'categories' => ['top_up' => ['label' => 'Diamonds', 'slug' => 'diamonds'], 'accounts' => []]],
            'mobile-legends' => ['name' => 'Mobile Legends: Bang Bang', 'publisher' => 'Moonton', 'aliases' => ['mlbb'], 'platforms' => ['ios', 'android'],
                'categories' => ['top_up' => ['label' => 'Diamonds', 'slug' => 'diamonds'], 'accounts' => [], 'boosting' => []]],
            'call-of-duty' => ['name' => 'Call of Duty', 'publisher' => 'Activision', 'hot' => true, 'aliases' => ['cod', 'warzone'], 'platforms' => ['battle-net', 'steam', 'playstation', 'xbox'],
                'categories' => ['top_up' => ['label' => 'COD Points', 'slug' => 'cod-points'], 'accounts' => [], 'boosting' => [], 'video_games' => []]],
            'apex-legends' => ['name' => 'Apex Legends', 'publisher' => 'Electronic Arts', 'platforms' => ['ea-app', 'steam', 'playstation', 'xbox'],
                'categories' => ['top_up' => ['label' => 'Apex Coins', 'slug' => 'apex-coins'], 'accounts' => [], 'boosting' => []]],
            'counter-strike-2' => ['name' => 'Counter-Strike 2', 'publisher' => 'Valve', 'hot' => true, 'aliases' => ['cs2', 'csgo'], 'platforms' => ['steam'],
                'categories' => ['items' => ['label' => 'Skins', 'slug' => 'skins'], 'accounts' => [], 'boosting' => []]],
            'dota-2' => ['name' => 'Dota 2', 'publisher' => 'Valve', 'platforms' => ['steam'],
                'categories' => ['items' => [], 'accounts' => [], 'boosting' => []]],
            'world-of-warcraft' => ['name' => 'World of Warcraft', 'publisher' => 'Blizzard', 'aliases' => ['wow'], 'platforms' => ['battle-net'],
                'categories' => ['currency' => ['label' => 'Gold', 'slug' => 'gold'], 'items' => [], 'boosting' => [], 'accounts' => [], 'subscriptions' => []]],
            'old-school-runescape' => ['name' => 'Old School RuneScape', 'publisher' => 'Jagex', 'aliases' => ['osrs'], 'platforms' => ['pc', 'ios', 'android'],
                'categories' => ['currency' => ['label' => 'Gold', 'slug' => 'gold'], 'items' => [], 'accounts' => [], 'boosting' => []]],
            'grand-theft-auto-v' => ['name' => 'Grand Theft Auto V', 'publisher' => 'Rockstar Games', 'aliases' => ['gta 5', 'gta online'], 'platforms' => ['rockstar', 'steam', 'playstation', 'xbox'],
                'categories' => ['currency' => ['label' => 'Money', 'slug' => 'money'], 'accounts' => [], 'video_games' => [], 'top_up' => ['label' => 'Shark Cards', 'slug' => 'shark-cards']]],
            'minecraft' => ['name' => 'Minecraft', 'publisher' => 'Mojang', 'platforms' => ['pc', 'xbox', 'playstation', 'nintendo'],
                'categories' => ['video_games' => [], 'top_up' => ['label' => 'Minecoins', 'slug' => 'minecoins'], 'accounts' => []]],
            'clash-of-clans' => ['name' => 'Clash of Clans', 'publisher' => 'Supercell', 'aliases' => ['coc'], 'platforms' => ['ios', 'android'],
                'categories' => ['top_up' => ['label' => 'Gems', 'slug' => 'gems'], 'accounts' => []]],
            'clash-royale' => ['name' => 'Clash Royale', 'publisher' => 'Supercell', 'platforms' => ['ios', 'android'],
                'categories' => ['top_up' => ['label' => 'Gems', 'slug' => 'gems'], 'accounts' => []]],
            'brawl-stars' => ['name' => 'Brawl Stars', 'publisher' => 'Supercell', 'platforms' => ['ios', 'android'],
                'categories' => ['top_up' => ['label' => 'Gems', 'slug' => 'gems'], 'accounts' => []]],
            'diablo-iv' => ['name' => 'Diablo IV', 'publisher' => 'Blizzard', 'aliases' => ['diablo 4', 'd4'], 'platforms' => ['battle-net', 'steam', 'playstation', 'xbox'],
                'categories' => ['items' => [], 'currency' => ['label' => 'Gold', 'slug' => 'gold'], 'boosting' => [], ...$vg]],
            'path-of-exile-2' => ['name' => 'Path of Exile 2', 'publisher' => 'Grinding Gear Games', 'aliases' => ['poe2'], 'new' => true, 'platforms' => ['pc', 'playstation', 'xbox'],
                'categories' => ['currency' => ['label' => 'Divine Orbs', 'slug' => 'divine-orbs'], 'items' => [], 'boosting' => []]],
            'lost-ark' => ['name' => 'Lost Ark', 'publisher' => 'Smilegate', 'platforms' => ['steam'],
                'categories' => ['currency' => ['label' => 'Gold', 'slug' => 'gold'], 'accounts' => []]],
            'elden-ring' => ['name' => 'Elden Ring', 'publisher' => 'FromSoftware', 'platforms' => ['steam', 'playstation', 'xbox'],
                'categories' => ['items' => ['label' => 'Runes', 'slug' => 'runes'], ...$vg]],
            'overwatch-2' => ['name' => 'Overwatch 2', 'publisher' => 'Blizzard', 'platforms' => ['battle-net', 'playstation', 'xbox', 'nintendo'],
                'categories' => ['top_up' => ['label' => 'Coins', 'slug' => 'coins'], 'accounts' => [], 'boosting' => []]],
            'rocket-league' => ['name' => 'Rocket League', 'publisher' => 'Psyonix', 'platforms' => ['epic', 'playstation', 'xbox', 'nintendo'],
                'categories' => ['items' => [], 'top_up' => ['label' => 'Credits', 'slug' => 'credits'], 'accounts' => []]],
            'rainbow-six-siege' => ['name' => 'Rainbow Six Siege', 'publisher' => 'Ubisoft', 'aliases' => ['r6'], 'platforms' => ['ubisoft', 'steam', 'playstation', 'xbox'],
                'categories' => ['top_up' => ['label' => 'R6 Credits', 'slug' => 'r6-credits'], 'accounts' => [], 'boosting' => []]],
            'destiny-2' => ['name' => 'Destiny 2', 'publisher' => 'Bungie', 'platforms' => ['steam', 'playstation', 'xbox'],
                'categories' => ['boosting' => [], 'top_up' => ['label' => 'Silver', 'slug' => 'silver'], 'game_dlc' => []]],
            'escape-from-tarkov' => ['name' => 'Escape from Tarkov', 'publisher' => 'Battlestate Games', 'aliases' => ['eft'], 'platforms' => ['pc'],
                'categories' => ['currency' => ['label' => 'Roubles', 'slug' => 'roubles'], 'items' => [], 'boosting' => []]],
            'pokemon-go' => ['name' => 'Pokémon GO', 'publisher' => 'Niantic', 'platforms' => ['ios', 'android'],
                'categories' => ['accounts' => [], 'top_up' => ['label' => 'PokéCoins', 'slug' => 'pokecoins']]],
            'marvel-rivals' => ['name' => 'Marvel Rivals', 'publisher' => 'NetEase', 'new' => true, 'platforms' => ['steam', 'playstation', 'xbox'],
                'categories' => ['top_up' => ['label' => 'Lattice', 'slug' => 'lattice'], 'accounts' => [], 'boosting' => []]],
            'steam' => ['name' => 'Steam', 'publisher' => 'Valve', 'hot' => true, 'platforms' => ['steam'],
                'categories' => ['gift_cards' => ['label' => 'Wallet Codes', 'slug' => 'gift-cards'], 'accounts' => [], 'video_games' => []]],
            'xbox' => ['name' => 'Xbox', 'publisher' => 'Microsoft', 'platforms' => ['xbox'],
                'categories' => ['gift_cards' => [], 'subscriptions' => ['label' => 'Game Pass', 'slug' => 'game-pass']]],
            'playstation-network' => ['name' => 'PlayStation Network', 'publisher' => 'Sony', 'aliases' => ['psn'], 'platforms' => ['playstation'],
                'categories' => ['gift_cards' => [], 'subscriptions' => ['label' => 'PlayStation Plus', 'slug' => 'playstation-plus']]],
            'nintendo-eshop' => ['name' => 'Nintendo eShop', 'publisher' => 'Nintendo', 'platforms' => ['nintendo'],
                'categories' => ['gift_cards' => [], 'subscriptions' => ['label' => 'Switch Online', 'slug' => 'switch-online']]],
            'google-play' => ['name' => 'Google Play', 'publisher' => 'Google', 'platforms' => ['android'],
                'categories' => ['gift_cards' => [], 'egift_cards' => []]],
            'apple' => ['name' => 'Apple', 'publisher' => 'Apple', 'aliases' => ['itunes', 'app store'], 'platforms' => ['ios'],
                'categories' => ['gift_cards' => [], 'egift_cards' => []]],
            'netflix' => ['name' => 'Netflix', 'publisher' => 'Netflix', 'platforms' => ['pc'],
                'categories' => ['subscriptions' => [], 'gift_cards' => []]],
            'spotify' => ['name' => 'Spotify', 'publisher' => 'Spotify', 'platforms' => ['pc'],
                'categories' => ['subscriptions' => ['label' => 'Premium', 'slug' => 'premium']]],
            'windows' => ['name' => 'Windows', 'publisher' => 'Microsoft', 'platforms' => ['pc'],
                'categories' => ['software_apps' => ['label' => 'Keys', 'slug' => 'keys']]],
            'mobile-recharge' => ['name' => 'Mobile Recharge', 'publisher' => 'Various carriers', 'platforms' => ['android', 'ios'],
                'categories' => ['mobile_recharges' => ['label' => 'Airtime', 'slug' => 'airtime']]],
            'gaming-ecards' => ['name' => 'Paysafecard', 'publisher' => 'Paysafe', 'platforms' => ['pc'],
                'categories' => ['gaming_ecards' => []]],
            'coupons' => ['name' => 'Game Store Coupons', 'publisher' => 'Various', 'platforms' => ['pc'],
                'categories' => ['coupons_savings' => []]],
            'retail-cards' => ['name' => 'Amazon', 'publisher' => 'Amazon', 'platforms' => ['pc'],
                'categories' => ['retail_gift_cards' => []]],
        ];
    }
}
