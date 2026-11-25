<?php

declare(strict_types=1);

namespace App\Domains\Offers\Actions;

use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ServiceType;
use App\Domains\Identity\Models\User;
use App\Domains\Offers\Enums\DeliveryMethod;
use App\Domains\Offers\Enums\Fulfillment;
use App\Domains\Offers\Enums\InsuranceMode;
use App\Domains\Offers\Enums\OfferStatus;
use App\Domains\Offers\Enums\PublishMode;
use App\Domains\Offers\Exceptions\OfferException;
use App\Domains\Offers\Models\Offer;
use App\Domains\Offers\Models\OfferPriceHistory;
use App\Domains\Offers\StateMachines\OfferStateMachine;
use App\Domains\Offers\Support\OfferRules;
use App\Domains\Sellers\Enums\SellerStatus;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Storage\Actions\RefreshStorageStock;
use App\Domains\Storage\Models\StorageGroup;
use App\Shared\Support\Settings;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Create New Offer / Edit Offer (spec 1.8, 5.4, 6.2). Validates everything that depends on the catalog,
 * the seller and their storage (shape validation is the controller's job), flags duplicates, records the
 * price history, and hands status changes to OfferStateMachine.
 *
 * @phpstan-type OfferInput array{
 *     game_category_id: int, product_id: int|null, service_type_id: int|null, title: string|null, description: array<string, string|null>,
 *     delivery_method: string, fulfillment: string, storage_group_id: int|null, attribute_values: array<string, mixed>,
 *     unit_price_amount: int, min_qty: int, max_qty: int|null, unit_label: string|null, integer_multiple: bool,
 *     quantity_tiers: list<array{min_qty: int, unit_price_amount: int}>, delivery_time_minutes: int,
 *     manual_stock_qty: int|null, insurance_days: int, acquisition_cost_amount: int|null, listing_days: int,
 *     publish_mode: string
 * }
 */
final class SaveOffer
{
    public function __construct(
        private readonly OfferStateMachine $machine,
        private readonly RefreshProductOffers $refreshProduct,
        private readonly RefreshStorageStock $refreshStock,
        private readonly Settings $settings,
    ) {}

    /**
     * @param  OfferInput  $input
     */
    public function create(SellerProfile $profile, User $actor, array $input): Offer
    {
        self::assertCanWrite($profile);

        $gameCategory = GameCategory::query()->visible()->with(['game', 'type'])->find($input['game_category_id']);
        if ($gameCategory === null) {
            throw OfferException::because('offer.error.unknown_category', field: 'game_category_id');
        }
        if (! $profile->canSellIn($gameCategory->id)) {
            throw OfferException::because('offer.error.service_not_passed', field: 'game_category_id');
        }

        $product = $this->product($gameCategory, $input['product_id']);
        $method = $this->method($gameCategory, $input['delivery_method']);
        $values = $this->values($profile, $gameCategory, $product, $method, $input);

        $offer = DB::transaction(function () use ($profile, $actor, $gameCategory, $product, $method, $values): Offer {
            $offer = new Offer([
                ...self::fillable($values),
                'seller_profile_id' => $profile->id,
                'game_category_id' => $gameCategory->id,
                'product_id' => $product?->id,
                'delivery_method' => $method,
            ]);
            $offer->duplicate_of_offer_id = $this->duplicateOf($offer);
            $offer->forceFill(['status' => OfferStatus::Draft, 'unit_price_amount' => $values['unit_price_amount'], 'version' => 1])->save();

            self::recordPrice($offer, $actor);

            return $this->machine->place($offer, $actor);
        });

        $this->afterSave($offer, null);

        return $offer;
    }

    /**
     * Game×category, product and delivery method are fixed (spec 6.2: changing them means a new offer).
     *
     * @param  OfferInput  $input
     */
    public function update(Offer $offer, User $actor, array $input): Offer
    {
        $offer->loadMissing(['seller.level', 'gameCategory.game', 'gameCategory.type', 'product']);
        self::assertCanWrite($offer->seller);

        if ($offer->status === OfferStatus::SystemDeactivated) {
            throw OfferException::because('offer.error.system_deactivated');
        }

        if ($input['game_category_id'] !== $offer->game_category_id || $input['product_id'] !== $offer->product_id || $input['delivery_method'] !== $offer->delivery_method->value) {
            throw OfferException::because('offer.error.immutable');
        }

        $values = $this->values($offer->seller, $offer->gameCategory, $offer->product, $offer->delivery_method, $input);
        $previousGroup = $offer->storage_group_id;

        $offer = DB::transaction(function () use ($offer, $actor, $values): Offer {
            /** @var Offer $locked */
            $locked = Offer::query()->lockForUpdate()->findOrFail($offer->id);

            $before = ['title' => $locked->title, 'description' => $locked->description, 'attribute_values' => $locked->attribute_values];
            $priceChanged = $locked->unit_price_amount !== $values['unit_price_amount'];

            $locked->fill(self::fillable($values));
            $locked->duplicate_of_offer_id = $this->duplicateOf($locked);

            if ($priceChanged) {
                $locked->unit_price_amount = $values['unit_price_amount'];
                $locked->version++;
            }

            $locked->save();

            if ($priceChanged) {
                self::recordPrice($locked, $actor);
            }

            $contentChanged = $before !== ['title' => $locked->title, 'description' => $locked->description, 'attribute_values' => $locked->attribute_values];
            $locked->loadMissing('seller.level');
            $needsReview = $locked->seller->level?->auto_approve !== true || $locked->duplicate_of_offer_id !== null;

            if ($locked->status === OfferStatus::Rejected) {
                return $this->machine->publish($locked, $actor); // edited after rejection → resubmitted
            }

            if ($contentChanged && $needsReview && $locked->published_at !== null) {
                return $this->machine->requireReview($locked, $actor);
            }

            return $this->machine->syncStock($locked->refresh());
        });

        $this->afterSave($offer, $previousGroup);

        return $offer;
    }

    /**
     * Duplicate key (spec 5.4): same seller, product (or game×category), delivery method and attributes.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function attributesHash(?int $productId, int $gameCategoryId, DeliveryMethod $method, array $attributes): string
    {
        ksort($attributes);

        return hash('sha256', json_encode([
            $productId === null ? "gc:{$gameCategoryId}" : "p:{$productId}",
            $method->value,
            $attributes,
        ], JSON_THROW_ON_ERROR));
    }

    private function afterSave(Offer $offer, ?int $previousGroup): void
    {
        $this->refreshProduct->handle($offer->product_id);

        foreach (array_unique(array_filter([$offer->storage_group_id, $previousGroup])) as $groupId) {
            $group = StorageGroup::query()->find($groupId);
            if ($group !== null) {
                $this->refreshStock->handle($group);
            }
        }
    }

    private static function assertCanWrite(SellerProfile $profile): void
    {
        if ($profile->status === SellerStatus::Suspended) {
            throw OfferException::because('seller.error.suspended');
        }

        if ($profile->status !== SellerStatus::Active && $profile->status !== SellerStatus::Vacation) {
            throw OfferException::because('seller.error.not_active');
        }
    }

    private function product(GameCategory $gameCategory, ?int $productId): ?Product
    {
        if ($productId === null) {
            return null;
        }

        $product = Product::query()->active()->where('game_category_id', $gameCategory->id)->find($productId);
        if ($product === null) {
            throw OfferException::because('offer.error.unknown_product', field: 'product_id');
        }
        $product->setRelation('gameCategory', $gameCategory);

        return $product;
    }

    /**
     * Service type (ADR-032): required when the category type has enabled service types, and must be one of them;
     * otherwise none. A service switched off by staff can no longer be chosen (also on edit).
     */
    private function service(GameCategory $gameCategory, ?int $serviceTypeId): ?ServiceType
    {
        $available = ServiceType::availableFor($gameCategory->category_type_id);

        if ($available->isEmpty()) {
            if ($serviceTypeId !== null) {
                throw OfferException::because('offer.error.unknown_service', field: 'service_type_id');
            }

            return null;
        }

        if ($serviceTypeId === null) {
            throw OfferException::because('offer.error.service_required', field: 'service_type_id');
        }

        $service = $available->firstWhere('id', $serviceTypeId);
        if ($service === null) {
            throw OfferException::because('offer.error.unknown_service', field: 'service_type_id');
        }

        return $service;
    }

    private function method(GameCategory $gameCategory, string $value): DeliveryMethod
    {
        $method = DeliveryMethod::tryFrom($value);

        if ($method === null || ! in_array($method, OfferRules::allowedMethods($gameCategory), true)) {
            throw OfferException::because('offer.error.method_not_allowed', field: 'delivery_method');
        }

        return $method;
    }

    /**
     * Mass-assignable columns for create/update, plus the unit price.
     *
     * @param  OfferInput  $input
     * @return array<string, mixed>&array{unit_price_amount: int}
     */
    private function values(SellerProfile $profile, GameCategory $gameCategory, ?Product $product, DeliveryMethod $method, array $input): array
    {
        $service = $this->service($gameCategory, $input['service_type_id']);

        // Attributes: a catalog product carries its own; unit offers are checked against the schema.
        try {
            $attributes = $product !== null
                ? ($product->attribute_values ?? [])
                : $gameCategory->attributeSchema()->normalise($input['attribute_values']);
        } catch (InvalidArgumentException $e) {
            throw OfferException::because('offer.error.attributes', ['reason' => $e->getMessage()], 'attribute_values');
        }

        $fulfillment = Fulfillment::tryFrom($input['fulfillment']) ?? Fulfillment::Manual;
        $groupId = null;
        $manualStock = $input['manual_stock_qty'];

        if ($fulfillment === Fulfillment::Auto) {
            $group = $input['storage_group_id'] === null ? null : StorageGroup::query()->where('seller_profile_id', $profile->id)->find($input['storage_group_id']);

            if (! $method->supportsAutoDelivery()) {
                throw OfferException::because('offer.error.auto_not_supported', field: 'fulfillment');
            }
            if ($group === null) {
                throw OfferException::because('offer.error.storage_group', field: 'storage_group_id');
            }
            if (! in_array($group->item_type, $method->storageTypes(), true)) {
                throw OfferException::because('offer.error.storage_type', field: 'storage_group_id');
            }

            $groupId = $group->id;
            $manualStock = null;
        } elseif ($manualStock === null) {
            throw OfferException::because('offer.error.stock_required', field: 'manual_stock_qty');
        }

        $price = $input['unit_price_amount'];
        $maxQty = $input['max_qty'];
        if ($maxQty !== null && $maxQty < $input['min_qty']) {
            throw OfferException::because('offer.error.max_below_min', field: 'max_qty');
        }

        if ($input['delivery_time_minutes'] > $this->settings->int('max_delivery_time_minutes') || ! in_array($input['delivery_time_minutes'], OfferRules::DELIVERY_TIMES, true)) {
            throw OfferException::because('offer.error.delivery_time', field: 'delivery_time_minutes');
        }

        $tiers = OfferRules::tiers($input['quantity_tiers'], $price, $input['min_qty']);

        // Insurance (spec 1.8 #10): compulsory per game×category policy.
        $compulsory = OfferRules::compulsoryInsuranceDays($gameCategory);
        if ($compulsory > 0 && $input['insurance_days'] < $compulsory) {
            throw OfferException::because('offer.error.insurance_compulsory', ['days' => $compulsory], 'insurance_days');
        }
        $insuranceMode = match (true) {
            $compulsory > 0 => InsuranceMode::Compulsory,
            $input['insurance_days'] > 0 => InsuranceMode::Optional,
            default => InsuranceMode::None,
        };

        $title = trim((string) $input['title']);
        if ($title === '' && $product !== null) {
            $title = $product->translate('name', 'en');
        }
        if ($title === '') {
            throw OfferException::because('offer.error.title_required', field: 'title');
        }

        $description = array_filter(
            array_map(static fn (?string $v): ?string => $v === null || trim($v) === '' ? null : trim($v), $input['description']),
            static fn (?string $v): bool => $v !== null,
        );

        return [
            'title' => mb_substr($title, 0, 160),
            'description' => $description === [] ? null : $description,
            'fulfillment' => $fulfillment,
            'storage_group_id' => $groupId,
            'manual_stock_qty' => $manualStock,
            'attribute_values' => $attributes === [] ? null : $attributes,
            'attributes_hash' => self::attributesHash($product?->id, $gameCategory->id, $method, $service === null ? $attributes : [...$attributes, '_service' => $service->id]),
            'service_type_id' => $service?->id,
            'unit_price_amount' => $price,
            'currency' => 'USD',
            'min_qty' => $input['min_qty'],
            'max_qty' => $maxQty,
            'unit_label' => $input['unit_label'] === null || trim($input['unit_label']) === '' ? null : mb_substr(trim($input['unit_label']), 0, 40),
            'integer_multiple' => $input['integer_multiple'],
            'quantity_tiers' => $tiers === [] ? null : $tiers,
            'delivery_time_minutes' => $input['delivery_time_minutes'],
            'insurance_days' => $input['insurance_days'],
            'insurance_mode' => $insuranceMode,
            'account_recovery_protection_days' => $method === DeliveryMethod::AccountTransfer ? $this->settings->int('account_recovery_protection_days') : null,
            'acquisition_cost_amount' => $input['acquisition_cost_amount'],
            'listing_days' => $input['listing_days'],
            'publish_mode' => PublishMode::tryFrom($input['publish_mode']) ?? PublishMode::Immediate,
        ];
    }

    private function duplicateOf(Offer $offer): ?int
    {
        $id = Offer::query()
            ->where('seller_profile_id', $offer->seller_profile_id)
            ->where('attributes_hash', $offer->attributes_hash)
            ->when($offer->exists, fn ($q) => $q->whereKeyNot($offer->id))
            ->orderBy('id')
            ->value('id');

        return is_int($id) ? $id : null;
    }

    /**
     * The price is set explicitly (version++, history), never mass assigned.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function fillable(array $values): array
    {
        unset($values['unit_price_amount']);

        return $values;
    }

    private static function recordPrice(Offer $offer, User $actor): void
    {
        OfferPriceHistory::query()->create([
            'offer_id' => $offer->id,
            'unit_price_amount' => $offer->unit_price_amount,
            'currency' => $offer->currency,
            'changed_by' => $actor->id,
        ]);
    }
}
