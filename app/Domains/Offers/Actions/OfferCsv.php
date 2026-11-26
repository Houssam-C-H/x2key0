<?php

declare(strict_types=1);

namespace App\Domains\Offers\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Offers\Exceptions\OfferException;
use App\Domains\Offers\Models\Offer;
use App\Domains\Offers\Support\OfferInputBuilder;
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
                    $this->save->update($offer, $actor, OfferInputBuilder::from($cells, $offer));
                    $result['updated']++;
                } else {
                    $this->save->create($profile, $actor, OfferInputBuilder::from($cells, null));
                    $result['created']++;
                }
            } catch (OfferException|ValidationException|\DomainException $e) {
                $result['errors'][] = ['line' => $line, 'message' => mb_substr($e->getMessage(), 0, 300)];
            }
        }

        return $result;
    }
}
