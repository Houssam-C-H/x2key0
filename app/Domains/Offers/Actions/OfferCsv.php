<?php

declare(strict_types=1);

namespace App\Domains\Offers\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Offers\Exceptions\OfferException;
use App\Domains\Offers\Models\Offer;
use App\Domains\Offers\Support\OfferRules;
use App\Domains\Sellers\Models\SellerProfile;
use Brick\Money\Money;
use Illuminate\Validation\ValidationException;
use SplFileObject;

/**
 * Bulk offers by CSV (Seller Center › Active offers). Export writes every offer of the store; import updates rows
 * that carry an `id` (blank cells keep the current value) and creates rows without one. Every row goes through
 * SaveOffer, so prices, stock, review rules, price history and service types are checked exactly as in the form.
 */
final class OfferCsv
{
    public const array COLUMNS = [
        'id', 'game_category_id', 'product_id', 'service_type_id', 'title', 'unit_price', 'min_qty', 'max_qty',
        'manual_stock_qty', 'delivery_time_minutes', 'delivery_method', 'status', 'game_category',
    ];

    public const int MAX_ROWS = 500;

    public function __construct(private readonly SaveOffer $save) {}

    /** @return resource */
    public function export(SellerProfile $profile)
    {
        $out = fopen('php://temp', 'w+b');
        if ($out === false) {
            throw new \RuntimeException('cannot open a temporary stream');
        }
        fputcsv($out, self::COLUMNS, escape: '');

        Offer::query()->where('seller_profile_id', $profile->id)->with(['gameCategory.game', 'gameCategory.type'])->orderBy('id')
            ->chunk(500, function ($offers) use ($out): void {
                foreach ($offers as $o) {
                    fputcsv($out, [
                        $o->public_id, $o->game_category_id, $o->product_id, $o->service_type_id, $o->title,
                        (string) Money::ofMinor($o->unit_price_amount, 'USD')->getAmount(), $o->min_qty, $o->max_qty,
                        $o->manual_stock_qty, $o->delivery_time_minutes, $o->delivery_method->value, $o->status->value,
                        $o->gameCategory->title('en'),
                    ], escape: '');
                }
            });
        rewind($out);

        return $out;
    }

    /**
     * @return array{created: int, updated: int, errors: list<array{line: int, message: string}>}
     */
    public function import(SellerProfile $profile, User $actor, string $path): array
    {
        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD);

        $header = null;
        $result = ['created' => 0, 'updated' => 0, 'errors' => []];
        $line = 0;

        foreach ($file as $row) {
            $line++;
            if (! is_array($row) || $row === [null]) {
                continue;
            }
            $row = array_map(static fn (mixed $v): string => is_string($v) ? trim($v) : '', $row);
            if ($header === null) {
                $header = array_map(static fn (string $h): string => mb_strtolower(ltrim($h, "\u{FEFF}")), $row);
                if (! in_array('title', $header, true) || ! in_array('unit_price', $header, true)) {
                    return ['created' => 0, 'updated' => 0, 'errors' => [['line' => 1, 'message' => (string) __('offer.csv.bad_header')]]];
                }

                continue;
            }
            if ($result['created'] + $result['updated'] + count($result['errors']) >= self::MAX_ROWS) {
                $result['errors'][] = ['line' => $line, 'message' => (string) __('offer.csv.too_many', ['max' => self::MAX_ROWS])];
                break;
            }

            $cells = array_combine(array_slice($header, 0, count($row)), array_slice($row, 0, count($header)));

            try {
                if (($cells['id'] ?? '') !== '') {
                    $offer = Offer::query()->where('seller_profile_id', $profile->id)->where('public_id', $cells['id'])->first();
                    if ($offer === null) {
                        throw OfferException::because('offer.csv.unknown_id');
                    }
                    $this->save->update($offer, $actor, $this->input($cells, $offer));
                    $result['updated']++;
                } else {
                    $this->save->create($profile, $actor, $this->input($cells, null));
                    $result['created']++;
                }
            } catch (OfferException|ValidationException|\DomainException $e) {
                $result['errors'][] = ['line' => $line, 'message' => mb_substr($e->getMessage(), 0, 300)];
            }
        }

        return $result;
    }

    /**
     * SaveOffer input from the row; for updates, blank cells keep the offer's current values.
     *
     * @param  array<string, string>  $c
     * @return array{game_category_id: int, product_id: int|null, service_type_id: int|null, title: string|null, description: array<string, string|null>, delivery_method: string, fulfillment: string, storage_group_id: int|null, attribute_values: array<string, mixed>, unit_price_amount: int, min_qty: int, max_qty: int|null, unit_label: string|null, integer_multiple: bool, quantity_tiers: list<array{min_qty: int, unit_price_amount: int}>, delivery_time_minutes: int, manual_stock_qty: int|null, insurance_days: int, acquisition_cost_amount: int|null, listing_days: int, publish_mode: string}
     */
    private function input(array $c, ?Offer $o): array
    {
        $int = static fn (string $key, ?int $current): ?int => ($c[$key] ?? '') === '' ? $current : (ctype_digit($c[$key]) ? (int) $c[$key] : throw OfferException::because('offer.csv.not_number', ['column' => $key]));
        $price = ($c['unit_price'] ?? '') === '' ? $o?->unit_price_amount : self::cents($c['unit_price']);
        if ($price === null) {
            throw OfferException::because('offer.csv.not_number', ['column' => 'unit_price']);
        }

        return [
            'game_category_id' => $o->game_category_id ?? ($int('game_category_id', null) ?? throw OfferException::because('offer.csv.required', ['column' => 'game_category_id'])),
            'product_id' => $o !== null ? $o->product_id : $int('product_id', null),
            'service_type_id' => $int('service_type_id', $o?->service_type_id),
            'title' => ($c['title'] ?? '') === '' ? $o?->title : $c['title'],
            'description' => $o->description ?? [],
            'delivery_method' => $o !== null ? $o->delivery_method->value : (($c['delivery_method'] ?? '') !== '' ? $c['delivery_method'] : throw OfferException::because('offer.csv.required', ['column' => 'delivery_method'])),
            'fulfillment' => $o !== null ? $o->fulfillment->value : 'manual',
            'storage_group_id' => $o?->storage_group_id,
            'attribute_values' => $o->attribute_values ?? [],
            'unit_price_amount' => $price,
            'min_qty' => $int('min_qty', $o->min_qty ?? 1) ?? 1,
            'max_qty' => $int('max_qty', $o?->max_qty),
            'unit_label' => $o?->unit_label,
            'integer_multiple' => $o->integer_multiple ?? false,
            'quantity_tiers' => $o->quantity_tiers ?? [],
            'delivery_time_minutes' => $int('delivery_time_minutes', $o->delivery_time_minutes ?? 60) ?? 60,
            'manual_stock_qty' => $int('manual_stock_qty', $o?->manual_stock_qty),
            'insurance_days' => $o->insurance_days ?? 0,
            'acquisition_cost_amount' => $o?->acquisition_cost_amount,
            'listing_days' => $o->listing_days ?? OfferRules::LISTING_DAYS[2],
            'publish_mode' => $o !== null ? $o->publish_mode->value : 'immediate',
        ];
    }

    private static function cents(string $value): int
    {
        if (preg_match('/^(\d{1,7})(?:[.,](\d{1,2}))?$/', $value, $m) !== 1) {
            throw OfferException::because('offer.csv.not_number', ['column' => 'unit_price']);
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }
}
