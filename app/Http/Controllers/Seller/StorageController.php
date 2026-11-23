<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Domains\Delivery\Models\AutoDeliveryLog;
use App\Domains\Identity\Models\User;
use App\Domains\Offers\Enums\Fulfillment;
use App\Domains\Offers\Models\Offer;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Storage\Actions\ImportStorageItems;
use App\Domains\Storage\Actions\ManageStorageItems;
use App\Domains\Storage\Actions\RevealStorageItem;
use App\Domains\Storage\Actions\SaveStorageGroup;
use App\Domains\Storage\Enums\StorageItemStatus;
use App\Domains\Storage\Enums\StorageItemType;
use App\Domains\Storage\Exceptions\StorageException;
use App\Domains\Storage\Models\StorageGroup;
use App\Domains\Storage\Models\StorageImportBatch;
use App\Domains\Storage\Models\StorageItem;
use App\Domains\Storage\Support\StoragePayload;
use App\Http\Controllers\Seller\Concerns\ResolvesSeller;
use App\Shared\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Storage (spec 1.7 / 5.5 / 8.1): Storage Category (groups + import with duplicate report), Storage List,
 * Recycle Station. Every route sits behind the Privacy Password gate. Secrets never travel in Inertia props
 * or the session: a reveal is a JSON response marked no-store, and imports are never flashed back.
 */
final class StorageController
{
    use ResolvesSeller;

    private const int ITEMS_PER_PAGE = 50;

    public function groups(Request $request, Settings $settings): Response
    {
        $profile = $this->seller($request);

        $groups = StorageGroup::query()->where('seller_profile_id', $profile->id)->orderBy('name')->get();
        $statusCounts = StorageItem::query()
            ->where('seller_profile_id', $profile->id)
            ->selectRaw('storage_group_id, status, COUNT(*) as n')
            ->groupBy('storage_group_id', 'status')
            ->get();
        $flagged = StorageItem::query()->where('seller_profile_id', $profile->id)->whereNotNull('flagged_at')
            ->selectRaw('storage_group_id, COUNT(*) as n')->groupBy('storage_group_id')->pluck('n', 'storage_group_id');
        $offers = Offer::query()->where('seller_profile_id', $profile->id)->where('fulfillment', Fulfillment::Auto)
            ->selectRaw('storage_group_id, COUNT(*) as n')->groupBy('storage_group_id')->pluck('n', 'storage_group_id');

        $counts = [];
        foreach ($statusCounts as $row) {
            $status = $row->getAttribute('status');
            if ($status instanceof StorageItemStatus) {
                $counts[$row->storage_group_id][$status->value] = self::int($row->getAttribute('n'));
            }
        }

        $batches = StorageImportBatch::query()
            ->whereIn('storage_group_id', $groups->pluck('id'))
            ->latest('id')
            ->limit(10)
            ->get();

        return Inertia::render('Storage/Groups', [
            'groups' => $groups->map(fn (StorageGroup $g): array => [
                'id' => $g->id,
                'name' => $g->name,
                'type' => $g->item_type->value,
                'threshold' => $g->low_stock_threshold,
                'available' => $g->available_count_cached,
                'low' => $g->isLow(),
                'counts' => $counts[$g->id] ?? [],
                'flagged' => self::int($flagged[$g->id] ?? 0),
                'offers' => self::int($offers[$g->id] ?? 0),
            ])->all(),
            'batches' => $batches->map(fn (StorageImportBatch $b): array => [
                'id' => $b->id,
                'group' => $groups->firstWhere('id', $b->storage_group_id)?->name,
                'source' => $b->source,
                'total' => $b->count_total,
                'accepted' => $b->count_accepted,
                'duplicates' => $b->count_duplicates,
                'invalid' => $b->count_invalid,
                'flagged' => $b->count_flagged,
                'createdAt' => $b->created_at->toIso8601String(),
            ])->all(),
            'types' => StorageItemType::values(),
            'defaultThreshold' => $settings->int('low_stock_default_threshold'),
            'maxItems' => $settings->int('storage_import_max_items'),
            'accountFields' => StoragePayload::ACCOUNT_FIELDS,
            'readOnly' => ! $profile->status->isPublic(),
        ]);
    }

    public function storeGroup(Request $request, SaveStorageGroup $action): RedirectResponse
    {
        $data = self::validateGroup($request);

        try {
            $action->create($this->seller($request), $data['name'], $data['type'], $data['threshold']);
        } catch (StorageException $e) {
            return back()->withErrors(['name' => $e->getMessage()]);
        }

        return back()->with('success', __('storage.group.created'));
    }

    public function updateGroup(Request $request, SaveStorageGroup $action, int $group): RedirectResponse
    {
        $model = $this->ownGroup($this->seller($request), $group);
        $data = self::validateGroup($request);

        try {
            $action->update($model, $data['name'], $data['type'], $data['threshold']);
        } catch (StorageException $e) {
            return back()->withErrors(['name' => $e->getMessage()]);
        }

        return back()->with('success', __('storage.group.saved'));
    }

    public function import(Request $request, ImportStorageItems $action, int $group): RedirectResponse
    {
        $model = $this->ownGroup($this->seller($request), $group);

        $request->validate([
            'mode' => ['required', Rule::in(['paste', 'file', 'form'])],
            'items' => ['nullable', 'required_if:mode,paste', 'string', 'max:2000000'],
            'file' => ['nullable', 'required_if:mode,file', 'file', 'max:2048', 'mimes:txt,csv', 'mimetypes:text/plain,text/csv,application/csv'],
            'account' => ['nullable', 'required_if:mode,form', 'array'],
            'account.*' => ['nullable', 'string', 'max:'.StoragePayload::MAX_LINE],
        ]);

        $mode = $request->string('mode')->toString();

        if ($mode === 'form') {
            if ($model->item_type !== StorageItemType::Account) {
                return back()->withErrors(['account' => __('storage.error.form_accounts_only')]);
            }

            $account = (array) $request->input('account');
            /** @var array<string, string|null> $fields */
            $fields = array_map(static fn (mixed $v): ?string => is_string($v) ? $v : null, array_intersect_key($account, array_flip(StoragePayload::ACCOUNT_FIELDS)));
            $one = StoragePayload::account($fields);
            $parsed = ['items' => $one === null ? [] : [$one], 'invalid' => $one === null ? 1 : 0];
        } else {
            $file = $request->file('file');
            $text = $mode === 'file' && $file instanceof UploadedFile ? (string) file_get_contents($file->getRealPath()) : $request->string('items')->toString();

            if (! mb_check_encoding($text, 'UTF-8')) {
                return back()->withErrors(['file' => __('storage.error.encoding')]);
            }

            $parsed = StoragePayload::parse($model->item_type, $text);
            unset($text);
        }

        try {
            $batch = $action->handle($model, self::user($request), $mode, $parsed);
        } catch (StorageException $e) {
            return back()->withErrors([$mode === 'file' ? 'file' : ($mode === 'form' ? 'account' : 'items') => $e->getMessage()]);
        }

        return back()->with('success', __('storage.import.result', [
            'accepted' => $batch->count_accepted,
            'duplicates' => $batch->count_duplicates,
            'invalid' => $batch->count_invalid,
            'flagged' => $batch->count_flagged,
        ]));
    }

    public function items(Request $request): Response
    {
        return $this->itemList($request, false);
    }

    public function recycle(Request $request): Response
    {
        return $this->itemList($request, true);
    }

    /** Decrypts one item for its seller. JSON only, never cached, never stored in the session. */
    public function reveal(Request $request, RevealStorageItem $action, int $item): JsonResponse
    {
        $profile = $this->seller($request);
        $model = StorageItem::query()->where('seller_profile_id', $profile->id)->find($item);
        abort_if($model === null, 404);

        try {
            $secret = $action->handle($model, self::user($request));
        } catch (StorageException $e) {
            return response()->json(['message' => $e->getMessage()], 422)->header('Cache-Control', 'no-store');
        }

        return response()->json($secret)->header('Cache-Control', 'no-store, max-age=0')->header('Pragma', 'no-cache');
    }

    public function manage(Request $request, ManageStorageItems $action): RedirectResponse
    {
        $request->validate([
            'operation' => ['required', Rule::in(ManageStorageItems::OPERATIONS)],
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
        ]);

        $ids = [];
        $raw = $request->input('ids');
        foreach (is_array($raw) ? $raw : [] as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        try {
            $count = $action->handle($this->seller($request), self::user($request), $request->string('operation')->toString(), $ids);
        } catch (StorageException $e) {
            return back()->withErrors(['items' => $e->getMessage()]);
        }

        return back()->with('success', __('storage.items.done', ['count' => $count]));
    }

    /** Storage › Delivery Logs (spec 1.7 / 5.5): what auto delivery sent, and failures. No secrets. */
    public function logs(Request $request): Response
    {
        $profile = $this->seller($request);

        $page = AutoDeliveryLog::query()
            ->where('auto_delivery_logs.seller_profile_id', $profile->id)
            ->join('order_items', 'order_items.id', '=', 'auto_delivery_logs.order_item_id')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('storage_items', 'storage_items.id', '=', 'auto_delivery_logs.storage_item_id')
            ->leftJoin('storage_groups', 'storage_groups.id', '=', 'storage_items.storage_group_id')
            ->select(['auto_delivery_logs.*', 'orders.number as order_number', 'orders.public_id as order_public_id', 'storage_groups.name as group_name'])
            ->orderByDesc('auto_delivery_logs.id')
            ->paginate(self::ITEMS_PER_PAGE);

        return Inertia::render('Storage/Logs', [
            'logs' => [
                'items' => array_map(fn (AutoDeliveryLog $l): array => [
                    'id' => $l->id,
                    'order' => is_string($n = $l->getAttribute('order_number')) ? $n : '',
                    'orderHref' => is_string($p = $l->getAttribute('order_public_id')) ? "/seller/orders/{$p}" : null,
                    'group' => is_string($g = $l->getAttribute('group_name')) ? $g : null,
                    'item' => $l->storage_item_id,
                    'result' => $l->result,
                    'error' => $l->error,
                    'at' => $l->delivered_at->toIso8601String(),
                ], $page->items()),
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
            ],
        ]);
    }

    private function itemList(Request $request, bool $recycled): Response
    {
        $profile = $this->seller($request);
        $groups = StorageGroup::query()->where('seller_profile_id', $profile->id)->orderBy('name')->get(['id', 'name', 'item_type']);
        $groupId = $request->integer('group') ?: null;
        $status = is_string($s = $request->query('status')) ? StorageItemStatus::tryFrom($s) : null;

        $query = StorageItem::query()
            ->where('seller_profile_id', $profile->id)
            ->when($groupId !== null, fn ($q) => $q->where('storage_group_id', $groupId))
            ->when($recycled, fn ($q) => $q->where('status', StorageItemStatus::Recycled))
            ->when(! $recycled && $status !== null, fn ($q) => $q->where('status', $status))
            ->when(! $recycled && $status === null, fn ($q) => $q->where('status', '!=', StorageItemStatus::Recycled))
            ->orderByDesc('id');

        $page = $query->paginate(self::ITEMS_PER_PAGE, ['id', 'storage_group_id', 'status', 'flagged_at', 'recycled_at', 'created_at', 'import_batch_id'])->withQueryString();
        $names = $groups->pluck('name', 'id');

        return Inertia::render($recycled ? 'Storage/Recycle' : 'Storage/Items', [
            'items' => [
                'items' => array_map(fn (StorageItem $i): array => [
                    'id' => $i->id,
                    'group' => $names[$i->storage_group_id] ?? '',
                    'status' => $i->status->value,
                    'flagged' => $i->flagged_at !== null,
                    'revealable' => $i->status->revealable(),
                    'batch' => $i->import_batch_id,
                    'createdAt' => $i->created_at->toIso8601String(),
                    'recycledAt' => $i->recycled_at?->toIso8601String(),
                ], $page->items()),
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
            ],
            'groups' => $groups->map(fn (StorageGroup $g): array => ['id' => $g->id, 'name' => $g->name, 'type' => $g->item_type->value])->all(),
            'filters' => ['group' => $groupId, 'status' => $status?->value],
            'statuses' => array_values(array_filter(StorageItemStatus::values(), static fn (string $s): bool => $s !== StorageItemStatus::Recycled->value)),
            'readOnly' => ! $profile->status->isPublic(),
        ]);
    }

    /**
     * @return array{name: string, type: StorageItemType, threshold: int}
     */
    private static function validateGroup(Request $request): array
    {
        $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:60'],
            'type' => ['required', Rule::in(StorageItemType::values())],
            'threshold' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);

        return [
            'name' => $request->string('name')->toString(),
            'type' => StorageItemType::from($request->string('type')->toString()),
            'threshold' => $request->integer('threshold'),
        ];
    }

    private function ownGroup(SellerProfile $profile, int $id): StorageGroup
    {
        $group = StorageGroup::query()->where('seller_profile_id', $profile->id)->find($id);
        abort_if($group === null, 404);

        return $group;
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
