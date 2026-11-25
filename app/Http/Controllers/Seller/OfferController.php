<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ServiceType;
use App\Domains\Catalog\Schema\AttributeSchema;
use App\Domains\Identity\Models\User;
use App\Domains\Offers\Actions\BulkUpdateOffers;
use App\Domains\Offers\Actions\SaveOffer;
use App\Domains\Offers\Enums\DeliveryMethod;
use App\Domains\Offers\Enums\Fulfillment;
use App\Domains\Offers\Enums\OfferStatus;
use App\Domains\Offers\Exceptions\OfferException;
use App\Domains\Offers\Models\Offer;
use App\Domains\Offers\Support\OfferPresenter;
use App\Domains\Offers\Support\OfferRules;
use App\Domains\Sellers\Enums\ServiceApplicationStatus;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Sellers\Support\SellerPresenter;
use App\Domains\Storage\Models\LowStockAlert;
use App\Domains\Storage\Models\StorageGroup;
use App\Http\Controllers\Seller\Concerns\ReadsOfferForm;
use App\Http\Controllers\Seller\Concerns\ResolvesSeller;
use App\Shared\Support\Settings;
use App\Shared\Support\Translations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Seller Center › Create New Offers and Active Offers (spec 1.8, Part 20 /seller/offers).
 */
final class OfferController
{
    use ReadsOfferForm;
    use ResolvesSeller;

    public const array TABS = ['all', 'active', 'expire_soon', 'low_stock', 'deactivated', 'pending_publish', 'in_review', 'system_deactivated', 'auto'];

    public const array SORTS = ['default', 'latest', 'lowest', 'highest'];

    public const array PER_PAGE = [20, 50, 100];

    public function index(Request $request, Settings $settings): Response
    {
        $profile = $this->seller($request);
        $locale = app()->getLocale();

        $tab = in_array($t = $request->query('tab'), self::TABS, true) ? $t : 'all';
        $sort = in_array($s = $request->query('sort'), self::SORTS, true) ? $s : 'default';
        $perPage = in_array($p = $request->integer('per_page', 20), self::PER_PAGE, true) ? $p : 20;
        $service = $request->integer('service') ?: null;
        $q = is_string($v = $request->query('q')) ? mb_substr(trim($v), 0, 100) : '';
        $expireSoon = now()->addHours($settings->int('offer_expire_soon_hours'));

        $base = fn (): Builder => Offer::query()
            ->where('offers.seller_profile_id', $profile->id)
            ->when($service !== null, fn (Builder $b) => $b->where('offers.game_category_id', $service))
            ->when($q !== '', fn (Builder $b) => $b->where(fn (Builder $w) => $w->where('offers.title', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%')->orWhere('offers.public_id', $q)));

        $counts = [];
        foreach (self::TABS as $name) {
            $counts[$name] = self::applyTab($base(), $name, $expireSoon)->count();
        }

        $query = self::applyTab($base(), $tab, $expireSoon)->with(['gameCategory.game', 'gameCategory.type', 'storageGroup', 'serviceType']);
        $query = match ($sort) {
            'latest' => $query->orderByDesc('offers.id'),
            'lowest' => $query->orderBy('offers.unit_price_amount')->orderByDesc('offers.id'),
            'highest' => $query->orderByDesc('offers.unit_price_amount')->orderByDesc('offers.id'),
            default => $query->orderByDesc('offers.sort_weight')->orderByDesc('offers.id'),
        };
        $page = $query->paginate($perPage)->withQueryString();

        // Landing summary: game×category chips with offer counts (spec 1.8 /sell/manage).
        $services = Offer::query()
            ->where('seller_profile_id', $profile->id)
            ->selectRaw('game_category_id, COUNT(*) as offers')
            ->groupBy('game_category_id')
            ->pluck('offers', 'game_category_id');
        $serviceModels = GameCategory::query()->whereIn('id', $services->keys())->with(['game', 'type'])->get();

        return Inertia::render('Seller/Offers/Index', [
            'offers' => [
                'items' => array_map(static fn (Offer $o): array => OfferPresenter::sellerRow($o, $locale), $page->items()),
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
            ],
            'counts' => $counts,
            'services' => $serviceModels->map(fn (GameCategory $gc): array => [
                'id' => $gc->id,
                'title' => $gc->title($locale),
                'count' => self::int($services[$gc->id] ?? 0),
            ])->sortBy('title')->values()->all(),
            'filters' => ['tab' => $tab, 'sort' => $sort, 'perPage' => $perPage, 'service' => $service, 'q' => $q],
            'tabs' => self::TABS,
            'sorts' => self::SORTS,
            'perPageOptions' => self::PER_PAGE,
            'listingDays' => OfferRules::LISTING_DAYS,
            'readOnly' => ! $profile->status->isPublic(),
        ]);
    }

    public function create(Request $request): Response
    {
        $profile = $this->seller($request);

        return $this->form($profile, null, $request->integer('category') ?: null);
    }

    public function edit(Request $request, int $offer): Response
    {
        $profile = $this->seller($request);
        $model = $this->own($profile, $offer);

        return $this->form($profile, $model, $model->game_category_id);
    }

    public function store(Request $request, SaveOffer $action): RedirectResponse
    {
        $profile = $this->seller($request);
        $request->validate(self::offerRules(true));

        try {
            $offer = $action->create($profile, self::user($request), self::offerInput($request));
        } catch (OfferException $e) {
            return back()->withErrors([$e->field => $e->getMessage()])->withInput();
        }

        return redirect('/seller/offers')->with('success', __(self::savedMessage($offer)));
    }

    public function update(Request $request, SaveOffer $action, int $offer): RedirectResponse
    {
        $profile = $this->seller($request);
        $model = $this->own($profile, $offer);
        $request->validate(self::offerRules(false));

        try {
            $model = $action->update($model, self::user($request), self::offerInput($request));
        } catch (OfferException $e) {
            return back()->withErrors([$e->field => $e->getMessage()])->withInput();
        }

        return redirect('/seller/offers')->with('success', __(self::savedMessage($model)));
    }

    public function bulk(Request $request, BulkUpdateOffers $action): RedirectResponse
    {
        $request->validate([
            'operation' => ['required', Rule::in(BulkUpdateOffers::OPERATIONS)],
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
            'days' => ['nullable', 'integer', Rule::in(OfferRules::LISTING_DAYS)],
        ]);

        $ids = self::ids($request);

        try {
            $result = $action->apply($this->seller($request), self::user($request), $request->string('operation')->toString(), $ids, $request->integer('days'));
        } catch (OfferException $e) {
            return back()->withErrors(['offers' => $e->getMessage()]);
        }

        $message = __('offer.bulk.result', ['done' => $result['done'], 'failed' => $result['failed']]);

        return $result['failed'] > 0 && $result['done'] === 0
            ? back()->with('error', $message)
            : back()->with('success', $message);
    }

    /** Inline edits in the Active Offers table: unit price, sort number, stock. */
    public function inline(Request $request, BulkUpdateOffers $action, int $offer): RedirectResponse
    {
        $request->validate([
            'field' => ['required', Rule::in(['price', 'sort', 'stock'])],
            'value' => ['required', 'string', 'max:12'],
        ]);

        $profile = $this->seller($request);
        $model = $this->own($profile, $offer);
        $value = $request->string('value')->toString();

        try {
            match ($request->string('field')->toString()) {
                'price' => preg_match(self::MONEY_PATTERN, $value) === 1
                    ? $action->price($profile, self::user($request), $model, self::cents($value))
                    : throw OfferException::because('offer.error.price'),
                'sort' => $action->sort($profile, $model, (int) $value),
                default => $action->stock($profile, $model, (int) $value),
            };
        } catch (OfferException $e) {
            return back()->withErrors(['offers' => $e->getMessage()]);
        }

        return back()->with('success', __('offer.inline.saved'));
    }

    private function form(SellerProfile $profile, ?Offer $offer, ?int $categoryId): Response
    {
        $locale = app()->getLocale();

        $services = GameCategory::query()
            ->visible()
            ->whereIn('id', $profile->serviceApplications()->where('status', ServiceApplicationStatus::Passed)->select('game_category_id'))
            ->with(['game', 'type'])
            ->get()
            ->sortBy(fn (GameCategory $gc): string => mb_strtolower($gc->title($locale)))
            ->values();

        $selected = $services->firstWhere('id', $categoryId);
        $profile->loadMissing('level');

        return Inertia::render('Seller/Offers/Form', [
            'offer' => $offer === null ? null : $this->formValues($offer),
            // Create offer steps: category type first, then the game inside it (OfferTargetPicker).
            'services' => $services->map(fn (GameCategory $gc): array => [
                ...SellerPresenter::service($gc, $locale),
                'typeCode' => $gc->type->code->value,
                'game' => $gc->game->translate('name', $locale),
            ])->all(),
            'category' => $selected === null ? null : [
                'id' => $selected->id,
                'title' => $selected->title($locale),
                'typeCode' => $selected->type->code->value,
                'notice' => $selected->noticeText($locale),
                'attributes' => self::attributeFields($selected->attributeSchema(), $locale),
                'methods' => array_map(static fn (DeliveryMethod $m): array => ['value' => $m->value, 'auto' => $m->supportsAutoDelivery(), 'storageTypes' => array_map(static fn ($t): string => $t->value, $m->storageTypes())], OfferRules::allowedMethods($selected)),
                'compulsoryInsuranceDays' => OfferRules::compulsoryInsuranceDays($selected),
                'services' => ServiceType::availableFor($selected->category_type_id)
                    ->map(fn (ServiceType $s): array => ['id' => $s->id, 'name' => $s->translate('name', $locale), 'description' => $s->description === null ? null : $s->translate('description', $locale)])->all(),
                'products' => Product::query()->active()->where('game_category_id', $selected->id)->orderBy('slug')->limit(500)->get()
                    ->map(fn (Product $p): array => ['id' => $p->id, 'name' => $p->translate('name', $locale)])->all(),
            ],
            'storageGroups' => StorageGroup::query()->where('seller_profile_id', $profile->id)->orderBy('name')->get()
                ->map(fn (StorageGroup $g): array => ['id' => $g->id, 'name' => $g->name, 'type' => $g->item_type->value, 'available' => $g->available_count_cached])->all(),
            'options' => [
                'deliveryTimes' => OfferRules::DELIVERY_TIMES,
                'listingDays' => OfferRules::LISTING_DAYS,
                'insuranceDays' => OfferRules::INSURANCE_DAYS,
                'maxTiers' => OfferRules::MAX_TIERS,
            ],
            'commissionBps' => $profile->level->commission_bps ?? 0,
            'autoApprove' => $profile->level?->auto_approve === true,
            'readOnly' => ! $profile->status->isPublic(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formValues(Offer $offer): array
    {
        return [
            'id' => $offer->id,
            'publicId' => $offer->public_id,
            'status' => $offer->status->value,
            'statusReason' => $offer->status_reason,
            'game_category_id' => $offer->game_category_id,
            'product_id' => $offer->product_id,
            'service_type_id' => $offer->service_type_id,
            'title' => $offer->title,
            'description' => $offer->description ?? [],
            'delivery_method' => $offer->delivery_method->value,
            'fulfillment' => $offer->fulfillment->value,
            'storage_group_id' => $offer->storage_group_id,
            'attribute_values' => $offer->attribute_values ?? [],
            'unit_price' => self::decimal($offer->unit_price_amount),
            'min_qty' => $offer->min_qty,
            'max_qty' => $offer->max_qty,
            'unit_label' => $offer->unit_label,
            'integer_multiple' => $offer->integer_multiple,
            'quantity_tiers' => array_map(static fn (array $t): array => ['min_qty' => $t['min_qty'], 'unit_price' => self::decimal($t['unit_price_amount'])], $offer->quantity_tiers ?? []),
            'delivery_time_minutes' => $offer->delivery_time_minutes,
            'manual_stock_qty' => $offer->manual_stock_qty,
            'insurance_days' => $offer->insurance_days,
            'acquisition_cost' => self::decimal($offer->acquisition_cost_amount),
            'listing_days' => $offer->listing_days,
            'publish_mode' => $offer->publish_mode->value,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function attributeFields(AttributeSchema $schema, string $locale): array
    {
        return array_map(static fn (array $f): array => [
            'key' => $f['key'],
            'type' => $f['type']->value,
            'label' => Translations::pick($f['label'], $locale),
            'required' => $f['required'],
            'unit' => $f['unit'],
            'options' => array_map(static fn (array $o): array => ['value' => $o['value'], 'label' => Translations::pick($o['label'], $locale)], $f['options']),
        ], $schema->fields());
    }

    /**
     * @param  Builder<Offer>  $query
     * @return Builder<Offer>
     */
    private static function applyTab(Builder $query, string $tab, Carbon $expireSoon): Builder
    {
        $statuses = static fn (OfferStatus ...$s): array => array_map(static fn (OfferStatus $x): string => $x->value, $s);

        return match ($tab) {
            'active' => $query->where('offers.status', OfferStatus::Active),
            'expire_soon' => $query->whereIn('offers.status', $statuses(OfferStatus::Active, OfferStatus::OutOfStock))->where('offers.expires_at', '<=', $expireSoon),
            'low_stock' => $query->where(fn (Builder $w) => $w->where('offers.status', OfferStatus::OutOfStock)
                ->orWhereIn('offers.id', LowStockAlert::query()->open()->select('offer_id'))),
            'deactivated' => $query->whereIn('offers.status', $statuses(OfferStatus::Deactivated, OfferStatus::Expired)),
            'pending_publish' => $query->where('offers.status', OfferStatus::PendingPublish),
            'in_review' => $query->whereIn('offers.status', $statuses(OfferStatus::PendingReview, OfferStatus::Rejected)),
            'system_deactivated' => $query->where('offers.status', OfferStatus::SystemDeactivated),
            'auto' => $query->where('offers.fulfillment', Fulfillment::Auto),
            default => $query,
        };
    }

    private function own(SellerProfile $profile, int $id): Offer
    {
        $offer = Offer::query()->where('seller_profile_id', $profile->id)->with(['storageGroup', 'seller.level'])->find($id);
        abort_if($offer === null, 404);

        return $offer;
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * @return list<int>
     */
    private static function ids(Request $request): array
    {
        $ids = [];
        $raw = $request->input('ids');
        foreach (is_array($raw) ? $raw : [] as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function savedMessage(Offer $offer): string
    {
        return match ($offer->status) {
            OfferStatus::PendingReview => $offer->duplicate_of_offer_id !== null ? 'offer.saved.duplicate' : 'offer.saved.review',
            OfferStatus::PendingPublish => 'offer.saved.pending_publish',
            OfferStatus::OutOfStock => 'offer.saved.out_of_stock',
            default => 'offer.saved.live',
        };
    }
}
